<?php
namespace Tracy\Component\ClaudeCowork\Site\Controller;
\defined('_JEXEC') or die;
use Joomla\Database\DatabaseInterface;
/**
 * The locked files' proofs between door calls ({@see \FileProofStore}): one row, private database
 * state, in a table of its own so the content reader's snapshot never carries it. A site whose
 * update has not created the table yet answers no proofs, and the contract hashes every file.
 */
final class JoomlaFileProofStore implements \FileProofStore
{
    private DatabaseInterface $db;
    public function __construct(DatabaseInterface $db) { $this->db=$db; }
    public function load(): array {
        $raw=$this->db->setQuery('SELECT proofs FROM #__claudecowork_file_proof WHERE id=1')->loadResult();
        if(!is_string($raw)||$raw==='')return [];
        $proofs=json_decode($raw,true);
        return is_array($proofs)?$proofs:[];
    }
    public function save(array $proofs): void {
        $json=$this->db->quote(json_encode($proofs,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        $this->db->setQuery('INSERT INTO #__claudecowork_file_proof (id,proofs) VALUES (1,'.$json.') ON DUPLICATE KEY UPDATE proofs=VALUES(proofs)')->execute();
    }
}
