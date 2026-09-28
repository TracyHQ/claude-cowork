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
            if($current!=$binding)throw new \RuntimeException('Cannot replace a bound baseline');
            return;
        }
        $this->write(1,$binding);
    }
    /**
     * Replace the baseline outright.
     *
     * `save()` refuses this on purpose: a content edit must leave the baseline byte-identical, and
     * one that did not would be a structural change wearing a content apply's clothes. Adding or
     * removing a language is the one act that legitimately changes what the baseline IS, and it
     * reaches this method only from inside the transaction that has already re-derived and
     * re-verified the whole site.
     */
    public function replace(array $binding): void { $this->write(1,$binding); }
    public function job(): ?array {
        $raw=$this->db->setQuery('SELECT binding FROM #__claudecowork_content_contract WHERE id=2')->loadResult();
        return $raw===null?null:json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    }
    public function saveJob(?array $job): void {
        if($job===null) { $this->db->setQuery('DELETE FROM #__claudecowork_content_contract WHERE id=2')->execute(); return; }
        $this->write(2,$job);
    }
    /** One row, one meaning: 1 is what the site IS, 2 is what is being done to it. */
    private function write(int $id, array $value): void {
        $json=$this->db->quote(json_encode($value,JSON_THROW_ON_ERROR));
        $this->db->setQuery('INSERT INTO #__claudecowork_content_contract (id,binding) VALUES ('.$id.','.$json.') ON DUPLICATE KEY UPDATE binding=VALUES(binding)')->execute();
    }
}
