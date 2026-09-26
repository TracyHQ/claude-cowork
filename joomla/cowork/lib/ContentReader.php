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
    /** The caller's `maxBytes`: its bounds, and what a caller naming none gets for a listing. */
    public const MIN_BUDGET=8192;
    public const DEFAULT_BUDGET=65536;
    public const TTL=300;
    private array $site;
    private array $provenance;
    private array $contents;
    private string $revision;
    private string $secret;
    private string $principal;
    private int $now;
    private array $unresolved;
    /** The byte budget of the response being built: UTF-8 bytes of the whole encoded envelope. */
    private int $budget=self::DEFAULT_BUDGET;
    public function __construct(array $site, array $provenance, array $contents, string $revision, string $secret, string $principal, int $now, array $unresolved=[]) {
        $this->site=$site; $this->provenance=$provenance; $this->contents=$contents; $this->revision=$revision;
        $this->secret=$secret; $this->principal=$principal; $this->now=$now; $this->unresolved=$unresolved;
    }
    public static function encode($value): string { return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
    public static function bad(string $message='Invalid content query'): void { throw new ContentReadError('CONTENT_BAD_QUERY',400,$message); }
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
            'pagination'=>['limit'=>$limit,'nextCursor'=>$next,'total'=>$total,'budget'=>$this->budget,'bytes'=>0],
            'completeness'=>['scope'=>'mapped-content','status'=>$this->unresolved?'partial':'complete','unresolved'=>$this->unresolved], 'contents'=>$contents];
    }
    /**
     * The exact encoded size of an envelope whose `pagination.bytes` states that very size: with
     * `bytes:0` it is X+1 bytes long, and the true size S solves S = X + digits(S).
     */
    private static function solve(int $withZero): int {
        $rest=$withZero-1;
        for ($digits=1; ; $digits++) if (strlen((string)($rest+$digits))===$digits) return $rest+$digits;
    }
    private static function measure(array $envelope): int { $envelope['pagination']['bytes']=0; return self::solve(strlen(self::encode($envelope))); }
    private static function finish(array $envelope): array { $envelope['pagination']['bytes']=self::measure($envelope); return $envelope; }
    /** Cut a UTF-8 string to at most $bytes bytes and $chars characters, never inside a codepoint. */
    private static function cut(string $value, int $bytes, int $chars): string {
        if (strlen($value)>$bytes) {
            $end=max(0,$bytes);
            while ($end>0 && (ord($value[$end])&0xC0)===0x80) $end--;
            $value=substr($value,0,$end);
        }
        return mb_strlen($value,'UTF-8')>$chars?mb_substr($value,0,$chars,'UTF-8'):$value;
    }
    /**
     * One content that alone overflows the budget is still answered, never an empty page: its
     * longest text is cut to half the budget in characters (then further, longest first, only if
     * the envelope still overflows), each cut field says `truncated`, the content says `truncated`
     * and lists what was cut in `truncatedFields` (the `field` shape of CONTENT_FIELD_TOO_LARGE).
     * The revision was computed before and is kept: it names the content, not this rendering.
     *
     * @param callable(array):array $build the content → its envelope
     */
    private function truncate(array $content, callable $build): array {
        $first=true; $cuts=[];
        while (($size=self::measure($build($content)))>$this->budget) {
            $paths=[];
            foreach (['bodyHtml','summary','title'] as $key) if (is_string($content[$key]??null)) $paths[]=[[$key],null,null,$key,false];
            foreach ($content['fields']??[] as $f=>$field) if (is_string($field['value']??null)) $paths[]=[['fields',$f,'value'],null,null,(string)$field['key'],true];
            foreach ($content['blocks']??[] as $b=>$block) {
                foreach ($block['fields']??[] as $f=>$field) if (is_string($field['value']??null))
                    $paths[]=[['blocks',$b,'fields',$f,'value'],$block['id'],null,(string)$field['key'],true];
                foreach ($block['items']??[] as $i=>$item) foreach ($item['fields']??[] as $f=>$field) if (is_string($field['value']??null))
                    $paths[]=[['blocks',$b,'items',$i,'fields',$f,'value'],$block['id'],$item['id']??null,(string)$field['key'],true];
            }
            $longest=null; $length=0;
            foreach ($paths as $path) {
                $value=$content; foreach ($path[0] as $step) $value=$value[$step];
                if (strlen($value)>$length) { $longest=$path; $length=strlen($value); }
            }
            if ($longest===null) $this->tooLarge($content['id'],null,'content');
            [$steps,$blockId,$itemId,$key,$isField]=$longest;
            $chars=$first?intdiv($this->budget,2):PHP_INT_MAX;
            $bytes=$first?$length:$length-($size-$this->budget)-16;
            $slot=&$content; foreach ($steps as $step) $slot=&$slot[$step];
            $slot=self::cut($slot,$bytes,$chars); unset($slot);
            if ($isField) { array_pop($steps); $mark=&$content; foreach ($steps as $step) $mark=&$mark[$step]; $mark['truncated']=true; unset($mark); }
            $name=['blockId'=>$blockId,'itemId'=>$itemId,'key'=>$key];
            if (!in_array($name,$cuts,true)) $cuts[]=$name;
            $content['truncated']=true; $content['truncatedFields']=$cuts; $first=false;
        }
        return self::finish($build($content));
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
        // `maxBytes`: the UTF-8 bytes of this response's whole JSON. Never part of a cursor: a
        // cursor names the first content not yet sent, and any budget may continue from there.
        $explicit=array_key_exists('maxBytes',$query);
        if ($explicit) {
            $raw=$query['maxBytes']; unset($query['maxBytes']);
            if (is_int($raw)) $raw=(string)$raw;
            if (!is_string($raw) || !preg_match('/^[1-9][0-9]{0,5}$/D',$raw) || (int)$raw<self::MIN_BUDGET || (int)$raw>self::MAX_BYTES)
                self::bad('maxBytes must be an integer from '.self::MIN_BUDGET.' to '.self::MAX_BYTES);
            $this->budget=(int)$raw;
        }
        foreach ($query as $key=>$value) if (!in_array($key,['id','type','locale','limit','cursor','blocksCursor','blockId','protocolVersions'],true) || !is_string($value) || $value==='') self::bad();
        // Accepted and ignored: a relay announcing the protocols it speaks (`tracy-content/v1`)
        // must not meet a 400 here. It selects nothing today, so it never enters a cursor.
        unset($query['protocolVersions']);
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
        // A detail read with no maxBytes keeps its pre-budget contract (MAX_BYTES segments and
        // CONTENT_FIELD_TOO_LARGE with a firstBlock link); a named budget cuts instead of refusing.
        if ($detail && !$explicit) $this->budget=self::MAX_BYTES;
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
                $build=fn(array $c)=>$this->envelope([$c],1,null,1);
                if (self::measure($build($content))<=$this->budget) return self::finish($build($content));
                if (!$explicit) $this->tooLarge($content['id'],$query['blockId'],'block');
                return $this->truncate($content,$build);
            }
            if (!$explicit) {
                foreach (['title','bodyHtml','summary'] as $key) if (strlen(self::encode($content[$key]??null))>self::MAX_BYTES-8192) $this->tooLarge($content['id'],null,$key);
                foreach ($content['blocks'] as $block) foreach ($block['fields'] as $field)
                    if (strlen(self::encode($field['value']))>self::MAX_BYTES-8192) $this->tooLarge($content['id'],$block['id'],$field['key']);
            }
            $build=fn(array $c)=>$this->envelope([$c],1,null,1);
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
                $envelope=$build($content);
                if (self::measure($envelope)<=$this->budget) break;
                $whole=$content;
                // A raw body and its mapped field can each fit while their combined response
                // does not. Partial permits an absent body: deliver blocks now and retain the
                // complete body for a later segment, including a terminal body-only segment.
                // Absence means not loaded, never null/empty pretending the body is unknown.
                $body=$content['bodyHtml'];
                unset($content['bodyHtml']);
                $content['detailState']='partial';
                $content['links']['next']=$content['links']['self'].'&blocksCursor='.rawurlencode($this->cursor($next));
                $content['blocksPagination']=['limit'=>max(1,count($content['blocks'])),'total'=>count($all),'nextCursor'=>$this->cursor($next)];
                $deferred=$build($content);
                if ($content['blocks'] && self::measure($deferred)<=$this->budget) return self::finish($deferred);
                if ($explicit && count($content['blocks'])===1) return $this->truncate($content,$build);
                if (!$content['blocks']) {
                    if ($explicit) return $this->truncate($whole,$build);
                    $this->tooLarge($content['id'],null,'content');
                }
                $content['bodyHtml']=$body;
                array_pop($content['blocks']);
                if (!$content['blocks'] && $offset<count($all)) $this->tooLarge($content['id'],$all[$offset]['id']??null,'block');
            } while (true);
            return self::finish($envelope);
        }
        $rows=array_values(array_filter($this->contents,fn($c)=>(!isset($query['type'])||$c['type']===$query['type'])&&(!isset($query['locale'])||$c['locale']===$query['locale'])));
        $total=count($rows); $out=[]; $offset=$state['offset'];
        // Whole contents while the envelope, with the cursor it would carry, stays in budget.
        // An encoded list is `[` + items joined by `,` + `]`, so the size of k items is exact
        // arithmetic over the empty envelope and each item's own encoding.
        $page=function(array $out) use ($state,$offset,$total,$limit): array {
            $next=null;
            if ($offset+count($out)<$total) { $state['offset']=$offset+count($out); $next=$this->cursor($state); }
            return $this->envelope($out,(int)$limit,$next,$total);
        };
        $sum=0;
        foreach (array_slice($rows,$offset,(int)$limit) as $content) {
            foreach (['bodyHtml','tags','fields','blocks','images','relations'] as $key) unset($content[$key]);
            $content['detailState']='summary';
            $sum+=strlen(self::encode($content));
            // The empty envelope carries the cursor k items would carry (bytes still 0).
            $empty=$page(array_fill(0,count($out)+1,null)); $empty['contents']=[];
            $size=self::solve(strlen(self::encode($empty))+$sum+count($out));
            if ($size>$this->budget && !$out) {
                // The first content alone overflows. A caller who named a budget gets it cut, never
                // an empty page. One who named none gets the pre-budget answer: whole up to
                // MAX_BYTES, else CONTENT_FIELD_TOO_LARGE — no content is ever cut unasked.
                if ($explicit) return $this->truncate($content,fn(array $c)=>$page([$c]));
                if (self::measure($page([$content]))>self::MAX_BYTES) $this->tooLarge($content['id'],null,'summary');
                $this->budget=self::MAX_BYTES; $out[]=$content; break;
            }
            if ($size>$this->budget) break;
            $out[]=$content;
        }
        return self::finish($page($out));
    }
}
