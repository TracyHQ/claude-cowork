<?php
// Loaded by run.php after demo-trim.php, site-language.php and multilingual-contracts.php (reuses
// their fixtures and the gate's site builder). content-locks.php proves `apply`, the open surface
// and apply.revert never write under an open editor; this proves the same for every OTHER operation
// behind the contract door that writes rows: demoTrim.apply/revert, multilingual.retire/restore,
// multilingual.apply (per phase), multilingual.revert and sourceLanguage.set/revert. A refusal is
// SLOT_LOCKED_BY_USER, whole, before the first write — and once the editor closes, the same request
// goes through as it always did.

$opJane = ['kind' => 'admin-user', 'name' => 'Jane Admin', 'since' => '2026-09-26T10:00:00Z', 'until' => null];
$opHeld = [];
$opLockOf = function (array $targets) use (&$opHeld): array {
    $out = [];
    foreach ($targets as [$kind, $id]) if (isset($opHeld[$kind . ':' . $id])) $out[$kind . ':' . $id] = $opHeld[$kind . ':' . $id];
    return $out;
};
/** The first refusal's parts an agent acts on: code, who, which record, which content, and the job phase. */
$opWhy = fn (array $answer): array => [$answer['ok'] ?? null, $answer['errors'][0]['code'] ?? null, $answer['errors'][0]['lockedBy'] ?? null,
    $answer['errors'][0]['record'] ?? null, $answer['errors'][0]['field']['contentId'] ?? null, $answer['errors'][0]['phase'] ?? null];

/* ------------------------------------------------------------------ demoTrim.apply / revert */
$opTrim = (new Engine($WTOKEN, [], null, null, null, null, $trimWriter, null, $trimLog, null, null, null, $trimContract))
    ->locks($opLockOf)->contentRevisions(fn () => ['revisions' => [], 'owners' => ['article-old' => 'content-old']]);
$opTrimCall = fn (array $params) => $opTrim->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);
$opTrimSite = fn () => [$trimWriter->store, $trimLog->log, $trimStore->binding];

$opHeld = ['article:203' => $opJane];
$opBefore = $opTrimSite();
$opR = $opTrimCall(['operation' => 'demoTrim.apply', 'apply_id' => 'dtrim-lock', 'request_id' => 'lock-1']);
check('demoTrim.apply: a demo row open in the editor refuses the trim', $opWhy($opR),
    [false, 'SLOT_LOCKED_BY_USER', $opJane, ['kind' => 'article', 'id' => 203], 'content-old', null]);
check('demoTrim.apply: the refusal says who has which record open', $opR['message'],
    '"An older post" is open in the Joomla editor by Jane Admin since 2026-09-26T10:00:00Z: ask them to save and close it, then try again.');
check('demoTrim.apply: nothing is written, not even the record that a trim began', $opTrimSite(), $opBefore);

// A trim already in flight (its record landed, its batch died) meets an editor on resume.
$opHeld = [];
$trimWriter->failOn = ['article', 203];
$opTrimCall(['operation' => 'demoTrim.apply', 'apply_id' => 'dtrim-lock', 'request_id' => 'lock-1']);
$trimWriter->failOn = null;
check('demoTrim.apply: (a crashed batch leaves a trim in flight)', $trimStore->binding['demoTrim']['status'] ?? null, 'applying');
$opHeld = ['menuItem:330' => $opJane];
$opBefore = $opTrimSite();
check('demoTrim.apply: resuming under an open editor is refused too', $opWhy($opTrimCall(['operation' => 'demoTrim.apply', 'apply_id' => 'dtrim-lock', 'request_id' => 'lock-1']))[1], 'SLOT_LOCKED_BY_USER');
check('demoTrim.apply: and leaves the trim in flight, exactly as it was', $opTrimSite(), $opBefore);
$opHeld = [];
$opR = $opTrimCall(['operation' => 'demoTrim.apply', 'apply_id' => 'dtrim-lock', 'request_id' => 'lock-1']);
check('demoTrim.apply: once the editor closes, the same request completes', [$opR['ok'], $opR['status'], $trimWriter->store['article'][203]['state'], $trimWriter->store['menuItem'][330]['published']],
    [true, 'completed', '0', '0']);

