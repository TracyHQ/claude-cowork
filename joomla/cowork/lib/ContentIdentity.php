<?php
/** Explicit opt-in metadata installation. Never invoked by a read request. */
final class ContentIdentity
{
    public const TABLES = ['article'=>'content', 'page'=>'menu', 'shared'=>'modules'];

    public static function install($db, bool $newSite = false): void
    {
        $db->setQuery('CREATE TABLE IF NOT EXISTS #__claudecowork_content_identity (kind VARCHAR(16) NOT NULL, native_id BIGINT NOT NULL, uid CHAR(32) NOT NULL, PRIMARY KEY(kind,native_id), UNIQUE KEY(uid)) ENGINE=InnoDB')->execute();
        $db->setQuery('CREATE TABLE IF NOT EXISTS #__claudecowork_content_reader (id INT PRIMARY KEY, site_id CHAR(32) NOT NULL, secret CHAR(64) NOT NULL, enabled TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB')->execute();
        $db->setQuery('INSERT IGNORE INTO #__claudecowork_content_reader VALUES (1,'.$db->quote(bin2hex(random_bytes(16))).','.$db->quote(bin2hex(random_bytes(32))).',0)')->execute();
        // Trigger creation is a capability prerequisite, not best-effort identity. A failed setup
        // remains disabled. Native INSERT/DELETE and their identity changes share the DB transaction.
        $db->setQuery('UPDATE #__claudecowork_content_reader SET enabled=0 WHERE id=1')->execute();
        if ($newSite) $db->setQuery('UPDATE #__claudecowork_content_reader SET site_id='.$db->quote(bin2hex(random_bytes(16))).',secret='.$db->quote(bin2hex(random_bytes(32))).' WHERE id=1')->execute();
        foreach (self::TABLES as $kind=>$table) {
            foreach (['insert'=>'INSERT', 'delete'=>'DELETE'] as $suffix=>$event) {
                $name=$db->quoteName($db->getPrefix().'cc_content_'.$kind.'_'.$suffix);
                $exists=$db->setQuery('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='.$db->quote($db->getPrefix().'cc_content_'.$kind.'_'.$suffix))->loadResult();
                if (!$exists) {
                    $action=$suffix==='insert'
                        ? "INSERT INTO #__claudecowork_content_identity(kind,native_id,uid) VALUES (".$db->quote($kind).",NEW.id,REPLACE(UUID(),'-',''))"
                        : 'DELETE FROM #__claudecowork_content_identity WHERE kind='.$db->quote($kind).' AND native_id=OLD.id';
                    $db->setQuery('CREATE TRIGGER '.$name.' AFTER '.$event.' ON #__'.$table.' FOR EACH ROW '.$action)->execute();
                }
            }
            $db->setQuery('INSERT IGNORE INTO #__claudecowork_content_identity(kind,native_id,uid) SELECT '.$db->quote($kind).",id,REPLACE(UUID(),'-','') FROM #__".$table)->execute();
        }
        $db->setQuery('UPDATE #__claudecowork_content_reader SET enabled=1 WHERE id=1')->execute();
    }
}
