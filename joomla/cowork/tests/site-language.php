<?php
// Loaded by run.php: a sealed quickstart with no multilingual profile can still be given ONE language —
// its pack installed from the reviewed catalog and made the site's default — and stay sealed.

final class LanguageTestExtensions implements ExtensionManager
{
    public array $installed = [];
    public array $asked = [];
    public function installFromUrl(string $url): array { throw new RuntimeException('unverified install'); }
    public function installVerifiedFromUrl(string $url, string $sha256, int $bytes): array
    {
        $this->asked[] = [$url, $sha256, $bytes];
        $this->installed[] = ['type' => 'language', 'element' => 'vi-VN', 'name' => 'Vietnamese'];
        return ['ok' => true, 'type' => 'package'];
    }
    public function listInstalled(): array { return $this->installed; }
    public function coreManifest(): array { return ['platform' => 'joomla', 'platformVersion' => '6.1.2', 'extensions' => []]; }
    public function setEnabled(string $type, string $element, ?string $folder, bool $enabled): array { return ['ok' => false, 'error' => 'not used']; }
}

$langRoot = sys_get_temp_dir() . '/cowork-site-language-' . bin2hex(random_bytes(6));
$langDir = $langRoot . '/lib/contracts/lang/j6/1.0.0';
mkdir($langDir, 0777, true);
$langStore = new TestContractStore();
$langLog = new FakeApplyLog();
$langWriter = new ContractTestWriter($langLog, $langStore);
$langHero = ['title' => 'Home hero', 'module' => 'mod_custom', 'position' => 'masthead', 'published' => '1', 'access' => '1', 'language' => '*', 'client_id' => '0',
    'content' => '<h1>Demo title</h1>', 'params' => '{"style":"0"}'];
$langWriter->store['module'][110] = ['id' => '110'] + $langHero;
$langWriter->store['moduleAssignment'][110] = ['menuids' => '[0]'];
$langWriter->store['languageDefaults'] = ['site' => 'en-GB', 'administrator' => 'en-GB'];
$langSlots = [];
foreach (ContentSlots::htmlSlots($langHero['content']) as $n => $s) $langSlots[] = ['key' => 'hero.' . $n, 'entity' => 'hero', 'column' => 'content', 'maxCharacters' => 80, 'sample' => 'Demo title'] + $s;
foreach ([
    'manifest' => ['id' => 'lang/j6/1.0.0'],
    'content-map' => ['entities' => [['key' => 'hero', 'kind' => 'module', 'sourceId' => 10, 'identity' => ['title' => 'Home hero']]], 'slots' => $langSlots, 'pages' => []],
    'presentation-lock' => ['entities' => ['hero' => $langHero], 'assignments' => [['moduleid' => 10, 'menuid' => 0]], 'fileRoots' => [], 'files' => [],
        'inventoryCounts' => ['module' => 1], 'access' => $langStore->acl],
] as $name => $body) file_put_contents($langDir . '/' . $name . '.json', json_encode($body));
file_put_contents($langRoot . '/lib/language-packs.json', json_encode([
    'schemaVersion' => 'tracy-joomla-language-packs/v1',
    'packs' => ['vi-VN' => ['tag' => 'vi-VN', 'name' => 'Vietnamese', 'version' => '4.2.2.1', 'url' => 'https://downloads.joomla.org/vi.zip',
        'sha256' => str_repeat('c', 64), 'bytes' => 754892, 'platformMajors' => [4, 6]]],
]));

$langExtensions = new LanguageTestExtensions();
$langContract = new QuickstartContract($langWriter, $langStore, $langDir, $langDir);
$langEngine = new Engine($WTOKEN, ['joomla' => '6.1.2'], null, null, null, $langExtensions, $langWriter, null, $langLog, null, null, null, $langContract);
$langCall = fn(array $params) => $langEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);

