<?php
/** Compare effective ACL ancestry, allowing Joomla to materialize an empty inherited asset on save. */
final class ContractAccess {
    public static function snapshot(array $inventory): array {
        $out=[];
        foreach(['viewlevels'=>['id','rules'],'usergroups'=>['id','parent_id']] as $table=>$fields) {
            $rows=$inventory[$table];usort($rows,fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
            $out[$table]=array_map(function($r)use($fields){$v=[];foreach($fields as $f)$v[$f]=$f==='rules'?json_decode($r[$f],true,512,JSON_THROW_ON_ERROR):(string)$r[$f];return $v;},$rows);
        }
        $assets=[];$names=[];
        foreach($inventory['assets'] as $asset){$assets[(int)$asset['id']]=$asset;$names[$asset['name']]=(int)$asset['id'];}
        $chain=function(int $id)use($assets){
            $seen=[];$chain=[];
            while($id) {
                if(isset($seen[$id])||!isset($assets[$id]))throw new RuntimeException('Invalid ACL ancestry');
                $seen[$id]=true;$row=$assets[$id];$rules=json_decode($row['rules'],true,512,JSON_THROW_ON_ERROR);
                if($rules)$chain[]=['name'=>$row['name'],'rules'=>$rules];
                $id=(int)$row['parent_id'];
            }
            return array_reverse($chain);
        };
        $out['componentRules']=[];
        foreach(['root.1','com_content','com_modules','com_menus'] as $name)$out['componentRules'][$name]=$chain($names[$name]??0);
        $out['entityRules']=[];
        foreach(['modules'=>'com_modules','content'=>'com_content','categories'=>'com_content'] as $table=>$component) {
            foreach($inventory[$table] as $row) {
                if($table==='categories' && ($row['extension']??'')!=='com_content')continue;
                $id=(int)($row['asset_id']??0);
                $out['entityRules'][$table.'.'.$row['id']]=$chain($id?:($names[$component]??0));
            }
        }
        ksort($out['entityRules']);return $out;
    }
}
