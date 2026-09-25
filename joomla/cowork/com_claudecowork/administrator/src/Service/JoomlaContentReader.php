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
    private function date($value): ?string {
        return !$value || substr($value,0,4)==='0000' ? null : gmdate('Y-m-d\TH:i:s\Z',strtotime($value.' UTC'));
    }
    private function visible(array $row, array $levels, int $now, string $kind): bool {
        if (!in_array((int)($row['access']??0),$levels,true) || (int)($row[$kind==='article'?'state':'published']??0)!==1) return false;
        foreach (['publish_up'=>true,'publish_down'=>false] as $key=>$up) {
            $at=$this->date($row[$key]??null);
            if ($at && ($up ? strtotime($at)>$now : strtotime($at)<=$now)) return false;
        }
        return true;
    }
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
    public function read(array $query, string $principal): array {
        try { $config=$this->db->setQuery('SELECT * FROM #__claudecowork_content_reader WHERE id=1')->loadAssoc(); }
        catch (\Throwable $e) {
            // Missing opt-in metadata is unsupported; a DB timeout/failure is unavailable.
            // Joomla's mysqli statement preserves MariaDB/MySQL's native error number.
            if ((int)$e->getCode()!==1146) throw $e;
            throw new \ContentReadError('CONTENT_ADAPTER_UNSUPPORTED',501,'Content reader is not enabled');
        }
        if (!$config || !(int)$config['enabled']) throw new \ContentReadError('CONTENT_ADAPTER_UNSUPPORTED',501,'Content reader is not enabled');
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
        $levels=[];
        foreach ($data['viewlevels'] as $row) if (in_array(1,json_decode($row['rules'],true)??[],true)) $levels[]=(int)$row['id'];
        $identities=[];
        foreach ($data['claudecowork_content_identity'] as $row) $identities[$row['kind']][(int)$row['native_id']]=$row['uid'];
        $opaque=fn(string $domain,string $key)=>$domain.'_'.substr(hash_hmac('sha256',$domain.':'.$key,$config['site_id']),0,32);
        $contents=[]; $keys=[]; $locales=[]; $visibility=[]; $native=[];
        $categories=array_column($data['categories'],null,'id');
        $menus=array_column($data['menu'],null,'id');
        foreach ($mapping['keys'] as $key=>$meta) {
            $kind=['article'=>'article','menuItem'=>'page','module'=>'shared'][$meta['kind']]??null;
            if ($kind===null || !empty($meta['switcher'])) continue;
            $row=$mapping['rows'][$key]; $nativeId=(int)$mapping['ids'][$key];
            $allowed=$this->visible($row,$levels,$now,$kind);
            if ($kind==='article') {
                $cat=$categories[$row['catid']]??null;
                $seen=[];
                while ($cat && (int)$cat['id']>1) {
                    if (isset($seen[$cat['id']])) throw new \RuntimeException('Category cycle');
                    $seen[$cat['id']]=true;
                    if ((int)$cat['published']!==1 || !in_array((int)$cat['access'],$levels,true)) $allowed=false;
                    $cat=$categories[$cat['parent_id']]??null;
                }
            }
            if ($kind==='page') {
                $parent=$menus[$row['parent_id']]??null; $seen=[];
                while ($parent && (int)$parent['id']>1) {
                    if (isset($seen[$parent['id']])) throw new \RuntimeException('Menu cycle');
                    $seen[$parent['id']]=true;
                    if (!$this->visible($parent,$levels,$now,'page')) $allowed=false;
                    $parent=$menus[$parent['parent_id']]??null;
                }
            }
            $visibility[$key]=$allowed;
            if (!$allowed) continue;
            $uid=$identities[$kind][$nativeId]??null;
            if (!$uid) throw new \ContentReadError('CONTENT_ADAPTER_UNSUPPORTED',501,'Content identity registry is incomplete');
            $id=$opaque('content',$uid); $keys[$key]=$id; $native[$kind][$nativeId]=$id;
            if (isset($contents[$id])) continue;
            $locale=($row['language']??'*')==='*'?null:$row['language'];
            // Joomla's legacy sr-YU is not a canonical language tag. Do not silently relabel it.
            if ($locale==='sr-YU') $locale=null;
            if ($locale!==null) $locales[$locale]=true;
            $url=$kind==='page'?$this->base.'/index.php?Itemid='.$nativeId:($kind==='article'?$this->base.'/index.php?option=com_content&view=article&id='.$nativeId:null);
            $content=['id'=>$id,'type'=>$kind,'title'=>$row['title']??null,'slug'=>$row['alias']??null,'url'=>$url,'locale'=>$locale,'translationGroupId'=>null,'summary'=>null,
                'publishedAt'=>$this->date($row['publish_up']??null),'createdAt'=>$this->date($row['created']??null),'updatedAt'=>$this->date($row['modified']??null),
                'revision'=>'pending','detailState'=>'complete','links'=>['self'=>$this->base.'/content.json?id='.$id],
                'publication'=>['status'=>'published','valueSource'=>'current','scheduledAt'=>null],
                'bodyHtml'=>$kind==='article'?($row['introtext']??'').($row['fulltext']??''):($kind==='shared'&&($row['module']??'')==='mod_custom'?($row['content']??''):null),
                'tags'=>[],'fields'=>[],'blocks'=>[],'images'=>[],'relations'=>[]];
            $fields=[];
            foreach ($mapping['slots'][$key] as $slot) {
                $value=$this->contract->slotValue($row,$slot);
                $fields[]=['key'=>$slot['key'],'type'=>$slot['type'],'value'=>$value,'slotKey'=>$slot['key'],'semanticKey'=>null];
                if ($slot['type']==='image' && $value!=='') {
                    $src=preg_match('~^https?://~',$value)?$value:$this->base.'/'.ltrim($value,'/');
                    $mid=$opaque('media',$value);
                    // The slot is its own block below (same opaque key), so the picture says which
                    // block it belongs to. Alt stays null: a contract slot carries no alt of its own.
                    $block=$opaque('block',$uid.':'.$slot['key']);
                    if (isset($content['images'][$mid])) $content['images'][$mid]['usages'][]=['contentId'=>$id,'blockId'=>$block,'itemId'=>null];
                    else $content['images'][$mid]=['id'=>$mid,'src'=>$src,'alt'=>null,'width'=>null,'height'=>null,'usages'=>[['contentId'=>$id,'blockId'=>$block,'itemId'=>null]]];
                }
            }
            // Physical slots are fields, never manufactured repeater item identities. Bounded
            // chunks contain stable slot keys; their IDs are based on keys, not array position.
            foreach ($fields as $field) $content['blocks'][]=['id'=>$opaque('block',$uid.':'.$field['key']),'key'=>$field['key'],'role'=>null,'position'=>count($content['blocks']),
                'sharedContentId'=>null,'visibility'=>'unknown','fields'=>[$field],'items'=>[]];
            $content['images']=array_values($content['images']);
            $contents[$id]=$content;
        }
        // Relations only from CMS foreign keys/associations. Assignment is a candidate occurrence,
        // never evidence that a layout actually rendered a module.
        $moduleRows=array_column($data['modules'],null,'id');
        $assignments=$data['modules_menu'];
        usort($assignments,fn($a,$b)=>[(int)$moduleRows[$a['moduleid']]['ordering'],(int)$a['moduleid'],(int)$a['menuid']]<=>[(int)$moduleRows[$b['moduleid']]['ordering'],(int)$b['moduleid'],(int)$b['menuid']]);
        foreach ($assignments as $assignment) {
            $source=$native['shared'][(int)$assignment['moduleid']]??null;
            if (!$source) continue;
            foreach ($native['page']??[] as $menuId=>$owner) {
                $menu=(int)$assignment['menuid'];
                if ($menu!==0 && $menu!==$menuId) continue;
                if ($contents[$source]['locale']!==null && $contents[$owner]['locale']!==$contents[$source]['locale']) continue;
                $blockId=$opaque('occurrence',$owner.':'.$source);
                if (in_array($blockId,array_column($contents[$owner]['blocks'],'id'),true)) continue;
                $contents[$owner]['blocks'][]=['id'=>$blockId,'key'=>$blockId,'role'=>null,'position'=>count($contents[$owner]['blocks']),
                    'sharedContentId'=>$source,'visibility'=>'unknown','fields'=>[],'items'=>[]];
            }
        }
        $groups=[];
        foreach ($data['associations'] as $association) {
            $kind=['com_content.item'=>'article','com_menus.item'=>'page'][$association['context']]??null;
            $id=$kind===null?null:($native[$kind][(int)$association['id']]??null);
            if ($id && $contents[$id]['locale']!==null) $groups[$association['context'].':'.$association['key']][]=$id;
        }
        foreach ($groups as $group=>$members) {
            if (count($members)<2 || count(array_unique(array_map(fn($id)=>$contents[$id]['locale'],$members)))!==count($members)) continue;
            sort($members);
            foreach ($members as $id) {
                $contents[$id]['translationGroupId']=$opaque('translation',implode(':',$members));
                foreach ($members as $other) if ($id!==$other) $contents[$id]['relations'][]=['type'=>'translation','contentId'=>$other];
            }
        }
        // Only authorized projection dependencies affect the public fingerprint. Hidden rows,
        // unrelated ACL labels and unrelated media cannot signal activity through a revision.
        ksort($contents);
        $readableBefore=$this->readableMedia($contents,$mediaBefore);
        $readableAfter=$this->readableMedia($contents,$mediaAfter);
        if ($readableBefore!==$readableAfter) throw new \ContentReadError('CONTENT_SNAPSHOT_EXPIRED',409,'Readable media changed during the read; restart');
        $revision=$this->hash([$contents,$mapping['contractHash'],$readableAfter]);
        foreach ($contents as &$content) $content['revision']=$revision;
        unset($content); $localeList=array_keys($locales); sort($localeList);
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
