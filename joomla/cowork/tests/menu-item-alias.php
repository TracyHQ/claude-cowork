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
    check('alias: the rename is recorded as the alias undo a language step uses', $maLog->entries('MA1'),
        [['op' => 'alias', 'kind' => 'menuItem', 'id' => 11, 'before' => 'group-boxing']]);
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
        [$maOut['ok'], $maOut['alias'], array_column($maLog->entries('MA6'), 'op')], [true, 'tennis', ['alias', 'content']]);
}
