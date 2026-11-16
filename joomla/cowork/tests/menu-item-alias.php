<?php
// Loaded by run.php after the write doubles (FakeSiteWriter, FakeApplyLog). content.update renames an
// existing menu item's address through `fields: { alias }`: the writer's path-chain rename, an undo
// apply.revert plays back, Joomla's URL-safe form, and a refusal when a sibling already answers there.

if (function_exists('check')) {
    $maToken = 'alias-token-at-least-16chars';
    $maFresh = function (): array {
        $writer = new FakeSiteWriter();
        $writer->store['menuItem'][10] = ['alias' => 'classes', 'path' => 'classes', 'parent_id' => 1, 'language' => '*', 'home' => 0, 'client_id' => 0, 'title' => 'Classes'];
        $writer->store['menuItem'][11] = ['alias' => 'group-boxing', 'path' => 'classes/group-boxing', 'parent_id' => 10, 'language' => '*', 'home' => 0, 'client_id' => 0, 'title' => 'Group boxing'];
        $writer->store['menuItem'][12] = ['alias' => 'advanced-boxing', 'path' => 'classes/advanced-boxing', 'parent_id' => 10, 'language' => '*', 'home' => 0, 'client_id' => 0, 'title' => 'Advanced'];
        $writer->store['menuItem'][13] = ['alias' => 'schedule', 'path' => 'classes/group-boxing/schedule', 'parent_id' => 11, 'language' => '*', 'home' => 0, 'client_id' => 0, 'title' => 'Schedule'];
        $writer->store['menuItem'][1] = ['alias' => 'home', 'path' => 'home', 'parent_id' => 1, 'language' => '*', 'home' => 1, 'client_id' => 0, 'title' => 'Home'];
        $writer->store['category'][20] = ['alias' => 'pages', 'path' => 'pages', 'parent_id' => 1, 'language' => '*'];
        $log = new FakeApplyLog();
        return [$writer, $log, new Engine('alias-token-at-least-16chars', [], null, null, null, null, $writer, new FakeMediaWriter(), $log)];
    };
    $maUpdate = fn (Engine $engine, string $apply, string $kind, int $id, array $fields): array => $engine->handle(['token' => $maToken, 'action' => 'content.update',
        'params' => ['apply_id' => $apply, 'kind' => $kind, 'id' => $id, 'fields' => $fields]]);

    [$maWriter, $maLog, $maEngine] = $maFresh();
    $maOut = $maUpdate($maEngine, 'MA1', 'menuItem', 11, ['alias' => 'tennis']);
    check('alias: a menu item takes a new alias', [$maOut['ok'], $maOut['alias'], $maWriter->store['menuItem'][11]['alias']], [true, 'tennis', 'tennis']);
    check('alias: its path and the path of the item under it follow',
        [$maWriter->store['menuItem'][11]['path'], $maWriter->store['menuItem'][13]['path']], ['classes/tennis', 'classes/tennis/schedule']);
    check('alias: the rename records an ordinary menu alias undo', $maLog->entries('MA1'),
        [['op' => 'menuAlias', 'kind' => 'menuItem', 'id' => 11, 'before' => 'group-boxing']]);
    $maEngine->handle(['token' => $maToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'MA1']]);
    check('alias: apply.revert puts the old address back, branch included',
        [$maWriter->store['menuItem'][11]['alias'], $maWriter->store['menuItem'][11]['path'], $maWriter->store['menuItem'][13]['path']],
        ['group-boxing', 'classes/group-boxing', 'classes/group-boxing/schedule']);

    [$maWriter, $maLog, $maEngine] = $maFresh();
    $maOut = $maUpdate($maEngine, 'MA2', 'menuItem', 12, ['alias' => '  Padel & Squash Courts! ']);
    check('alias: the value is made URL safe the way Joomla makes one', [$maOut['ok'], $maOut['alias'], $maWriter->store['menuItem'][12]['path']],
        [true, 'padel-squash-courts', 'classes/padel-squash-courts']);
    check('alias: the pure form, digits kept and hyphens collapsed', [Engine::urlSafeAlias('Swimming -- Lessons 2'), Engine::urlSafeAlias('--About_Us--')],
        ['swimming-lessons-2', 'about-us']);

    [$maWriter, $maLog, $maEngine] = $maFresh();
    $maOut = $maUpdate($maEngine, 'MA3', 'menuItem', 12, ['alias' => 'group-boxing']);
    check('alias: a sibling already at that address refuses the call and names it', [$maOut['ok'], $maOut['error'], $maOut['holder']], [false, 'alias_taken', 11]);
    check('alias: a refused rename writes nothing and records nothing',
        [$maWriter->store['menuItem'][12]['alias'], $maLog->entries('MA3')], ['advanced-boxing', []]);
    $maWriter->store['menuItem'][11]['language'] = 'fr-FR';
    $maWriter->store['menuItem'][12]['language'] = 'en-GB';
    $maOut = $maUpdate($maEngine, 'MA3', 'menuItem', 12, ['alias' => 'group-boxing']);
    check('alias: a sibling in another language does not hold the address', [$maOut['ok'], $maWriter->store['menuItem'][12]['alias']], [true, 'group-boxing']);
    $maOut = $maUpdate($maEngine, 'MA3', 'menuItem', 13, ['alias' => 'advanced-boxing']);
    check('alias: an item under another parent does not hold the address', $maOut['ok'], true);

    [$maWriter, $maLog, $maEngine] = $maFresh();
    $maOut = $maUpdate($maEngine, 'MA4', 'menuItem', 11, ['alias' => 'group-boxing']);
    check('alias: the address it already has is an answer, not a write', [$maOut['ok'], $maOut['alias'], $maLog->entries('MA4')], [true, 'group-boxing', []]);
    $maOut = $maUpdate($maEngine, 'MA4', 'menuItem', 11, ['alias' => '!!!']);
    check('alias: a value with no letter or digit is refused', [$maOut['ok'], $maOut['error']], [false, 'bad_params']);
    $maOut = $maUpdate($maEngine, 'MA4', 'menuItem', 1, ['alias' => 'start']);
    check('alias: the home item is not renamed', [$maOut['ok'], $maOut['error'], $maWriter->store['menuItem'][1]['alias']], [false, 'unsupported', 'home']);
    $maOut = $maUpdate($maEngine, 'MA4', 'menuItem', 99, ['alias' => 'ghost']);
    check('alias: a missing item is refused', [$maOut['ok'], $maOut['error']], [false, 'write_failed']);

    [$maWriter, $maLog, $maEngine] = $maFresh();
    $maOut = $maUpdate($maEngine, 'MA5', 'category', 20, ['alias' => 'services']);
    check('alias: a category rename is refused out loud, never dropped', [$maOut['ok'], $maOut['error'], $maWriter->store['category'][20]['alias']], [false, 'unsupported', 'pages']);
    $maOut = $maUpdate($maEngine, 'MA5', 'category', 20, ['alias' => 'pages']);
    check('alias: a category named at the alias it has is fine', [$maOut['ok'], $maOut['alias']], [true, 'pages']);

    [$maWriter, $maLog, $maEngine] = $maFresh();
    $maOut = $maUpdate($maEngine, 'MA6', 'menuItem', 11, ['alias' => 'tennis', 'title' => 'Tennis']);
    check('alias: a rename and a field write land together, each with its undo',
        [$maOut['ok'], $maOut['alias'], array_column($maLog->entries('MA6'), 'op')], [true, 'tennis', ['menuAlias', 'content']]);
    // A normal route repair on a bound site must not be mistaken for a language operation.
    [$maWriter, $maLog] = $maFresh();
    $maWriter->store['menuItem'][117] = ['alias' => 'contact', 'path' => 'contact', 'parent_id' => 1, 'language' => '*', 'home' => 0, 'client_id' => 0, 'title' => 'Contact us'];
    $maStore = new TestContractStore();
    $maStore->binding = ['contractHash' => 'bound-route-test'];
    $maContract = QuickstartContract::derived($maWriter, $maStore, sys_get_temp_dir(), function (): array {
        throw new RuntimeException('An ordinary route rename must not rebuild the contract');
    });
    $maEngine = new Engine($maToken, [], null, null, null, null, $maWriter, null, $maLog, null, null, null, $maContract);
    $maApply = 'c30-contact-us-route-mwc70hbk-451dad';
    $maOut = $maUpdate($maEngine, $maApply, 'menuItem', 117, ['alias' => 'contact-us']);
    check('alias: a bound-site contact route rename succeeds', [$maOut['ok'], $maWriter->store['menuItem'][117]['path']], [true, 'contact-us']);
    $maBack = $maEngine->handle(['token' => $maToken, 'action' => 'apply.revert', 'params' => ['apply_id' => $maApply]]);
    check('alias: a bound-site ordinary rename can be reverted', $maBack, ['ok' => true, 'reverted' => 1]);
    check('alias: the bound-site route and receipt are restored', [$maWriter->store['menuItem'][117]['alias'], $maWriter->store['menuItem'][117]['path'], $maLog->entries($maApply)], ['contact', 'contact', []]);

    $maOut = $maUpdate($maEngine, 'bound-alias-and-title', 'menuItem', 117, ['alias' => 'contact-us', 'title' => 'Get in touch']);
    check('alias: a bound-site rename can include a title write', [$maOut['ok'], array_column($maLog->entries('bound-alias-and-title'), 'op')], [true, ['menuAlias', 'content']]);
    $maSavedEntries = $maLog->entries('bound-alias-and-title');
    $maWritten = $maWriter->store;
    $maEngine->locks(fn(array $rows): array => ['menuItem:117' => ['userId' => 42, 'name' => 'Editor']]);
    $maLocked = $maEngine->handle(['token' => $maToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'bound-alias-and-title']]);
    check('alias: an open editor refuses the whole ordinary rename undo', [$maLocked['ok'], $maWriter->store, $maLog->entries('bound-alias-and-title')], [false, $maWritten, $maSavedEntries]);
    $maEngine->locks(fn(array $rows): array => []);
    $maBack = $maEngine->handle(['token' => $maToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'bound-alias-and-title']]);
    check('alias: after the editor closes both ordinary changes revert', [$maBack, $maWriter->store['menuItem'][117]['alias'], $maWriter->store['menuItem'][117]['title']], [['ok' => true, 'reverted' => 2], 'contact', 'Contact us']);
    $maOut = $maUpdate($maEngine, 'bound-alias-only-lock', 'menuItem', 117, ['alias' => 'contact-us']);
    $maEngine->locks(fn(array $rows): array => ['menuItem:117' => ['userId' => 42, 'name' => 'Editor']]);
    $maLocked = $maEngine->handle(['token' => $maToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'bound-alias-only-lock']]);
    check('alias: an alias-only receipt still checks the open editor', [$maLocked['ok'], $maWriter->store['menuItem'][117]['alias'], count($maLog->entries('bound-alias-only-lock'))], [false, 'contact-us', 1]);
    $maEngine->locks(fn(array $rows): array => []);

    // Historical language receipts stay behind their own restore operation, even alias-only chunks.
    foreach ([
        [['op' => 'alias', 'kind' => 'menuItem', 'id' => 117, 'before' => 'language-before']],
        [['op' => 'alias', 'kind' => 'menuItem', 'id' => 117, 'before' => 'language-before'], ['op' => 'languageDefaults', 'before' => []]],
        [['op' => 'relabel', 'from' => 'en-GB', 'to' => 'en-US']],
        [['op' => 'menuAlias', 'kind' => 'menuItem', 'id' => 117, 'before' => 'ordinary-before'], ['op' => 'alias', 'kind' => 'menuItem', 'id' => 117, 'before' => 'language-before']],
    ] as $maN => $maLanguageEntries) {
        $maLanguageApply = 'language-guard-' . $maN;
        foreach ($maLanguageEntries as $maEntry) $maLog->record($maLanguageApply, $maEntry);
        $maBefore = $maWriter->store;
        $maRefused = $maEngine->handle(['token' => $maToken, 'action' => 'apply.revert', 'params' => ['apply_id' => $maLanguageApply]]);
        check('alias: generic revert preserves language receipt guard ' . $maN,
            [$maRefused['ok'], $maRefused['error'], $maWriter->store, $maLog->entries($maLanguageApply)], [false, 'bad_params', $maBefore, $maLanguageEntries]);
    }
}
