<?php
namespace Tracy\Component\ClaudeCowork\Site\Controller;
\defined('_JEXEC') or die;
use Joomla\Database\DatabaseInterface;
/** Private database state, never a JSON file under the public webroot. */
final class JoomlaContractStore implements \ContractStore
{
    private DatabaseInterface $db;
    public function __construct(DatabaseInterface $db) { $this->db=$db; }
    public function access(): array {
        $inventory=[];
        foreach (['viewlevels'=>'id,rules','usergroups'=>'id,parent_id','assets'=>'id,parent_id,name,rules','modules'=>'id,asset_id','content'=>'id,asset_id','categories'=>'id,asset_id,extension'] as $table=>$fields) {
            $inventory[$table]=$this->db->setQuery('SELECT '.$fields.' FROM #__'.$table)->loadAssocList();
        }
        return \ContractAccess::snapshot($inventory);
    }
    public function load(): ?array {
        $raw=$this->db->setQuery('SELECT binding FROM #__claudecowork_content_contract WHERE id=1')->loadResult();
        return $raw===null?null:json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    }
    public function save(array $binding): void {
        $current=$this->load();
        if($current!==null) {
            if($current!=$binding)throw new \RuntimeException('Cannot replace a content-only baseline');
            return;
        }
        $this->db->setQuery('INSERT INTO #__claudecowork_content_contract (id,binding) VALUES (1,'.$this->db->quote(json_encode($binding,JSON_THROW_ON_ERROR)).')')->execute();
    }
}
