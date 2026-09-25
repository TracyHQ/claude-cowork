<?php
/** Projection and signed continuation protocol. No Joomla globals and no writes. */
final class ContentReadError extends RuntimeException
{
    public string $reason;
    public int $status;
    public array $extra;
    public function __construct(string $reason, int $status, string $message, array $extra=[]) {
        parent::__construct($message); $this->reason=$reason; $this->status=$status; $this->extra=$extra;
    }
    public function body(): array { return ['error'=>['code'=>$this->reason,'message'=>$this->getMessage()]+$this->extra]; }
}
final class ContentReader
{
    public const MAX_BYTES=262144;
    public const TTL=300;
    private array $site;
    private array $provenance;
    private array $contents;
    private string $revision;
    private string $secret;
    private string $principal;
    private int $now;
    private array $unresolved;
    public function __construct(array $site, array $provenance, array $contents, string $revision, string $secret, string $principal, int $now, array $unresolved=[]) {
        $this->site=$site; $this->provenance=$provenance; $this->contents=$contents; $this->revision=$revision;
        $this->secret=$secret; $this->principal=$principal; $this->now=$now; $this->unresolved=$unresolved;
    }
    public static function encode($value): string { return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
    public static function bad(): void { throw new ContentReadError('CONTENT_BAD_QUERY',400,'Invalid content query'); }
    public static function parse(string $raw): array {
        $out=[];
        foreach ($raw===''?[]:explode('&',$raw) as $pair) {
            [$key,$value]=array_pad(explode('=',$pair,2),2,''); $key=urldecode($key); $value=urldecode($value);
            if (isset($out[$key]) || strpos($key,'[')!==false) self::bad();
            $out[$key]=$value;
        }
        return $out;
    }
    private function cursor(array $state): string {
        $data=rtrim(strtr(base64_encode(self::encode($state)),'+/','-_'),'=');
        return $data.'.'.hash_hmac('sha256',$data,$this->secret);
    }
    private function decode(string $cursor): array {
        if (strlen($cursor)>4096 || !preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D',$cursor,$m) || !hash_equals(hash_hmac('sha256',$m[1],$this->secret),$m[2])) self::bad();
        $state=json_decode(base64_decode(strtr($m[1],'-_','+/')),true);
        if (!is_array($state) || ($state['site']??null)!==$this->site['id'] || ($state['principal']??null)!==$this->principal) self::bad();
        if (($state['expires']??0)<=$this->now || ($state['revision']??null)!==$this->revision)
            throw new ContentReadError('CONTENT_SNAPSHOT_EXPIRED',409,'Restart the content scan');
        return $state;
    }
    private function envelope(array $contents, int $limit, ?string $next, ?int $total): array {
        return ['schemaVersion'=>'tracy-content/v1','view'=>'authenticated','site'=>$this->site,'provenance'=>$this->provenance,
            'snapshot'=>['revision'=>$this->revision,'readAt'=>gmdate('Y-m-d\TH:i:s\Z',$this->now)],
            'pagination'=>['limit'=>$limit,'nextCursor'=>$next,'total'=>$total],
            'completeness'=>['scope'=>'mapped-content','status'=>$this->unresolved?'partial':'complete','unresolved'=>$this->unresolved], 'contents'=>$contents];
    }
    private function tooLarge(string $id, ?string $block, string $key): void {
        $extra=['field'=>['contentId'=>$id,'blockId'=>$block,'itemId'=>null,'key'=>$key]];
        $content=$this->contents[$id]??null;
        if ($key==='bodyHtml' && $block===null && !empty($content['blocks'])) {
            $first=$content['blocks'][0]['id'];
            $state=['site'=>$this->site['id'],'principal'=>$this->principal,'revision'=>$this->revision,
                'expires'=>$this->now+self::TTL,'query'=>['id'=>$id,'blockId'=>$first],'offset'=>0];
            $extra['snapshot']=['revision'=>$this->revision,'readAt'=>gmdate('Y-m-d\TH:i:s\Z',$this->now)];
            $extra['links']=['firstBlock'=>$content['links']['self'].'&blockId='.rawurlencode($first).'&blocksCursor='.rawurlencode($this->cursor($state))];
        }
        throw new ContentReadError('CONTENT_FIELD_TOO_LARGE',413,'A content field exceeds the response budget',$extra);
    }
    public function read(array $query): array {
        if (isset($query['limit']) && is_int($query['limit'])) $query['limit']=(string)$query['limit'];
        foreach ($query as $key=>$value) if (!in_array($key,['id','type','locale','limit','cursor','blocksCursor','blockId'],true) || !is_string($value) || $value==='') self::bad();
        // The signed cursor carries its filters; explicit repeats must still match below.
        if (isset($query['cursor'])) {
            $carried=$this->decode($query['cursor'])['query']??[];
            foreach (['type','locale','limit'] as $key)
                if (!isset($query[$key]) && isset($carried[$key])) $query[$key]=(string)$carried[$key];
        }
        $detail=isset($query['id']);
        if ($detail && (isset($query['cursor'])||isset($query['type'])||isset($query['locale'])||isset($query['limit']))) self::bad();
        if (!$detail && (isset($query['blocksCursor'])||isset($query['blockId']))) self::bad();
        $limit=$query['limit']??'30';
        if (!preg_match('/^[1-9][0-9]{0,2}$/D',$limit) || (int)$limit>100) self::bad();
        if (isset($query['type']) && !in_array($query['type'],['page','article','shared','generic','service','project'],true)) self::bad();
        if (isset($query['locale']) && !in_array($query['locale'],$this->site['locales'],true)) self::bad();
        $identity=$detail?['id'=>$query['id']]:['type'=>$query['type']??null,'locale'=>$query['locale']??null,'limit'=>(int)$limit];
        if (isset($query['blockId'])) $identity['blockId']=$query['blockId'];
        $state=['site'=>$this->site['id'],'principal'=>$this->principal,'revision'=>$this->revision,'expires'=>$this->now+self::TTL,'query'=>$identity,'offset'=>0];
        if (isset($query['cursor'])||isset($query['blocksCursor'])) {
            $state=$this->decode($query['cursor']??$query['blocksCursor']);
            if (($state['query']??null)!==$identity) self::bad();
        }
        if ($detail) {
            $content=$this->contents[$query['id']]??null;
            if (!$content) throw new ContentReadError('CONTENT_NOT_FOUND',404,'Content not found');
            if (isset($query['blockId'])) {
                $all=$content['blocks']; $position=array_search($query['blockId'],array_column($all,'id'),true);
                if ($position===false) throw new ContentReadError('CONTENT_NOT_FOUND',404,'Content not found');
                if (isset($query['blocksCursor']) && $state['offset']!==$position) self::bad();
                foreach (['bodyHtml','fields','tags','relations'] as $key) unset($content[$key]);
                $content['blocks']=[$all[$position]]; $content['detailState']='partial';
                $next=null; $content['links']['next']=$content['links']['self'];
                if (isset($all[$position+1])) {
                    $state['offset']=$position+1; $state['query']['blockId']=$all[$position+1]['id']; $next=$this->cursor($state);
                    $content['links']['next'].='&blockId='.rawurlencode($all[$position+1]['id']).'&blocksCursor='.rawurlencode($next);
                }
                $content['blocksPagination']=['limit'=>1,'total'=>count($all),'nextCursor'=>$next];
                $envelope=$this->envelope([$content],1,null,1);
                if (strlen(self::encode($envelope))>self::MAX_BYTES) $this->tooLarge($content['id'],$query['blockId'],'block');
                return $envelope;
            }
            foreach (['title','bodyHtml','summary'] as $key) if (strlen(self::encode($content[$key]??null))>self::MAX_BYTES-8192) $this->tooLarge($content['id'],null,$key);
            foreach ($content['blocks'] as $block) foreach ($block['fields'] as $field)
                if (strlen(self::encode($field['value']))>self::MAX_BYTES-8192) $this->tooLarge($content['id'],$block['id'],$field['key']);
            $all=$content['blocks']; $offset=$state['offset'];
            $content['blocks']=array_slice($all,$offset,100);
            do {
                $more=$offset+count($content['blocks'])<count($all);
                $next=$state; $next['offset']=$offset+count($content['blocks']);
                $token=$more?$this->cursor($next):null;
                $content['detailState']=$more?'partial':'complete';
                if ($more) $content['links']['next']=$content['links']['self'].'&blocksCursor='.rawurlencode($token);
                else unset($content['links']['next']);
                if ($more || $offset>0) $content['blocksPagination']=['limit'=>max(1,count($content['blocks'])),'total'=>count($all),'nextCursor'=>$token];
                $envelope=$this->envelope([$content],1,null,1);
                if (strlen(self::encode($envelope))<=self::MAX_BYTES) break;
                // A raw body and its mapped field can each fit while their combined response
                // does not. Partial permits an absent body: deliver blocks now and retain the
                // complete body for a later segment, including a terminal body-only segment.
                // Absence means not loaded, never null/empty pretending the body is unknown.
                $body=$content['bodyHtml'];
                unset($content['bodyHtml']);
                $content['detailState']='partial';
                $content['links']['next']=$content['links']['self'].'&blocksCursor='.rawurlencode($this->cursor($next));
                $content['blocksPagination']=['limit'=>max(1,count($content['blocks'])),'total'=>count($all),'nextCursor'=>$this->cursor($next)];
                $deferred=$this->envelope([$content],1,null,1);
                if ($content['blocks'] && strlen(self::encode($deferred))<=self::MAX_BYTES) return $deferred;
                $content['bodyHtml']=$body;
                if (!$content['blocks']) $this->tooLarge($content['id'],null,'content');
                array_pop($content['blocks']);
                if (!$content['blocks'] && $offset<count($all)) $this->tooLarge($content['id'],$all[$offset]['id']??null,'block');
            } while (true);
            return $envelope;
        }
        $rows=array_values(array_filter($this->contents,fn($c)=>(!isset($query['type'])||$c['type']===$query['type'])&&(!isset($query['locale'])||$c['locale']===$query['locale'])));
        $total=count($rows); $out=[]; $offset=$state['offset'];
        foreach (array_slice($rows,$offset,(int)$limit) as $content) {
            foreach (['bodyHtml','tags','fields','blocks','images','relations'] as $key) unset($content[$key]);
            $content['detailState']='summary'; $out[]=$content;
            $candidate=$this->envelope($out,(int)$limit,null,$total);
            if (strlen(self::encode($candidate))>self::MAX_BYTES-4096) { array_pop($out); break; }
        }
        if (!$out && $offset<$total) $this->tooLarge($rows[$offset]['id'],null,'summary');
        $next=null;
        if ($offset+count($out)<$total) { $state['offset']=$offset+count($out); $next=$this->cursor($state); }
        return $this->envelope($out,(int)$limit,$next,$total);
    }
}
