<?php
// Loaded by run.php: a sealed quickstart whose source edition was WRITTEN in another language (one
// language asked for, not the archive's) is called by that language's tag — `<html lang>`, Joomla's
// own words and the content language follow the text — while its URLs, `sef` and menus stay as they
// are. The archive's own hidden edition of that language trades tags with the source instead of
// being merged into it, the module headings the contract has no slot for are written too, and
// everything goes back.

final class WrittenTestExtensions implements ExtensionManager
{
    public array $asked = [];
    public function installFromUrl(string $url): array { throw new RuntimeException('unverified install'); }
    public function installVerifiedFromUrl(string $url, string $sha256, int $bytes): array { $this->asked[] = $url; return ['ok' => true, 'type' => 'package']; }
    // The archive ships the vi-VN pack already, as Tracy Business does.
    public function listInstalled(): array { return [['type' => 'language', 'element' => 'vi-VN', 'name' => 'Vietnamese']]; }
    public function coreManifest(): array { return ['platform' => 'joomla', 'platformVersion' => '6.1.2', 'extensions' => []]; }
    public function setEnabled(string $type, string $element, ?string $folder, bool $enabled): array { return ['ok' => false, 'error' => 'not used']; }
}

$wsRoot = sys_get_temp_dir() . '/cowork-written-language-' . bin2hex(random_bytes(6));
$wsDir = $wsRoot . '/lib/contracts/ws/j6/1.0.0';
mkdir($wsDir, 0777, true);
$wsStore = new TestContractStore();
$wsLog = new FakeApplyLog();
$wsWriter = new ContractTestWriter($wsLog, $wsStore);
$wsHero = ['title' => 'Home hero', 'module' => 'mod_custom', 'position' => 'masthead', 'published' => '1', 'access' => '1', 'language' => 'en-GB', 'client_id' => '0',
    'showtitle' => '0', 'content' => '<h1>Demo title</h1>', 'params' => '{"style":"0","show_date_format":"F j, Y"}'];
$wsColumn = ['title' => 'Services', 'module' => 'mod_menu', 'position' => 'footer-b', 'published' => '1', 'access' => '1', 'language' => 'en-GB', 'client_id' => '0',
    'showtitle' => '1', 'content' => '', 'params' => '{"menutype":"footer-en"}'];
$wsEverywhere = ['title' => 'Search', 'module' => 'mod_finder', 'position' => 'sidebar', 'published' => '1', 'access' => '1', 'language' => '*', 'client_id' => '0',
    'showtitle' => '1', 'content' => '', 'params' => '{}'];
$wsWriter->store['module'][110] = ['id' => '110'] + $wsHero;
$wsWriter->store['module'][111] = ['id' => '111'] + $wsColumn;
$wsWriter->store['module'][112] = ['id' => '112'] + $wsEverywhere;
// The archive's own vi-VN edition, hidden by `multilingual.retire`: not under the contract.
$wsWriter->store['module'][210] = ['id' => '210'] + ['published' => '0', 'language' => 'vi-VN'] + $wsColumn;
foreach ([110, 111, 112] as $id) $wsWriter->store['moduleAssignment'][$id] = ['menuids' => '[0]'];
$wsWriter->store['language'][1] = ['lang_id' => '1', 'lang_code' => 'en-GB', 'sef' => 'en', 'published' => '1',
    'title' => 'English (en-GB)', 'title_native' => 'English', 'image' => 'en_gb'];
$wsWriter->store['language'][43] = ['lang_id' => '43', 'lang_code' => 'vi-VN', 'sef' => 'vi', 'published' => '0',
    'title' => 'Vietnamese', 'title_native' => 'Tiếng Việt', 'image' => 'vi_vn'];
