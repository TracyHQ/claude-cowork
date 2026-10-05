<?php
/**
 * `multilingual.retire` and the site's default language (com_languages' `site` param).
 *
 * 🔒 THE LANGUAGE `/` ROUTES TO IS NEVER HIDDEN. Joomla's language filter loads only the PUBLISHED
 * content languages; when the default one is not among them, `/` redirects to `//` forever
 * (`LanguageFilter.php:467`, "Undefined array key ru-RU") and the browser gives up — the site is
 * dead while the retire receipt says `completed`. Measured 28/09/2026 on `r1j1734`: built in ru-RU,
 * then asked to keep en-GB + es-ES. A retire whose keep list leaves the default out moves the
 * default to a language that stays published, records the old one under the same apply id, and
 * `multilingual.restore` brings it back with the rows.
 */
declare(strict_types=1);

foreach (['tracy-business/j6/1.2.0'] as $rdId) {
    $rdDir = gateContractCopy(__DIR__ . '/../lib/contracts/' . $rdId);
    $rdStore = new GateContractStore();
    $rdLog = new FakeApplyLog();
    $rdSite = gateSite($rdDir, $rdStore, $rdLog);
    $rdContract = new QuickstartContract($rdSite, $rdStore, $rdDir, $rdDir);
    $rdEngine = new Engine($WTOKEN, ['joomla' => '6.1.1'], null, null, null, new FakeExtensions(), $rdSite, null, $rdLog, null, null, null, $rdContract);
    $rdCall = fn (array $params) => $rdEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);
    $rdLoop = function (array $params) use ($rdCall): array {
        for ($i = 0, $r = ['ok' => true, 'status' => 'running']; $i < 400 && ($r['ok'] ?? false) && ($r['status'] ?? '') === 'running'; $i++) $r = $rdCall($params);
        return $r;
    };
    $rdDefaults = fn () => $rdSite->store['languageDefaults'] ?? ['site' => 'en-GB', 'administrator' => 'en-GB'];
    $rdEntries = fn (string $apply) => array_values(array_filter($rdLog->entries($apply), fn ($e) => ($e['op'] ?? '') === 'languageDefaults'));
    check("$rdId: bound", $rdCall(['operation' => 'bind'])['ok'] ?? null, true);

    // The site was built in de-DE: `/` routes to the archive's German edition.
    $rdSite->store['languageDefaults'] = ['site' => 'de-DE', 'administrator' => 'en-GB'];
    $rdR = $rdLoop(['operation' => 'multilingual.retire', 'keep' => ['en-GB'], 'apply_id' => 'mlang-rd-1']);
    check("$rdId: retire completes", $rdR['status'] ?? $rdR['message'], 'completed');
    check("$rdId: de-DE is hidden", (int) $rdSite->store['language'][2]['published'], 0);
    check("$rdId: the default language moves to one that stays published, admin untouched", $rdDefaults(), ['site' => 'en-GB', 'administrator' => 'en-GB']);
    check("$rdId: the receipt says so", $rdR['siteDefault'] ?? null, ['from' => 'de-DE', 'to' => 'en-GB']);
    check("$rdId: the old default is recorded once under the retire's apply id, before any row is hidden",
        [count($rdEntries('mlang-rd-1')), $rdEntries('mlang-rd-1')[0]['before'] ?? null, ($rdLog->entries('mlang-rd-1')[0]['op'] ?? null)],
        [1, ['site' => 'de-DE', 'administrator' => 'en-GB'], 'languageDefaults']);

    // restore brings the rows AND the default back.
    $rdR = $rdCall(['operation' => 'multilingual.restore', 'apply_id' => 'mlang-rd-1']);
    check("$rdId: restore is accepted", $rdR['ok'] ?? null, true);
    check("$rdId: restore shows de-DE again", (int) $rdSite->store['language'][2]['published'], 1);
    check("$rdId: restore puts the default back", $rdDefaults(), ['site' => 'de-DE', 'administrator' => 'en-GB']);

    // A default the keep list holds is left alone: nothing recorded, nothing in the receipt.
    $rdSite->store['languageDefaults'] = ['site' => 'en-GB', 'administrator' => 'en-GB'];
    $rdR = $rdLoop(['operation' => 'multilingual.retire', 'keep' => ['en-GB'], 'apply_id' => 'mlang-rd-2']);
    check("$rdId: a kept default is not moved", [$rdR['status'] ?? $rdR['message'], $rdDefaults(), (array_key_exists('siteDefault', $rdR) ? $rdR['siteDefault'] : 'absent'), count($rdEntries('mlang-rd-2'))],
        ['completed', ['site' => 'en-GB', 'administrator' => 'en-GB'], null, 0]);
    $rdCall(['operation' => 'multilingual.restore', 'apply_id' => 'mlang-rd-2']);

    // The customer's order decides where `/` goes, among the languages whose ROW stays published:
    // vi-VN is named first, and the archive ships an edition of it, but its row is unpublished and a
    // spared edition is kept as shipped — routing `/` to it would be the same dead site. The source is next.
    $rdSite->store['languageDefaults'] = ['site' => 'de-DE', 'administrator' => 'en-GB'];
    $rdR = $rdLoop(['operation' => 'multilingual.retire', 'keep' => ['vi-VN', 'en-GB'], 'apply_id' => 'mlang-rd-3']);
    check("$rdId: a kept language whose row is not published is not chosen as default", [$rdR['status'] ?? $rdR['message'], $rdDefaults()['site']], ['completed', 'en-GB']);
    $rdCall(['operation' => 'multilingual.restore', 'apply_id' => 'mlang-rd-3']);
}
