<?php
// Loaded by run.php: a sealed quickstart's source edition can be CALLED by another tag of the same
// language (en-GB → en-US) — relabelled in place, not copied — and stay sealed, and go back.

final class RelabelTestExtensions implements ExtensionManager
{
    public array $installed = [];
    public array $asked = [];
    public ?ContractTestWriter $writer = null;
    public function installFromUrl(string $url): array { throw new RuntimeException('unverified install'); }
    public function installVerifiedFromUrl(string $url, string $sha256, int $bytes): array
    {
        $this->asked[] = $url;
        $this->installed[] = ['type' => 'language', 'element' => 'en-US', 'name' => 'English (United States)'];
        // What Joomla's installer does on its own: a content language for the new pack, at a
        // unique `sef` because `en` is the source's.
        $this->writer->store['language'][9] = ['lang_id' => '9', 'lang_code' => 'en-US', 'sef' => 'en-us', 'published' => '1',
            'title' => 'English (United States)', 'title_native' => 'English (United States)', 'image' => 'en_us'];
        return ['ok' => true, 'type' => 'package'];
    }
    public function listInstalled(): array { return $this->installed; }
    public function coreManifest(): array { return ['platform' => 'joomla', 'platformVersion' => '6.1.2', 'extensions' => []]; }
    public function setEnabled(string $type, string $element, ?string $folder, bool $enabled): array { return ['ok' => false, 'error' => 'not used']; }
}

$relRoot = sys_get_temp_dir() . '/cowork-source-language-' . bin2hex(random_bytes(6));
$relDir = $relRoot . '/lib/contracts/rel/j6/1.0.0';
mkdir($relDir, 0777, true);
$relStore = new TestContractStore();
$relLog = new FakeApplyLog();
$relWriter = new ContractTestWriter($relLog, $relStore);
$relHero = ['title' => 'Home hero', 'module' => 'mod_custom', 'position' => 'masthead', 'published' => '1', 'access' => '1', 'language' => 'en-GB', 'client_id' => '0',
    'content' => '<h1>Demo title</h1>', 'params' => '{"style":"0"}'];
$relFooter = ['title' => 'Footer', 'module' => 'mod_custom', 'position' => 'footer', 'published' => '1', 'access' => '1', 'language' => '*', 'client_id' => '0',
    'content' => '<p>Everywhere</p>', 'params' => '{"style":"0"}'];
$relWriter->store['module'][110] = ['id' => '110'] + $relHero;
$relWriter->store['module'][111] = ['id' => '111'] + $relFooter;
$relWriter->store['moduleAssignment'][110] = ['menuids' => '[0]'];
$relWriter->store['moduleAssignment'][111] = ['menuids' => '[0]'];
$relWriter->store['language'][1] = ['lang_id' => '1', 'lang_code' => 'en-GB', 'sef' => 'en', 'published' => '1',
    'title' => 'English (en-GB)', 'title_native' => 'English (United Kingdom)', 'image' => 'en_gb'];
$relWriter->store['languageDefaults'] = ['site' => 'en-GB', 'administrator' => 'en-GB'];
$relSlots = [];
foreach (ContentSlots::htmlSlots($relHero['content']) as $n => $s) $relSlots[] = ['key' => 'hero.' . $n, 'entity' => 'hero', 'column' => 'content', 'maxCharacters' => 80, 'sample' => 'Demo title'] + $s;
foreach ([
    'manifest' => ['id' => 'rel/j6/1.0.0'],
    'content-map' => ['entities' => [
        ['key' => 'hero', 'kind' => 'module', 'sourceId' => 10, 'identity' => ['title' => 'Home hero']],
        ['key' => 'footer', 'kind' => 'module', 'sourceId' => 11, 'identity' => ['title' => 'Footer']],
    ], 'slots' => $relSlots, 'pages' => []],
    'presentation-lock' => ['entities' => ['hero' => $relHero, 'footer' => $relFooter],
        'assignments' => [['moduleid' => 10, 'menuid' => 0], ['moduleid' => 11, 'menuid' => 0]], 'fileRoots' => [], 'files' => [],
        'inventoryCounts' => ['module' => 2], 'access' => $relStore->acl],
] as $name => $body) file_put_contents($relDir . '/' . $name . '.json', json_encode($body));
file_put_contents($relRoot . '/lib/language-packs.json', json_encode([
    'schemaVersion' => 'tracy-joomla-language-packs/v1',
    'packs' => ['en-US' => ['tag' => 'en-US', 'name' => 'English (United States)', 'version' => '6.1.2.1', 'url' => 'https://downloads.joomla.org/en-us.zip',
        'sha256' => str_repeat('d', 64), 'bytes' => 700000, 'platformMajors' => [4, 6]],
        'vi-VN' => ['tag' => 'vi-VN', 'name' => 'Vietnamese', 'version' => '4.2.2.1', 'url' => 'https://downloads.joomla.org/vi.zip',
        'sha256' => str_repeat('c', 64), 'bytes' => 754892, 'platformMajors' => [4, 6]]],
]));