$wsWriter->store['languageDefaults'] = ['site' => 'en-GB', 'administrator' => 'en-GB'];
$wsSlots = [];
foreach (ContentSlots::htmlSlots($wsHero['content']) as $n => $s) $wsSlots[] = ['key' => 'hero.' . $n, 'entity' => 'hero', 'column' => 'content', 'maxCharacters' => 80, 'sample' => 'Demo title'] + $s;
foreach ([
    'manifest' => ['id' => 'ws/j6/1.0.0'],
    'content-map' => ['entities' => [
        ['key' => 'hero', 'kind' => 'module', 'sourceId' => 10, 'identity' => ['title' => 'Home hero']],
        ['key' => 'column', 'kind' => 'module', 'sourceId' => 11, 'identity' => ['title' => 'Services', 'language' => 'en-GB']],
        ['key' => 'search', 'kind' => 'module', 'sourceId' => 12, 'identity' => ['title' => 'Search']],
    ], 'slots' => $wsSlots, 'pages' => []],
    'presentation-lock' => ['entities' => ['hero' => $wsHero, 'column' => $wsColumn, 'search' => $wsEverywhere],
        'assignments' => [['moduleid' => 10, 'menuid' => 0], ['moduleid' => 11, 'menuid' => 0], ['moduleid' => 12, 'menuid' => 0]], 'fileRoots' => [], 'files' => [],
        'inventoryCounts' => ['module' => 4], 'access' => $wsStore->acl],
] as $name => $body) file_put_contents($wsDir . '/' . $name . '.json', json_encode($body));
file_put_contents($wsRoot . '/lib/language-packs.json', json_encode([
    'schemaVersion' => 'tracy-joomla-language-packs/v1',
    'packs' => ['vi-VN' => ['tag' => 'vi-VN', 'name' => 'Vietnamese', 'version' => '4.2.2.1', 'url' => 'https://downloads.joomla.org/vi.zip',
        'sha256' => str_repeat('c', 64), 'bytes' => 754892, 'platformMajors' => [4, 6]]],
]));

$wsContract = new QuickstartContract($wsWriter, $wsStore, $wsDir, $wsDir);
// The vi-VN pack's own date format (4.2.2.1), and a site override that wins over it.
mkdir($wsRoot . '/site/language/vi-VN', 0777, true);
mkdir($wsRoot . '/site/language/overrides', 0777, true);
file_put_contents($wsRoot . '/site/language/vi-VN/joomla.ini', "DATE_FORMAT_LC3=\"d F Y\"\n");
file_put_contents($wsRoot . '/site/language/overrides/vi-VN.override.ini', "DATE_FORMAT_LC3=\"j F, Y\"\n");
$wsEngine = (new Engine($WTOKEN, ['joomla' => '6.1.2'], null, null, null, new WrittenTestExtensions(), $wsWriter, null, $wsLog, null, null, null, $wsContract))
    ->languageOverrides(new LanguageOverrides($wsRoot . '/site'));
$wsCall = fn(array $params) => $wsEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);
$wsWriter->transaction(function () use ($wsContract) { $wsContract->bind($wsContract->inspect()['snapshot']); return []; });

$plan = $wsCall(['operation' => 'sourceLanguage.plan', 'locale' => 'vi-VN', 'written' => true]);
check('a written plan names the headings the contract has no slot for, and only the shown ones',
    [$plan['ok'] ?? null, $plan['written'] ?? null, $plan['titles'] ?? null],
    [true, true, [['key' => 'column.title', 'source' => 'Services', 'maxCharacters' => 100], ['key' => 'search.title', 'source' => 'Search', 'maxCharacters' => 100]]]);
check('a variant of the source is a plain relabel, not a written one',
    $wsCall(['operation' => 'sourceLanguage.plan', 'locale' => 'en-US', 'written' => true])['error'] ?? null, 'bad_params');

$set = fn(array $extra = []) => $wsCall($extra + ['operation' => 'sourceLanguage.set', 'locale' => 'vi-VN', 'written' => true, 'apply_id' => 'srclang-w', 'request_id' => 'w1']);
check('a heading the contract does not name is refused', $set(['titles' => ['hero.title' => 'Anh hung']])['error'] ?? null, 'bad_params');
check('a heading with markup is refused', $set(['titles' => ['column.title' => '<b>Dịch vụ</b>']])['error'] ?? null, 'bad_params');
$wsStore->binding['presentation']['search']['language'] = 'vi-VN';
check('a language the contract governs rows of cannot name the source', $set(['titles' => ['column.title' => 'Dịch vụ']])['error'] ?? null, 'conflict');
$wsStore->binding['presentation']['search']['language'] = '*';
$wsWriter->store['language'][43]['published'] = '1';
$routed = $set(['titles' => ['column.title' => 'Dịch vụ']]);
check('a language the site still routes cannot name the source', [$routed['ok'], $wsWriter->store['module'][111]['language'], $wsWriter->store['module'][111]['title']], [false, 'en-GB', 'Services']);
$wsWriter->store['language'][43]['published'] = '0';

