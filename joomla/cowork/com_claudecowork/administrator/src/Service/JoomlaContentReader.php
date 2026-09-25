<?php
namespace Tracy\Component\ClaudeCowork\Administrator\Service;
\defined('_JEXEC') or die;

/** Joomla 6 pilot: mapped entities, public CMS audience, authenticated transport only. */
final class JoomlaContentReader
{
    private $db;
    private \QuickstartContract $contract;
    private $contractFactory;
    private string $root;
    private string $base;
    public function __construct($db, callable $contractFactory, string $root, string $base) {
        $this->db=$db; $this->contractFactory=$contractFactory; $this->root=$root; $this->base=rtrim($base,'/');
    }
    private function rows(string $table, string $order='id'): array {
        return $this->db->setQuery('SELECT * FROM #__'.$table.' ORDER BY '.$order)->loadAssocList();
    }
    private function hash($value): string { return hash('sha256',\ContentReader::encode($value)); }
    private function media(): array {
        $out=[]; $base=$this->root.'/images';
        if (!is_dir($base)) return [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base,\FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink() || !$file->isFile()) continue;
            $path=substr($file->getPathname(),strlen($this->root)+1);
            $out[$path]=hash_file('sha256',$file->getPathname());
        }
        ksort($out); return $out;
    }
    private function readableMedia(array $contents, array $scan): array {
        $paths=[];
        foreach ($contents as $content) {
            $sources=array_column($content['images'],'src');
            $html=[$content['bodyHtml']??''];
            foreach ($content['blocks'] as $block) foreach ($block['fields'] as $field)
                if (in_array($field['type'],['html','richtext'],true) && is_string($field['value'])) $html[]=$field['value'];
            foreach ($html as $markup) {
                if ($markup==='') continue;
                $previous=libxml_use_internal_errors(true);
                try {
                    $document=new \DOMDocument(); $document->loadHTML('<?xml encoding="utf-8" ?>'.$markup,LIBXML_NONET);
                    foreach ($document->getElementsByTagName('img') as $image) $sources[]=$image->getAttribute('src');
                } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
            }
            foreach ($sources as $source) {
                if (str_starts_with($source,$this->base.'/')) $source=substr($source,strlen($this->base)+1);
                elseif (preg_match('~^(https?:)?//~',$source)) continue;
                $path=ltrim(rawurldecode(parse_url($source,PHP_URL_PATH)??''),'/');
                if (str_starts_with($path,'images/') && !in_array('..',explode('/',$path),true)) $paths[$path]=$scan[$path]??null;
            }
        }
        ksort($paths); return $paths;
    }
    /** The reader's opt-in row; a missing table is unsupported, a DB failure is unavailable. */
    private function config(): array {
        try { $config=$this->db->setQuery('SELECT * FROM #__claudecowork_content_reader WHERE id=1')->loadAssoc(); }
        catch (\Throwable $e) {
            // Missing opt-in metadata is unsupported; a DB timeout/failure is unavailable.
            // Joomla's mysqli statement preserves MariaDB/MySQL's native error number.
            if ((int)$e->getCode()!==1146) throw $e;
            throw new \ContentReadError('CONTENT_ADAPTER_UNSUPPORTED',501,'Content reader is not enabled');
        }
        if (!$config || !(int)$config['enabled']) throw new \ContentReadError('CONTENT_ADAPTER_UNSUPPORTED',501,'Content reader is not enabled');
        return $config;
    }
    /**
     * Every readable content's current revision, and which content each contract entity's slots
     * are read in — what content.contract apply checks `expected_content_revisions` against.
     *
     * Opens no transaction: it runs inside the apply's own (after the write) or ahead of it, where
     * the batch's per-row `expected` fields already catch a row that moves in between. Media bytes
     * are not part of a content's revision, so nothing here scans files.
     *
     * @return array{revisions:array<string,string>,owners:array<string,string>}
     */
    public function revisions(): array {
        $config=$this->config();
        $data=[];
        foreach (\ContentProjection::TABLES as $table=>$order) $data[$table]=$this->rows($table,$order);
        $contract=($this->contractFactory)();
        if (!$contract) throw new \ContentReadError('CONTENT_ADAPTER_UNSUPPORTED',501,'Content mapping is unavailable');
        $projection=\ContentProjection::build($data,$contract->readMapping(),$config['site_id'],$this->base,time(),[$contract,'slotValue']);
        return ['revisions'=>$projection['revisions'],'owners'=>$projection['owners']];
    }
    public function read(array $query, string $principal): array {
        $config=$this->config();
        // No opportunistic setup. Missing triggers or a nontransactional source refuse capability.
        $prefix=$this->db->getPrefix();
        $triggers=$this->db->setQuery('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->loadColumn();
        foreach (['article','page','shared'] as $kind) foreach (['insert','delete'] as $event)
            if (!in_array($prefix.'cc_content_'.$kind.'_'.$event,$triggers,true)) throw new \ContentReadError('CONTENT_ADAPTER_UNSUPPORTED',501,'Content identity lifecycle is unavailable');
        if (file_exists($this->root.'/content.json')) throw new \ContentReadError('CONTENT_ADAPTER_UNSUPPORTED',501,'Content route is already occupied');
        $tables=['content','menu','modules','modules_menu','categories','viewlevels','usergroups','assets','associations','languages','claudecowork_content_contract','claudecowork_content_identity'];
        $engines=$this->db->setQuery('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->loadAssocList('TABLE_NAME','ENGINE');
        foreach ($tables as $table) if (($engines[$prefix.$table]??'')!=='InnoDB') throw new \ContentReadError('CONTENT_ADAPTER_UNSUPPORTED',501,'Content snapshot requires transactional tables');
        // File bytes bracket the DB snapshot. Changed bytes force a retry by the caller. Nothing
        // is cached between requests and no transaction spans an HTTP boundary.
        $mediaBefore=$this->media();
        $this->db->setQuery('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')->execute();
        $now=time();
        $this->db->transactionStart();
        try {
            $data=[];
            foreach ($tables as $table) $data[$table]=$this->rows($table,match($table) {
                'modules_menu'=>'moduleid,menuid','associations'=>'context,id','languages'=>'lang_id','claudecowork_content_identity'=>'kind,native_id',default=>'id'});
            foreach ($data['menu'] as $menu) if (trim((string)$menu['path'],'/')==='content.json') throw new \ContentReadError('CONTENT_ADAPTER_UNSUPPORTED',501,'Content route is already occupied');
            $contract=($this->contractFactory)();
            if (!$contract) throw new \ContentReadError('CONTENT_ADAPTER_UNSUPPORTED',501,'Content mapping is unavailable');
            $this->contract=$contract;
            $mapping=$this->contract->readMapping();
            $this->db->transactionCommit();
        } catch (\Throwable $e) { $this->db->transactionRollback(); throw $e; }
        $mediaAfter=$this->media();
        $projection=\ContentProjection::build($data,$mapping,$config['site_id'],$this->base,$now,[$this->contract,'slotValue']);
        $contents=$projection['contents'];
        $opaque=\ContentProjection::opaque($config['site_id']);
        // Only authorized projection dependencies affect the public fingerprint. Hidden rows,
        // unrelated ACL labels and unrelated media cannot signal activity through a revision.
        $readableBefore=$this->readableMedia($contents,$mediaBefore);
        $readableAfter=$this->readableMedia($contents,$mediaAfter);
        if ($readableBefore!==$readableAfter) throw new \ContentReadError('CONTENT_SNAPSHOT_EXPIRED',409,'Readable media changed during the read; restart');
        // The snapshot revision is hashed over every content still marked pending, exactly as before
        // per-content revisions existed, so cursors issued by an older receiver keep their meaning.
        $revision=$this->hash([$contents,$mapping['contractHash'],$readableAfter]);
        // Each content then carries its OWN revision (ContentProjection::revision), the one
        // content.contract apply accepts in expected_content_revisions.
        foreach ($contents as $id=>&$content) $content['revision']=$projection['revisions'][$id];
        unset($content); $localeList=$projection['locales'];
        $manifest=$mapping['manifest'];
        $reader=new \ContentReader(['id'=>$opaque('site',$config['site_id']),'name'=>null,'url'=>$this->base,'defaultLocale'=>null,'locales'=>$localeList],
            ['quickstartTag'=>$manifest['quickstart']['release'],'quickstartVersion'=>$manifest['quickstart']['version'],'contractId'=>$manifest['id'],'contractHash'=>$mapping['contractHash']],
            $contents,$revision,$config['secret'],$principal,$now,[
                ['code'=>'MAPPED_SCOPE','message'=>'Only bound pages, articles and modules in the public CMS audience are included.'],
                ['code'=>'RENDERING_UNKNOWN','message'=>'Module assignments are candidates; layout rendering and exclusion assignments are unresolved.'],
                ['code'=>'ITEM_MAPPING_UNSUPPORTED','message'=>'Positional HTML and ACM slots are fields; stable repeater item mapping is unavailable.'],
                ['code'=>'MEDIA_SCOPE','message'=>'Images outside mapped image slots and remote media bytes are unresolved.'],
                ['code'=>'LOCALE_UNKNOWN','message'=>'Shared and unsupported legacy locale tags are null.']]);
        $response=$reader->read($query);

        return $response;
    }
}
