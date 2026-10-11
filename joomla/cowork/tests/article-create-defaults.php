<?php
// Loaded by run.php after author-profile.php (its SQLite driver, ApDb). What a NEW article gets that
// the call did not name, so the row is the one com_content's own ArticleModel would have saved
// (TracyHQ/tch#1182, J5.8). Measured 04/10/2026 on dev e2ef-jo (JA Impact j6): an article created
// through content.update id 0 was stored with created_by = 0 — the door runs as a guest, and
// Table\Content::store() takes the signed-in user — and the home page answered 500 until the site
// was deleted: T4's AuthorModel::getAuthor(0) falls through to implode(',', null).

namespace {
    use Tracy\Component\ClaudeCowork\Site\Controller\JoomlaSiteWriter;

    $acNow = '2026-10-11 14:00:00';

    $acNew = JoomlaSiteWriter::articleCreateDefaults(['title' => 'Hello', 'catid' => 8, 'state' => 1], $acNow, 42);
    check('a new article is by the author it is given, and last changed by them', [$acNew['created_by'], $acNew['modified_by']], [42, 42]);
    check('a new article carries the stamps and the JSON defaults Joomla saves', [
        $acNew['created'], $acNew['modified'], $acNew['images'], $acNew['urls'], $acNew['attribs'], $acNew['metadata'],
        $acNew['metakey'], $acNew['metadesc'], $acNew['access'], $acNew['language'], $acNew['version'],
    ], [$acNow, $acNow, '{}', '{}', '{}', '{}', '', '', 1, '*', 1]);
    check('a published new article is published from now, as ArticleModel::prepareTable does', $acNew['publish_up'], $acNow);
    check('an unpublished new article has no publish date', array_key_exists('publish_up', JoomlaSiteWriter::articleCreateDefaults(['state' => 0], $acNow, 42)), false);

    $acNamed = JoomlaSiteWriter::articleCreateDefaults(['created_by' => 7, 'state' => 1, 'publish_up' => '2026-01-01 00:00:00',
        'attribs' => '{"show_title":"1"}', 'language' => 'en-GB', 'access' => 2], $acNow, 42);
    check('what the call names is kept', [$acNamed['created_by'], $acNamed['modified_by'], $acNamed['publish_up'], $acNamed['attribs'], $acNamed['language'], $acNamed['access']],
        [7, 7, '2026-01-01 00:00:00', '{"show_title":"1"}', 'en-GB', 2]);

    $acEmpty = JoomlaSiteWriter::articleCreateDefaults(['urls' => '', 'images' => ' ', 'metadata' => null, 'created_by' => 0], $acNow, 42);
    check('an empty JSON column, or an author of 0, counts as not named', [$acEmpty['urls'], $acEmpty['images'], $acEmpty['metadata'], $acEmpty['created_by']], ['{}', '{}', '{}', 42]);
    check('an images array is left for Table\\Content::bind() to encode', JoomlaSiteWriter::articleCreateDefaults(['images' => ['image_intro' => 'a.png']], $acNow, 42)['images'], ['image_intro' => 'a.png']);

    if (extension_loaded('pdo_sqlite')) {
        $acDb = new ApDb();
        $acDb->pdo->exec('CREATE TABLE j_users (id INTEGER PRIMARY KEY, name TEXT, block INTEGER)');
        $acDb->pdo->exec('CREATE TABLE j_user_usergroup_map (user_id INTEGER, group_id INTEGER)');
        $acDb->pdo->exec('CREATE TABLE j_assets (id INTEGER PRIMARY KEY, name TEXT, rules TEXT)');
        $acDb->pdo->exec("INSERT INTO j_assets VALUES (1, 'root.1', '{\"core.login.site\":{\"6\":1,\"2\":1},\"core.admin\":{\"8\":1,\"9\":1,\"3\":0}}')");
        // 300 is an editor (group 3, refused core.admin), 301 a blocked Super User, 302 and 303 Super Users.
        $acDb->pdo->exec("INSERT INTO j_users VALUES (300, 'Editor', 0), (301, 'Gone', 1), (303, 'Second admin', 0), (302, 'First admin', 0)");
        $acDb->pdo->exec('INSERT INTO j_user_usergroup_map VALUES (300, 3), (301, 8), (302, 9), (303, 8)');
        $acWriter = new JoomlaSiteWriter($acDb);

        check('the signed-in user writes an article when there is one', $acWriter->articleAuthorId(55), 55);
        check('a guest call is by the first active user of a group the site gives core.admin', $acWriter->articleAuthorId(0), 302);
        $acDb->pdo->exec('DELETE FROM j_user_usergroup_map WHERE group_id IN (8, 9)');
        check('a site with no active administrator: the first active user', $acWriter->articleAuthorId(0), 300);
        $acDb->pdo->exec('UPDATE j_users SET block = 1');
        check('a site with no active user at all: nobody, as before', $acWriter->articleAuthorId(0), 0);
    }
}