$relExtensions = new RelabelTestExtensions();
$relExtensions->writer = $relWriter;
$relContract = new QuickstartContract($relWriter, $relStore, $relDir, $relDir);
$relEngine = new Engine($WTOKEN, ['joomla' => '6.1.2'], null, null, null, $relExtensions, $relWriter, null, $relLog, null, null, null, $relContract);
$relCall = fn(array $params) => $relEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);

$plan = $relCall(['operation' => 'sourceLanguage.plan', 'locale' => 'en-US']);
check('plan names the published source and the pack it would install', [$plan['ok'], $plan['published'], $plan['current'], $plan['package']['version'], $plan['installed']], [true, 'en-GB', 'en-GB', '6.1.2.1', false]);
check('another language is not a relabel', $relCall(['operation' => 'sourceLanguage.plan', 'locale' => 'vi-VN'])['error'], 'bad_params');
check('another language is not set as one either', $relCall(['operation' => 'sourceLanguage.set', 'locale' => 'vi-VN', 'apply_id' => 'srclang-a', 'request_id' => 'r1'])['error'], 'bad_params');
check('an apply without the srclang- prefix is refused', $relCall(['operation' => 'sourceLanguage.set', 'locale' => 'en-US', 'apply_id' => 'slang-a', 'request_id' => 'r1'])['error'], 'bad_params');
check('nothing to take back on a site never relabelled', $relCall(['operation' => 'sourceLanguage.revert', 'apply_id' => 'srclang-u', 'request_id' => 'u0'])['error'], 'contract_failed');

$set = $relCall(['operation' => 'sourceLanguage.set', 'locale' => 'en-US', 'apply_id' => 'srclang-a', 'request_id' => 'r1']);
check('the relabel completes, with the pack from the reviewed catalog', [$set['ok'], $set['source'], $relExtensions->asked], [true, 'en-US', ['https://downloads.joomla.org/en-us.zip']]);
check('the source row is relabelled; the everywhere row is not', [$relWriter->store['module'][110]['language'], $relWriter->store['module'][111]['language']], ['en-US', '*']);
check('the content language keeps its sef and id, takes the pack\'s label, and the installer\'s extra row is gone',
    [array_keys($relWriter->store['language']), $relWriter->store['language'][1]['lang_code'], $relWriter->store['language'][1]['sef'], $relWriter->store['language'][1]['title_native']],
    [[1], 'en-US', 'en', 'English (United States)']);
check('site and administrator defaults follow', $relWriter->store['languageDefaults'], ['site' => 'en-US', 'administrator' => 'en-US']);
check('the binding records the relabel and the label it replaced', [$relStore->binding['sourceRelabel']['from'], $relStore->binding['sourceRelabel']['to'], $relStore->binding['sourceRelabel']['label']['title']], ['en-GB', 'en-US', 'English (en-GB)']);
check('the contract now names the source by its new tag', [$relContract->sourceLanguage(), $relContract->publishedSourceLanguage()], ['en-US', 'en-GB']);
check('the relabelled site inspects clean', $relContract->inspect()['contract'], 'rel/j6/1.0.0');
$again = new QuickstartContract($relWriter, $relStore, $relDir, $relDir);
check('a fresh receiver reads the relabel back from the binding', [$again->sourceLanguage(), $again->inspect()['contract']], ['en-US', 'rel/j6/1.0.0']);
check('asking again installs nothing twice', [$relCall(['operation' => 'sourceLanguage.set', 'locale' => 'en-US', 'apply_id' => 'srclang-a', 'request_id' => 'r1'])['alreadySet'] ?? null, count($relExtensions->asked)], [true, 1]);
check('a second variant on top is refused, not stacked', $relCall(['operation' => 'sourceLanguage.set', 'locale' => 'en-AU', 'apply_id' => 'srclang-b', 'request_id' => 'r2'])['error'], 'conflict');

$copy = $relCall(['operation' => 'apply', 'apply_id' => 'contract-r1', 'request_id' => 'c1', 'expected_revision' => $relContract->inspect()['revision'], 'changes' => ['hero.0' => 'Roofing in Boston']]);
check('a content apply after it keeps the relabel on record', [$copy['ok'], $relStore->binding['sourceRelabel']['to'] ?? null, $relWriter->store['module'][110]['language']], [true, 'en-US', 'en-US']);

$back = $relCall(['operation' => 'sourceLanguage.revert', 'apply_id' => 'srclang-u', 'request_id' => 'u1']);
check('revert gives the source its published tag and label back', [$back['ok'], $relWriter->store['module'][110]['language'], $relWriter->store['language'][1]['lang_code'], $relWriter->store['language'][1]['title'], $relWriter->store['language'][1]['sef']],
    [true, 'en-GB', 'en-GB', 'English (en-GB)', 'en']);
check('and the defaults, and drops the record', [$relWriter->store['languageDefaults'], isset($relStore->binding['sourceRelabel']), $relContract->sourceLanguage()], [['site' => 'en-GB', 'administrator' => 'en-GB'], false, 'en-GB']);
check('the reverted site inspects clean', $relContract->inspect()['contract'], 'rel/j6/1.0.0');
