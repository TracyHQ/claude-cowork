<?php
// Loaded by run.php: `content.update` kind `menuItem` with `fields.alias` re-aliases an existing menu
// item through `realiasMenuItem`, so its own path and every descendant's move with it; the `alias`
// undo entry lets `apply.revert` put the alias and the paths back. Advertised as `info.menu_item_alias`.
// The fake writer's realiasMenuItem walks the branch as the site's lft..rgt update does.

$maToken = 'menu-alias-token-at-least-16';
$maWriter = new FakeSiteWriter();
$maLog = new FakeApplyLog();
$maEngine = new Engine($maToken, [], null, null, null, null, $maWriter, null, $maLog);
$maItem = static fn(int $parent, string $alias, string $path, string $language = '*', array $extra = []) => $extra + [
    'title' => ucfirst($alias), 'alias' => $alias, 'path' => $path, 'parent_id' => $parent, 'client_id' => 0, 'language' => $language, 'published' => 1];
$maWriter->store['menuItem'][201] = $maItem(1, 'services', 'services');
$maWriter->store['menuItem'][202] = $maItem(201, 'roof-repair', 'services/roof-repair');
$maWriter->store['menuItem'][203] = $maItem(202, 'emergency', 'services/roof-repair/emergency');
$maWriter->store['menuItem'][204] = $maItem(1, 'about', 'about');
$maWriter->store['menuItem'][205] = $maItem(1, 'what-we-do', 'what-we-do', 'fr-FR');
$maWriter->store['menuItem'][206] = $maItem(1, 'contact', 'contact', '*', ['client_id' => 1]);
$maPaths = static fn() => array_map(static fn(array $row) => $row['path'], $maWriter->store['menuItem']);
$maStart = $maPaths();
$maUpdate = static fn(int $id, array $fields, string $apply = 'apply-alias') => $maEngine->handle(['token' => $maToken, 'action' => 'content.update',
    'params' => ['apply_id' => $apply, 'kind' => 'menuItem', 'id' => $id, 'fields' => $fields]]);

check('info advertises menu_item_alias', $maEngine->handle(['token' => $maToken, 'action' => 'info'])['info']['menu_item_alias'] ?? null, ['version' => 1]);
check('not on a receiver without a writer', array_key_exists('menu_item_alias', (new Engine($maToken))->handle(['token' => $maToken, 'action' => 'info'])['info']), false);

// ---- refusals -----------------------------------------------------------------------------
foreach (['Roof-Repair', '-repair', 'roof repair', 'roof_repair', "caf\u{e9}-menu", '', 'a/b', str_repeat('a', 401)] as $maBad)
    check('a malformed alias is refused: ' . json_encode(substr($maBad, 0, 12)), $maUpdate(201, ['alias' => $maBad])['error'] ?? null, 'bad_params');
check('an alias of digits only is refused', [$maUpdate(201, ['alias' => '2026'])['error'] ?? null, $maUpdate(201, ['alias' => '2026'])['message'] ?? null],
    ['bad_params', 'alias cannot be only digits: Joomla reads a number in a URL as an id']);
check('an alias that is not a string is refused', [$maUpdate(201, ['alias' => 7])['error'] ?? null, $maUpdate(201, ['alias' => null])['error'] ?? null], ['bad_params', 'bad_params']);
check('a sibling under the same parent and language holding it is a conflict', $maUpdate(201, ['alias' => 'about'])['error'] ?? null, 'conflict');
check('a missing menu item is not found', $maUpdate(299, ['alias' => 'gutters'])['error'] ?? null, 'not_found');
check('refusals wrote nothing and recorded nothing', [$maPaths() === $maStart, $maLog->entries('apply-alias')], [true, []]);