$opHeld = ['article:202' => $opJane];
$opBefore = $opTrimSite();
check('demoTrim.revert: a hidden row open in the editor refuses the revert', $opWhy($opTrimCall(['operation' => 'demoTrim.revert', 'apply_id' => 'dtrim-lock-undo', 'request_id' => 'lock-u1']))[1], 'SLOT_LOCKED_BY_USER');
check('demoTrim.revert: and writes nothing', $opTrimSite(), $opBefore);
$opHeld = [];
$opR = $opTrimCall(['operation' => 'demoTrim.revert', 'apply_id' => 'dtrim-lock-undo', 'request_id' => 'lock-u1']);
check('demoTrim.revert: once closed it brings the rows back', [$opR['ok'], $opR['status'], $trimWriter->store['article'][202]['state']], [true, 'reverted', '1']);

/* ---------------------------------------------------------------------------- siteLanguage */
// Only com_languages' params in #__extensions, which the editor never checks out: an open article
// or module stands in its way no more than it did before.
$opAsked = 0;
$opLang = (new Engine($WTOKEN, ['joomla' => '6.1.2'], null, null, null, $langExtensions, $langWriter, null, $langLog, null, null, null, $langContract))
    ->locks(function (array $targets) use (&$opAsked, $opLockOf): array { $opAsked++; return $opLockOf($targets); });
$opHeld = ['module:110' => $opJane];
$opR = $opLang->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'siteLanguage.set', 'locale' => 'vi-VN', 'apply_id' => 'slang-lock', 'request_id' => 'lock-s1']]);
check('siteLanguage.set: writes no record an editor holds, so an open module does not stop it', [$opR['ok'], $langWriter->store['languageDefaults']['site'], $opAsked], [true, 'vi-VN', 0]);
check('siteLanguage.revert: nor its way back', $opLang->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'siteLanguage.revert', 'apply_id' => 'slang-lock-undo', 'request_id' => 'lock-s2']])['ok'], true);
$opHeld = [];

