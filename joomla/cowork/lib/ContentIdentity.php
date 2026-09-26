<?php
/**
 * Explicit opt-in metadata installation. Never invoked by a read request.
 *
 * The identity table is kept in step with `#__content` and `#__modules` by AFTER INSERT/DELETE
 * triggers. `#__menu` gets NO trigger: Joomla writes it through Table\Menu, a nested set that runs
 * every INSERT and DELETE under `LOCK TABLES #__menu WRITE`, and a trigger that then writes a
 * table outside that lock makes MariaDB refuse the statement (error 1442, "Can't update table …
 * already used by statement which invoked this trigger"). Measured 26/09/2026: with the page
 * triggers in place no menu item could be created or deleted on any 0.18-RC site — by an agent
 * through the door or by an administrator in Joomla — and the component could not even be
 * updated, because the installer removes its admin menu item the same way. So page identities are
 * RECONCILED instead (`reconcile`: backfill the rows that appeared, sweep the ones that went)
 * right before every read that needs them; a `uid` stays fixed for the life of its native row.
 *
 * Upgrades: the component's install script drops every trigger — the two legacy page triggers
 * included — before Joomla touches `#__menu`, and calls `install` afterwards, which recreates the
 * four that remain and reconciles every kind. `uid`s of rows that stayed are never regenerated
 * (INSERT IGNORE), so nothing a customer holds changes across an upgrade.
 */
final class ContentIdentity
{
    public const TABLES = ['article'=>'content', 'page'=>'menu', 'shared'=>'modules'];
    /** The kinds a trigger keeps: every table Joomla writes without LOCK TABLES. */
    public const TRIGGERED = ['article'=>'content', 'shared'=>'modules'];

    /**
     * Every trigger name this component ever created, in creation order — the legacy page pair
     * included, so `dropTriggers` also cleans a site upgraded from a release that still had them.
     */
    public static function triggerNames(string $prefix): array
    {
        $names=[];
        foreach (self::TABLES as $kind=>$table) foreach (['insert', 'delete'] as $suffix) $names[]=$prefix.'cc_content_'.$kind.'_'.$suffix;
        return $names;
    }

    /** The trigger names a healthy site holds: the reader refuses capability when one is missing. */
    public static function requiredTriggerNames(string $prefix): array
    {
        $names=[];
        foreach (self::TRIGGERED as $kind=>$table) foreach (['insert', 'delete'] as $suffix) $names[]=$prefix.'cc_content_'.$kind.'_'.$suffix;
        return $names;
    }

    /**
     * Brings one kind's identities level with its native table: a row that appeared gets a fresh
     * uid (rows that already have one keep it), a row that went loses its identity. Two statements,
     * run inside the caller's transaction where there is one.
     */
    public static function reconcile($db, string $kind): void
    {
        $table=self::TABLES[$kind];
        $db->setQuery('INSERT IGNORE INTO #__claudecowork_content_identity(kind,native_id,uid) SELECT '.$db->quote($kind).",id,REPLACE(UUID(),'-','') FROM #__".$table)->execute();
        $db->setQuery('DELETE ci FROM #__claudecowork_content_identity ci LEFT JOIN #__'.$table.' t ON t.id=ci.native_id WHERE ci.kind='.$db->quote($kind).' AND t.id IS NULL')->execute();
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
        foreach (self::TRIGGERED as $kind=>$table) {
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
        }
        // Every kind, triggered or not: backfill what appeared while no trigger watched, then sweep
        // what went. The triggered kinds keep themselves level from here on; `page` is reconciled
        // again before each read.
        foreach (array_keys(self::TABLES) as $kind) self::reconcile($db, $kind);
        $db->setQuery('UPDATE #__claudecowork_content_reader SET enabled=1 WHERE id=1')->execute();
    }
}