// The same alias in another language, or in the administrator menu, is no sibling of this item.
$maOther = $maUpdate(204, ['alias' => 'what-we-do'], 'apply-languages');
check('an alias held by a sibling in another language is allowed', [$maOther['ok'], $maWriter->store['menuItem'][204]['alias']], [true, 'what-we-do']);
$maAdmin = $maUpdate(201, ['alias' => 'contact'], 'apply-admin');
check('an alias held by an administrator menu item is allowed', $maAdmin['ok'], true);
$maEngine->handle(['token' => $maToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-admin']]);
$maEngine->handle(['token' => $maToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-languages']]);
check('and both revert', $maPaths(), $maStart);

// ---- the round trip ---------------------------------------------------------------------------
$maDone = $maUpdate(201, ['alias' => 'our-services']);
check('a set re-aliases the item and answers the alias', [$maDone['ok'], $maDone['alias'] ?? null, $maWriter->store['menuItem'][201]['alias']],
    [true, 'our-services', 'our-services']);
check('the item and every descendant take the new path', [$maWriter->store['menuItem'][201]['path'], $maWriter->store['menuItem'][202]['path'], $maWriter->store['menuItem'][203]['path']],
    ['our-services', 'our-services/roof-repair', 'our-services/roof-repair/emergency']);
check('items outside the branch keep theirs', [$maWriter->store['menuItem'][204]['path'], $maWriter->store['menuItem'][205]['path']], ['about', 'what-we-do']);
$maEntries = $maLog->entries('apply-alias');
check('one alias undo entry, holding the alias it replaced', $maEntries, [['op' => 'alias', 'kind' => 'menuItem', 'id' => 201, 'before' => 'services']]);
check('apply.list names the step', array_column($maEngine->handle(['token' => $maToken, 'action' => 'apply.list', 'params' => ['apply_id' => 'apply-alias']])['steps'], 'op'), ['alias']);
check('the cache is purged', $maWriter->purges > 0, true);
$maSame = $maUpdate(201, ['alias' => 'our-services'], 'apply-same');
check('the same alias again writes and records nothing', [$maSame['ok'], $maLog->entries('apply-same')], [true, []]);

$maBack = $maEngine->handle(['token' => $maToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-alias']]);
check('apply.revert takes it back', [$maBack['ok'], $maWriter->store['menuItem'][201]['alias']], [true, 'services']);
check('every path is the site\'s own again', $maPaths(), $maStart);

// A deep item: only its own branch moves.
$maUpdate(202, ['alias' => 'roofing'], 'apply-deep');
check('a child re-aliased moves only its branch', [$maWriter->store['menuItem'][201]['path'], $maWriter->store['menuItem'][202]['path'], $maWriter->store['menuItem'][203]['path']],
    ['services', 'services/roofing', 'services/roofing/emergency']);
$maEngine->handle(['token' => $maToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-deep']]);
check('and reverts', $maPaths(), $maStart);

// With a field edit in the same call: the edit lands first, the alias after, so a revert takes the alias
// back first and then the row as it was. The fake writer replaces a whole row, so the order is read off the log.
$maWriter->store['menuItem'][207] = $maItem(1, 'blog', 'blog');
$maBoth = $maUpdate(207, ['alias' => 'news', 'title' => 'News'], 'apply-both');
check('alias and title in one call: both recorded, the field edit first', [$maBoth['ok'], array_column($maLog->entries('apply-both'), 'op')], [true, ['content', 'alias']]);
$maConflictBoth = $maUpdate(204, ['alias' => 'services', 'title' => 'Taken'], 'apply-taken');
check('a conflicting alias refuses the whole call, the title unwritten', [$maConflictBoth['error'] ?? null, $maWriter->store['menuItem'][204]['title'], $maLog->entries('apply-taken')], ['conflict', 'About', []]);

// ---- the site's writer ---------------------------------------------------------------------------
$maSrc = (string) file_get_contents(__DIR__ . '/../com_claudecowork/site/src/Controller/JoomlaSiteWriter.php');
checkTrue('the site writer reads each sibling\'s alias for the conflict check',
    str_contains($maSrc, "['id', 'parent_id', 'lft', 'menutype', 'language', 'published', 'params', 'alias']"));
