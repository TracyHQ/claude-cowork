<?php
// Loaded by run.php. What JoomlaSiteWriter fills in when an Apply CREATES a redirect or a custom
// field, and what the engine says about a redirect on a site whose redirect plugin is off. Both
// creates were refused by the database for NOT NULL columns the writer never set (benchmark v6).
// The real writer runs against a stub driver that records the row it is asked to insert.

namespace Joomla\CMS {
    if (!class_exists(Factory::class)) {
        /** Just the two calls a create makes: the clock and the signed-in editor. */
        final class Factory
        {
            public static function getDate(): object
            {
                return new class { public function toSql(): string { return '2026-09-29 10:00:00'; } };
            }
            public static function getApplication(): object
            {
                return new class { public function getIdentity(): object { return (object) ['id' => 42]; } };
            }
        }
    }
}

namespace Joomla\Database {
    if (!interface_exists(DatabaseInterface::class)) {
        interface DatabaseInterface {}
    }
}

namespace {
    if (!defined('_JEXEC')) {
        define('_JEXEC', 1);
    }
    require_once __DIR__ . '/../com_claudecowork/site/src/Controller/JoomlaSiteWriter.php';

    /** Records insertObject(); nothing else of the driver is reached by a create with no tags. */
    final class CreateRecordingDb implements \Joomla\Database\DatabaseInterface
    {
        public array $inserted = [];
        public function insertObject(string $table, object $object, string $key): bool
        {
            $object->{$key} = 77;
            $this->inserted[] = [$table, (array) $object];
            return true;
        }
    }

    use Tracy\Component\ClaudeCowork\Site\Controller\JoomlaSiteWriter;

    $cwDb = new CreateRecordingDb();
    $cwWriter = new JoomlaSiteWriter($cwDb);

    // ------------------------------------------------------------------ redirect
    check('a redirect create answers the new id', $cwWriter->write('redirect', 0, ['old_url' => '/old', 'new_url' => '/new']), 77);
    [$cwTable, $cwRow] = $cwDb->inserted[0];
    check('redirect: into its own table', $cwTable, '#__redirect_links');
    check('redirect: referer is filled (NOT NULL, no default)', $cwRow['referer'], '');
    check('redirect: hits start at 0', $cwRow['hits'], 0);
    check('redirect: created and modified are the same stamp', [$cwRow['created_date'], $cwRow['modified_date']], ['2026-09-29 10:00:00', '2026-09-29 10:00:00']);
    check('redirect: the caller words are kept', [$cwRow['old_url'], $cwRow['new_url']], ['/old', '/new']);
    check('redirect: published is left to the table default', array_key_exists('published', $cwRow), false);

    $cwDb->inserted = [];
    $cwWriter->write('redirect', 0, ['old_url' => '/a', 'new_url' => '/b', 'published' => 1, 'referer' => 'x', 'hits' => 9]);
    $cwRow = $cwDb->inserted[0][1];
    check('redirect: published is honoured, and referer/hits are not the caller\'s', [$cwRow['published'], $cwRow['referer'], $cwRow['hits']], [1, '', 0]);

    // --------------------------------------------------------------------- field
    $cwDb->inserted = [];
    $cwWriter->write('field', 0, ['title' => 'Phone Number', 'type' => 'tel', 'context' => 'com_contact.mail']);
    [$cwTable, $cwRow] = $cwDb->inserted[0];
    check('field: into #__fields', $cwTable, '#__fields');
    check('field: name is a slug of the title', $cwRow['name'], 'phone-number');
    check('field: type and context are kept', [$cwRow['type'], $cwRow['context']], ['tel', 'com_contact.mail']);
    check('field: group_id defaults to 0', $cwRow['group_id'], 0);
    check('field: json and text defaults', [$cwRow['params'], $cwRow['fieldparams'], $cwRow['description']], ['{}', '{}', '']);
    check('field: created_time and modified_time are stamped', [$cwRow['created_time'], $cwRow['modified_time']], ['2026-09-29 10:00:00', '2026-09-29 10:00:00']);
    check('field: created_user_id is the signed-in editor', $cwRow['created_user_id'], 42);

    $cwDb->inserted = [];
    $cwWriter->write('field', 0, ['title' => 'Budget', 'name' => 'budget', 'type' => 'list', 'context' => 'com_content.article', 'group_id' => 3]);
    $cwRow = $cwDb->inserted[0][1];
    check('field: an explicit name and group are kept', [$cwRow['name'], $cwRow['group_id'], $cwRow['type']], ['budget', 3, 'list']);

    $cwRefused = function (array $fields) use ($cwWriter): string {
        try {
            $cwWriter->write('field', 0, $fields);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        return '';
    };
    checkTrue('field: a type outside the list is refused', str_contains($cwRefused(['title' => 'X', 'type' => 'sql', 'context' => 'com_contact.contact']), 'type must be one of'));
    checkTrue('field: a context outside the list is refused', str_contains($cwRefused(['title' => 'X', 'type' => 'text', 'context' => 'com_users.user']), 'context must be one of'));
    checkTrue('field: no context is refused too', str_contains($cwRefused(['title' => 'X', 'type' => 'text']), 'context must be one of'));
    checkTrue('field: no name and no title is refused', str_contains($cwRefused(['type' => 'text', 'context' => 'com_contact.contact']), 'needs a name'));
    check('field: nothing was inserted by a refusal', count($cwDb->inserted), 1);

    // ------------------------------------------------- other kinds stay as they were
    $cwDb->inserted = [];
    $cwWriter->write('banner', 0, ['name' => 'Ad', 'type' => 'ignored']);
    $cwRow = $cwDb->inserted[0][1];
    check('another kind gets no stamps and no create-only columns', array_keys($cwRow), ['name', 'id']);
    check('static: a non-field create is returned unchanged', JoomlaSiteWriter::createFields('banner', ['name' => 'A']), ['name' => 'A']);
    check('static: a non-create kind has no stamps', JoomlaSiteWriter::createStamps('tag', 'now', 1), []);

    // ------------------------------------------ the answer names a disabled redirect plugin
    $cwExt = new FakeExtensions();
    $cwLog = new FakeApplyLog();
    $cwCall = function (bool $enabled) use ($cwExt, $cwLog, $WTOKEN): array {
        $cwExt->manifest = ['platform' => 'joomla', 'platformVersion' => '5.1.2', 'extensions' => [
            ['type' => 'plugin', 'element' => 'redirect', 'folder' => 'system', 'core' => true, 'enabled' => $enabled, 'version' => '5.1.2'],
        ]];
        $engine = new Engine($WTOKEN, [], null, null, null, $cwExt, new FakeSiteWriter(), null, $cwLog);
        return $engine->handle(['token' => $WTOKEN, 'action' => 'content.update',
            'params' => ['apply_id' => 'r-' . (int) $enabled, 'kind' => 'redirect', 'fields' => ['old_url' => '/o', 'new_url' => '/n']]]);
    };
    $cwOff = $cwCall(false);
    check('a redirect on a site with the plugin off still lands', $cwOff['ok'], true);
    check('and says the plugin is off', $cwOff['warnings'][0]['code'] ?? null, 'REDIRECT_PLUGIN_DISABLED');
    checkTrue('and names the next step', str_contains($cwOff['warnings'][0]['message'] ?? '', 'extension.enable'));
    check('with the plugin on there is no warning', isset($cwCall(true)['warnings']), false);
}