// The named fields of a row, in the order named.
$wsFields = fn (array $row, array $names) => array_map(fn ($name) => $row[$name] ?? null, array_combine($names, $names));
$done = $set(['titles' => ['column.title' => 'Dịch vụ', 'search.title' => 'Tìm kiếm']]);
check('the written relabel completes', [$done['ok'] ?? null, $done['source'] ?? null, $done['swapped'] ?? null], [true, 'vi-VN', true]);
check('the module date takes the language\'s own format, the site\'s override of it first', [$done['dates'] ?? null, $done['titles'] ?? null, json_decode($wsWriter->store['module'][110]['params'], true)['show_date_format'] ?? null], [1, 2, 'j F, Y']);
check('the source rows carry vi-VN; the everywhere row stays everywhere; the hidden edition takes en-GB',
    [$wsWriter->store['module'][110]['language'], $wsWriter->store['module'][111]['language'], $wsWriter->store['module'][112]['language'], $wsWriter->store['module'][210]['language'], $wsWriter->store['module'][210]['published']],
    ['vi-VN', 'vi-VN', '*', 'en-GB', '0']);
check('the routed content language keeps its id, sef and state and takes the Vietnamese tag and label',
    $wsFields($wsWriter->store['language'][1], ['lang_code', 'sef', 'published', 'title_native']),
    ['lang_code' => 'vi-VN', 'sef' => 'en', 'published' => '1', 'title_native' => 'Tiếng Việt']);
check('the hidden one takes the English tag and stays hidden',
    $wsFields($wsWriter->store['language'][43], ['lang_code', 'sef', 'published', 'title']),
    ['lang_code' => 'en-GB', 'sef' => 'vi', 'published' => '0', 'title' => 'English (en-GB)']);
check('the shown headings are written', [$wsWriter->store['module'][111]['title'], $wsWriter->store['module'][112]['title'], $wsWriter->store['module'][110]['title']], ['Dịch vụ', 'Tìm kiếm', 'Home hero']);
check('site and administrator speak vi-VN', $wsWriter->store['languageDefaults'], ['site' => 'vi-VN', 'administrator' => 'vi-VN']);
check('the contract names the source vi-VN and the site inspects clean', [$wsContract->sourceLanguage(), $wsContract->inspect()['contract']], ['vi-VN', 'ws/j6/1.0.0']);
$wsAgain = new QuickstartContract($wsWriter, $wsStore, $wsDir, $wsDir);
check('a fresh receiver reads it back from the binding', [$wsAgain->sourceLanguage(), $wsAgain->inspect()['contract']], ['vi-VN', 'ws/j6/1.0.0']);
check('asking again changes nothing', [$set(['titles' => ['column.title' => 'Dịch vụ']])['alreadySet'] ?? null, $wsWriter->store['module'][111]['title']], [true, 'Dịch vụ']);
check('a plain relabel on top is refused, not stacked',
    $wsCall(['operation' => 'sourceLanguage.set', 'locale' => 'en-US', 'apply_id' => 'srclang-x', 'request_id' => 'x1'])['error'] ?? null, 'conflict');

$copy = $wsCall(['operation' => 'apply', 'apply_id' => 'contract-w1', 'request_id' => 'c1', 'expected_revision' => $wsContract->inspect()['revision'], 'changes' => ['hero.0' => 'Bánh mì mỗi sáng']]);
check('a content apply after it keeps the language and the headings', [$copy['ok'] ?? null, $wsWriter->store['module'][110]['language'], $wsWriter->store['module'][111]['title']], [true, 'vi-VN', 'Dịch vụ']);

$back = $wsCall(['operation' => 'sourceLanguage.revert', 'apply_id' => 'srclang-wu', 'request_id' => 'wu1']);
check('revert swaps the tags back', [$back['ok'] ?? null, $wsWriter->store['module'][111]['language'], $wsWriter->store['module'][210]['language'], $wsWriter->store['language'][1]['lang_code'], $wsWriter->store['language'][43]['lang_code']],
    [true, 'en-GB', 'vi-VN', 'en-GB', 'vi-VN']);
check('and the headings and the defaults', [$wsWriter->store['module'][111]['title'], $wsWriter->store['module'][112]['title'], $wsWriter->store['languageDefaults']],
    ['Services', 'Search', ['site' => 'en-GB', 'administrator' => 'en-GB']]);
check('and the module date format', json_decode($wsWriter->store['module'][110]['params'], true)['show_date_format'] ?? null, 'F j, Y');
check('the reverted site inspects clean, under its published tag', [$wsContract->sourceLanguage(), $wsContract->inspect()['contract'], isset($wsStore->binding['sourceRelabel'])], ['en-GB', 'ws/j6/1.0.0', false]);