checkTrue('a contract with no multilingual profile still offers one site language', $langContract->siteLanguageAvailable());
checkTrue('and it is not mistaken for a multilingual contract', !$langContract->multilingualAvailable());
$plan = $langCall(['operation' => 'siteLanguage.plan', 'locale' => 'vi-VN']);
check('plan names the current default and the pack it would install', [$plan['ok'], $plan['current']['site'], $plan['package']['version'], $plan['installed']], [true, 'en-GB', '4.2.2.1', false]);
check('a locale outside the reviewed catalog is refused', $langCall(['operation' => 'siteLanguage.plan', 'locale' => 'xx-XX'])['ok'], false);
check('a request that names its own archive is refused', $langCall(['operation' => 'siteLanguage.set', 'locale' => 'vi-VN', 'apply_id' => 'slang-a', 'request_id' => 'r1', 'url' => 'https://evil.example/x.zip'])['error'], 'bad_params');
check('an apply without the slang- prefix is refused', $langCall(['operation' => 'siteLanguage.set', 'locale' => 'vi-VN', 'apply_id' => 'contract-a', 'request_id' => 'r1'])['error'], 'bad_params');

$langStore->binding = ['multilingual' => ['languages' => ['fr-FR' => ['ids' => []]]]];
check('a site that already has a second edition is not given a new default', $langCall(['operation' => 'siteLanguage.set', 'locale' => 'vi-VN', 'apply_id' => 'slang-a', 'request_id' => 'r1'])['error'], 'contract_failed');
$langStore->binding = null;

$set = $langCall(['operation' => 'siteLanguage.set', 'locale' => 'vi-VN', 'apply_id' => 'slang-a', 'request_id' => 'r1']);
check('the pack is installed and becomes the default, site and administrator', [$set['ok'], $set['locale'], $langWriter->store['languageDefaults']], [true, 'vi-VN', ['site' => 'vi-VN', 'administrator' => 'vi-VN']]);
check('the pack came from the reviewed catalog, verified', $langExtensions->asked, [['https://downloads.joomla.org/vi.zip', str_repeat('c', 64), 754892]]);
check('the binding records what the default was, so it can go back', [$langStore->binding['siteLanguage']['locale'], $langStore->binding['siteLanguage']['previous']], ['vi-VN', ['site' => 'en-GB', 'administrator' => 'en-GB']]);
check('the site still inspects clean', $langContract->inspect()['contract'], 'lang/j6/1.0.0');
check('asking again installs nothing twice', [$langCall(['operation' => 'siteLanguage.set', 'locale' => 'vi-VN', 'apply_id' => 'slang-a', 'request_id' => 'r1'])['ok'], count($langExtensions->asked)], [true, 1]);
check('a second language on top is refused, not stacked', $langCall(['operation' => 'siteLanguage.set', 'locale' => 'fr-FR', 'apply_id' => 'slang-b', 'request_id' => 'r2'])['error'], 'conflict');

$copy = $langCall(['operation' => 'apply', 'apply_id' => 'contract-l1', 'request_id' => 'l1', 'expected_revision' => $langContract->inspect()['revision'], 'changes' => ['hero.0' => 'Tôn lợp Hà Nội']]);
check('a content apply after it keeps the language on record', [$copy['ok'], $langStore->binding['siteLanguage']['locale'] ?? null], [true, 'vi-VN']);
check('the generic revert does not take the language apart', $langEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'slang-a']])['error'], 'bad_params');

$back = $langCall(['operation' => 'siteLanguage.revert', 'apply_id' => 'slang-undo', 'request_id' => 'u1']);
check('revert restores the previous defaults and drops the record', [$back['ok'], $langWriter->store['languageDefaults'], isset($langStore->binding['siteLanguage'])], [true, ['site' => 'en-GB', 'administrator' => 'en-GB'], false]);
check('the reverted site inspects clean', $langContract->inspect()['contract'], 'lang/j6/1.0.0');
