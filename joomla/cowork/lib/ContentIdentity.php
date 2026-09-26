<?php
/**
 * Explicit opt-in metadata installation. Never invoked by a read request.
 *
 * The identity table is kept in step with the native tables by six AFTER INSERT/DELETE triggers.
 * Joomla's own installer deletes the component's admin menu item under `LOCK TABLES #__menu WRITE`
 * (Nested::delete) every time the component is UPDATED, and a trigger that then writes a table
 * outside that lock makes MariaDB refuse the statement — and with it the whole install (measured
 * 26/09/2026: every 0.18-RC site failed to take the next RC, while 0.17.4 → RC, with no triggers
 * yet, went through). So the component's install script drops the triggers before Joomla touches
 * `#__menu` (`dropTriggers`) and calls `install` again afterwards, which recreates them and backfills
 * the rows written in between; the sweep at the end removes identities whose native row was
 * deleted while no trigger was watching. `uid`s of rows that stayed are never regenerated
 * (INSERT IGNORE), so nothing a customer holds changes across an upgrade.
 */
final class ContentIdentity
{
    public const TABLES = ['article'=>'content', 'page'=>'menu', 'shared'=>'modules'];

    /** The six trigger names, in the order `install` creates them. */
    public static function triggerNames(string $prefix): array
    {
        $names=[];
        foreach (self::TABLES as $kind=>$table) foreach (['insert', 'delete'] as $suffix) $names[]=$prefix.'cc_content_'.$kind.'_'.$suffix;
        return $names;
    }

    /** Whether the reader was ever enabled here: the identity table exists. */
    public static function installed($db): bool
    {
        return (int) $db->setQuery('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='.$db->quote($db->getPrefix().'claudecowork_content_identity'))->loadResult() > 0;
    }

    /** Drops the triggers so Joomla's installer can write `#__menu` under its own LOCK TABLES. */
    public static function dropTriggers($db): void
    {
        foreach (self::triggerNames($db->getPrefix()) as $name) $db->setQuery('DROP TRIGGER IF EXISTS '.$db->quoteName($name))->execute();
    }

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
            // Backfill first (rows inserted while no trigger watched), then sweep (rows deleted then).
            $db->setQuery('INSERT IGNORE INTO #__claudecowork_content_identity(kind,native_id,uid) SELECT '.$db->quote($kind).",id,REPLACE(UUID(),'-','') FROM #__".$table)->execute();
            $db->setQuery('DELETE ci FROM #__claudecowork_content_identity ci LEFT JOIN #__'.$table.' t ON t.id=ci.native_id WHERE ci.kind='.$db->quote($kind).' AND t.id IS NULL')->execute();
        }
        $db->setQuery('UPDATE #__claudecowork_content_reader SET enabled=1 WHERE id=1')->execute();
    }
}