/* ------------------------------------ the gate's archives: relabel, retire, restore, language, revert */
foreach (['tracy-business/j6/1.2.0', 'tracy-apple/j6/1.3.0'] as $opId) {
    $opDir = gateContractCopy(__DIR__ . '/../lib/contracts/' . $opId);
    $opStore = new GateContractStore();
    $opLog = new FakeApplyLog();
    $opSite = gateSite($opDir, $opStore, $opLog);
    $opExt = new FakeExtensions();
    $opExt->installed = [['type' => 'language', 'element' => 'vi-VN'], ['type' => 'language', 'element' => 'fr-FR'], ['type' => 'language', 'element' => 'en-US']];
    $opContract = new QuickstartContract($opSite, $opStore, $opDir, $opDir);
    $opMap = json_decode(file_get_contents($opDir . '/content-map.json'), true);
    $opOwners = [];
    foreach ($opMap['entities'] as $entity) $opOwners[$entity['key']] = 'content:' . $entity['key'];
    $opEngine = (new Engine($WTOKEN, ['joomla' => '6.1.1'], null, null, null, $opExt, $opSite, null, $opLog, null, null, null, $opContract))
        ->locks($opLockOf)->contentRevisions(fn () => ['revisions' => [], 'owners' => $opOwners]);
    $opCall = fn (array $params) => $opEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);
    $opState = fn () => [$opSite->store, $opSite->groups, $opLog->log, $opStore->binding, $opStore->job];
    $opLoop = function (array $params) use ($opCall): array {
        for ($i = 0, $r = ['ok' => true, 'status' => 'running']; $i < 400 && ($r['ok'] ?? false) && ($r['status'] ?? '') === 'running'; $i++) $r = $opCall($params);
        return $r;
    };
    $opFirst = function (string $kind, callable $want) use ($opSite): int {
        foreach ($opSite->store[$kind] ?? [] as $id => $row) if ($want($row, (int) $id)) return (int) $id;
        return 0;
    };
    check("$opId: bound", $opCall(['operation' => 'bind'])['ok'] ?? null, true);

    // sourceLanguage.set rewrites the tag of every en-GB row: one open is enough to refuse it. An
    // archive with none (Apple: every row at `*`) relabels only its content language, which no
    // editor holds, so an open `*` row does not stand in its way.
    $opKey = null;
    foreach ($opMap['entities'] as $entity)
        if (($opSite->store[$entity['kind']][(int) $entity['sourceId']]['language'] ?? '') === 'en-GB' && isset(JoomlaLocks::TABLES[$entity['kind']])) { $opKey = $entity; break; }
    $opTagged = $opKey !== null;
    if (!$opTagged) foreach ($opMap['entities'] as $entity) if (isset(JoomlaLocks::TABLES[$entity['kind']])) { $opKey = $entity; break; }
    $opHeld = [$opKey['kind'] . ':' . $opKey['sourceId'] => $opJane];
    $opBefore = $opState();
    $opR = $opCall(['operation' => 'sourceLanguage.set', 'locale' => 'en-US', 'apply_id' => 'srclang-lock', 'request_id' => 'lock-r1']);
    if ($opTagged) {
        check("$opId: sourceLanguage.set: an en-GB record open in the editor refuses the relabel", $opWhy($opR),
            [false, 'SLOT_LOCKED_BY_USER', $opJane, ['kind' => $opKey['kind'], 'id' => (int) $opKey['sourceId']], 'content:' . $opKey['key'], null]);
        check("$opId: sourceLanguage.set: and relabels nothing", $opState(), $opBefore);
        $opHeld = [];
        $opR = $opCall(['operation' => 'sourceLanguage.set', 'locale' => 'en-US', 'apply_id' => 'srclang-lock', 'request_id' => 'lock-r1']);
        check("$opId: sourceLanguage.set: once closed the relabel goes through", [$opR['ok'] ?? null, $opSite->store[$opKey['kind']][(int) $opKey['sourceId']]['language']], [true, 'en-US']);
    } else check("$opId: sourceLanguage.set: a record it does not rewrite, open, does not stop it", [$opR['ok'] ?? null, $opContract->sourceLanguage()], [true, 'en-US']);
    $opHeld = [];

    // multilingual.retire hides the other editions: one of them open refuses the pass.
    $opPad = $opFirst('article', fn ($row) => ($row['language'] ?? '') === 'de-DE' && (string) ($row['state'] ?? '') === '1');
    $opRetire = ['operation' => 'multilingual.retire', 'keep' => ['en-US', 'vi-VN'], 'apply_id' => 'mlang-lock-retire'];
    $opHeld = ['article:' . $opPad => $opJane];
    $opBefore = $opState();
    check("$opId: multilingual.retire: an edition open in the editor refuses the pass", $opWhy($opCall($opRetire)),
        [false, 'SLOT_LOCKED_BY_USER', $opJane, ['kind' => 'article', 'id' => $opPad], null, null]);
    check("$opId: multilingual.retire: and hides nothing", $opState(), $opBefore);
    $opHeld = [];
    $opR = $opLoop($opRetire);
    check("$opId: multilingual.retire: once closed the pass completes", [$opR['status'] ?? $opR['message'], $opSite->store['article'][$opPad]['state']], ['completed', '0']);

    // multilingual.restore shows them again through the retire's log.
    $opHeld = ['article:' . $opPad => $opJane];
    $opBefore = $opState();
    $opR = $opCall(['operation' => 'multilingual.restore', 'apply_id' => 'mlang-lock-retire']);
    check("$opId: multilingual.restore: a hidden row open in the editor refuses the restore", [$opWhy($opR)[1], $opWhy($opR)[3]], ['SLOT_LOCKED_BY_USER', ['kind' => 'article', 'id' => $opPad]]);
    check("$opId: multilingual.restore: and shows nothing", $opState(), $opBefore);
    $opHeld = [];
    check("$opId: multilingual.restore: once closed it restores the pass", [$opCall(['operation' => 'multilingual.restore', 'apply_id' => 'mlang-lock-retire'])['ok'] ?? null, $opSite->store['article'][$opPad]['state']], [true, '1']);
    check("$opId: (retired again)", $opLoop(['apply_id' => 'mlang-lock-retire2'] + $opRetire)['status'] ?? null, 'completed');

    // multilingual.apply: refused at the phase that would write the open record, the job left resumable.
    $opEditions = is_file($opDir . '/editions.json') ? json_decode(file_get_contents($opDir . '/editions.json'), true) : null;
    if ($opEditions !== null) {
        // A taken edition's menu row is written in the `menu` phase.
        $opLocked = null;
        foreach ($opEditions['locales']['vi-VN']['ids'] as $key => $eid) if (str_starts_with($key, 'menuItem-')) { $opLocked = ['menuItem', (int) $eid, null, 'menu']; break; }
    } else {
        // A translated source row still at `*` is moved off it in the `prepare` phase.
        $opLocked = null;
        foreach ($opMap['entities'] as $entity)
            if ($opContract->profile()->isTranslated($entity['key']) && ($opSite->store[$entity['kind']][(int) $entity['sourceId']]['language'] ?? '') === '*'
                && isset(JoomlaLocks::TABLES[$entity['kind']])) { $opLocked = [$entity['kind'], (int) $entity['sourceId'], 'content:' . $entity['key'], 'prepare']; break; }
    }
    checkTrue("$opId: (a row the language writes was found to hold open)", $opLocked !== null);
    $opPlan = $opCall(['operation' => 'multilingual.plan', 'locale' => 'vi-VN']);
    $opWords = [];
    foreach ($opPlan['slots'] as $slot) $opWords[$slot['key']] = (string) ($slot['source'] ?? '');
    $opApply = ['operation' => 'multilingual.apply', 'locale' => 'vi-VN', 'translations' => $opWords,
        'expected_revision' => $opPlan['revision'], 'apply_id' => 'mlang-lock-vi', 'request_id' => 'lock-vi'];
    $opHeld = [$opLocked[0] . ':' . $opLocked[1] => $opJane];
    for ($i = 0, $opR = ['ok' => true, 'status' => 'running']; $i < 400; $i++) {
        $opBefore = $opState();
        $opR = $opCall($opApply);
        if (!($opR['ok'] ?? false) || ($opR['status'] ?? '') !== 'running') break;
    }
    check("$opId: multilingual.apply: the phase that would write the open record refuses", $opWhy($opR),
        [false, 'SLOT_LOCKED_BY_USER', $opJane, ['kind' => $opLocked[0], 'id' => $opLocked[1]], $opLocked[2], $opLocked[3]]);
    check("$opId: multilingual.apply: that call writes nothing, and the job stays at its phase", $opState(), $opBefore);
    check("$opId: multilingual.apply: the job is resumable, not dropped", $opLocked[3] === 'prepare' ? $opStore->job : ($opStore->job['phase'] ?? null),
        $opLocked[3] === 'prepare' ? null : $opLocked[3]);
    check("$opId: multilingual.apply: asking again while it is open is the same refusal", [$opWhy($opCall($opApply))[1], $opState()], ['SLOT_LOCKED_BY_USER', $opBefore]);
    $opHeld = [];
    $opR = $opLoop($opApply);
    check("$opId: multilingual.apply: once closed the same request finishes the language", [$opR['status'] ?? $opR['message'], $opStore->job], ['completed', null]);

    // multilingual.revert replays the language's log: a row it would put back, open, refuses it.
    $opUndo = null;
    foreach ($opLog->entries('mlang-lock-vi') as $entry)
        if (in_array($entry['op'] ?? '', ['content', 'alias', 'visibility'], true) && isset(JoomlaLocks::TABLES[$entry['kind'] ?? '']) && (int) ($entry['id'] ?? 0) > 0) { $opUndo = [$entry['kind'], (int) $entry['id']]; break; }
    $opHeld = [$opUndo[0] . ':' . $opUndo[1] => $opJane];
    $opBefore = $opState();
    $opR = $opCall(['operation' => 'multilingual.revert', 'locale' => 'vi-VN']);
    check("$opId: multilingual.revert: a record the revert would write, open, refuses it", [$opWhy($opR)[1], $opWhy($opR)[3]], ['SLOT_LOCKED_BY_USER', ['kind' => $opUndo[0], 'id' => $opUndo[1]]]);
    check("$opId: multilingual.revert: and takes nothing back", $opState(), $opBefore);
    $opHeld = [];
    check("$opId: multilingual.revert: once closed the language comes out", [$opCall(['operation' => 'multilingual.revert', 'locale' => 'vi-VN'])['ok'] ?? null, $opContract->derivedLanguages()], [true, []]);

    // sourceLanguage.revert gives every en-US row its published tag back.
    $opHeld = [$opKey['kind'] . ':' . $opKey['sourceId'] => $opJane];
    $opBefore = $opState();
    $opR = $opCall(['operation' => 'sourceLanguage.revert', 'apply_id' => 'srclang-lock-undo', 'request_id' => 'lock-r2']);
    if ($opTagged) {
        check("$opId: sourceLanguage.revert: an open record refuses the way back too", $opWhy($opR)[1], 'SLOT_LOCKED_BY_USER');
        check("$opId: sourceLanguage.revert: and relabels nothing", $opState(), $opBefore);
        $opHeld = [];
        $opR = $opCall(['operation' => 'sourceLanguage.revert', 'apply_id' => 'srclang-lock-undo', 'request_id' => 'lock-r2']);
        check("$opId: sourceLanguage.revert: once closed the source is en-GB again", [$opR['ok'] ?? null, $opSite->store[$opKey['kind']][(int) $opKey['sourceId']]['language']], [true, 'en-GB']);
    } else check("$opId: sourceLanguage.revert: nor its way back", [$opR['ok'] ?? null, $opContract->sourceLanguage()], [true, 'en-GB']);
}
$opHeld = [];
