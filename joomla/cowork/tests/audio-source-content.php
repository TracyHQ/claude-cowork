<?php
// Normal standalone/include-safe tests; synthetic memory rows do not prove Joomla transactions.
require_once __DIR__ . '/../lib/Engine.php';

final class AudioSourceStore implements ContractStore {
    public ?array $binding = null;
    public function load(): ?array { return $this->binding; }
    public function save(array $binding): void {
        if ($this->binding !== null && $this->binding !== $binding) throw new RuntimeException('Cannot replace a content-only baseline');
        $this->binding = $binding;
    }
    public function replace(array $binding): void { $this->binding = $binding; }
    public function job(): ?array { return null; }
    public function saveJob(?array $job): void { throw new RuntimeException('Not used by audio source tests'); }
    public function access(): array { return ['viewlevels' => [['id' => '1', 'rules' => [1]]]]; }
}
final class AudioSourceLog implements ApplyLog {
    public array $rows = [];
    public function record(string $applyId, array $entry): void { $this->rows[$applyId][] = $entry; }
    public function recordMany(string $applyId, array $entries): void { foreach ($entries as $entry) $this->record($applyId, $entry); }
    public function entries(string $applyId): array { return $this->rows[$applyId] ?? []; }
    public function clear(string $applyId): void { unset($this->rows[$applyId]); }
}
final class AudioSourceWriter implements SiteWriter {
    public array $rows = [];
    private AudioSourceLog $log;
    private AudioSourceStore $store;
    public function __construct(AudioSourceLog $log, AudioSourceStore $store) { $this->log = $log; $this->store = $store; }
    public function transaction(callable $work): array {
        $before = [$this->rows, $this->log->rows, $this->store->binding];
        try { return $work(); }
        catch (Throwable $e) { [$this->rows, $this->log->rows, $this->store->binding] = $before; throw $e; }
    }
    public function read(string $kind, int $id): ?array { return $this->rows[$kind][$id] ?? null; }
    public function list(string $kind, int $offset, int $limit): array {
        $rows = array_slice(array_values($this->rows[$kind] ?? []), $offset, $limit);
        // Match native LIST_COLUMNS: only read() supplies params/client_id for styles, params for menus.
        if ($kind === 'templateStyle') return array_map(static fn($r) => array_intersect_key($r, array_flip(['id', 'title', 'template', 'home'])), $rows);
        if ($kind === 'menuItem') return array_map(static fn($r) => array_diff_key($r, ['params' => true]), $rows);
        return $rows;
    }
    public function write(string $kind, int $id, array $fields): int {
        if (!isset($this->rows[$kind][$id])) throw new RuntimeException('Missing test row');
        $this->rows[$kind][$id] = $fields + $this->rows[$kind][$id]; return $id;
    }
    public function canCreate(string $kind): bool { return false; }
    public function trashColumn(string $kind): ?string { return null; }
    public function purgeCache(): void {}
    public function setVisibility(string $kind, int $id, string $column, string $value): void { throw new RuntimeException('Not used'); }
    public function setVisibilityIf(string $kind, int $id, string $column, string $expected, string $value): bool { throw new RuntimeException('Not used'); }
    public function setVisibilityMany(string $kind, array $ids, string $column, string $value): void { throw new RuntimeException('Not used'); }
    public function realiasMenuItem(int $id, string $alias): void { throw new RuntimeException('Not used'); }
    public function replaceMenuItemParams(int $id, string $expected, string $params): bool { throw new RuntimeException('Not used'); }
    public function replaceTemplateStyleParams(int $id, string $expected, string $params): bool { throw new RuntimeException('Not used'); }
    public function lockMenuChildren(int $parent): array { throw new RuntimeException('Not used'); }
    public function relabelLanguage(string $from, string $to, ?array $label = null): array { throw new RuntimeException('Not used'); }
    public function swapLanguage(string $from, string $to, ?array $label = null): array { throw new RuntimeException('Not used'); }
    public function writeModuleTitles(array $titles): void { throw new RuntimeException('Not used'); }
    public function writeModuleParams(array $texts): void { throw new RuntimeException('Not used'); }
    public function readLanguageDefaults(): array { return ['site' => 'en-GB', 'administrator' => 'en-GB']; }
    public function writeLanguageDefaults(string $site, string $administrator): void { throw new RuntimeException('Not used'); }
    public function delete(string $kind, int $id): void { throw new RuntimeException('Not used'); }
    public function positionOf(string $kind, int $id): ?array { return null; }
    public function move(string $kind, int $id, int $parentId, int $after): void { throw new RuntimeException('Not used'); }
}

final class AudioSourceUri {
    public static string $base = '/music';
    public static function root($pathOnly = false): string { return self::$base; }
}
if (!class_exists('Joomla\\CMS\\Uri\\Uri')) class_alias(AudioSourceUri::class, 'Joomla\\CMS\\Uri\\Uri');
// Synthetic memory fixtures prove receiver validation/receipts, never playback or Joomla transactions.
$asStandalone = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__;
$asFailures = 0; $asCount = 0;
$asCheck = static function (string $name, $got, $want) use (&$asFailures, &$asCount, $asStandalone): void {
    $asCount++;
    if (!$asStandalone) { check('audio source: ' . $name, $got, $want); return; }
    $pass = $got === $want;
    echo ($pass ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$pass) { $asFailures++; echo json_encode(['actual' => $got, 'expected' => $want]) . PHP_EOL; }
};
$asRoot = sys_get_temp_dir() . '/cowork-audio-source-' . bin2hex(random_bytes(6));
mkdir($asRoot);
$asRemove = static function (string $path) use (&$asRemove): void {
    if (is_dir($path) && !is_link($path)) { foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) $asRemove($entry->getPathname()); rmdir($path); }
    else unlink($path);
};
$asFixture = static function (array $options = []) use ($asRoot): array {
    $root = $asRoot . '/' . bin2hex(random_bytes(4)); $dir = $root . '/lib/contracts/audio/test/1';
    foreach ([$dir, $root . '/images/audio', $root . '/media/audio', $root . '/templates/ja_symphony/html/layouts/joomla/content', $root . '/templates/ja_symphony/html/com_content/article'] as $path) mkdir($path, 0777, true);
    // Complete MPEG frames identify as audio/mpeg; a renamed text file cannot pass finfo.
    $mp3 = str_repeat(hex2bin('fffb9064') . str_repeat(chr(0), 413), 4);
    foreach (['images/audio/old.mp3', 'images/audio/new.mp3', 'media/audio/new.mp3'] as $path) file_put_contents($root . '/' . $path, $mp3);
    file_put_contents($root . '/images/audio/not-audio.mp3', 'ordinary text, not media');
    file_put_contents($root . '/outside.mp3', $mp3);
    symlink($root . '/outside.mp3', $root . '/images/audio/escape.mp3');
    $receiver = '<?php if (empty($displayData->params->get("ctm_audio")["audio-src"])) return; ?>';
    $caller = '<?php echo LayoutHelper::render("joomla.content.audio", $this->item); ?>';
    $receiverPath = 'templates/ja_symphony/html/layouts/joomla/content/audio.php';
    $callerPath = 'templates/ja_symphony/html/com_content/article/default.php';
    file_put_contents($root . '/' . $receiverPath, $receiver); file_put_contents($root . '/' . $callerPath, $caller);
    $review = ['template' => 'ja_symphony', 'path' => $receiverPath, 'fields' => ['ctm_audio.audio-src' => 'hidden'], 'files' => [$callerPath => hash('sha256', $caller)]];
    file_put_contents($root . '/lib/link-layouts.json', json_encode(['layouts' => [], 'audioLayouts' => [hash('sha256', $receiver) => $review]]));
    $row = ['id' => '114', 'title' => 'Synthetic audio article', 'alias' => 'synthetic-audio', 'state' => '1', 'access' => '1', 'language' => '*', 'introtext' => '', 'fulltext' => '', 'attribs' => json_encode(['article_layout' => '', 'ctm_audio' => ['audio-src' => ['images/audio/old.mp3'], 'audio-title' => ['Synthetic track'], 'audio-apple' => ['https://shop.example/item']]])];
    $slot = ['key' => 'article-14.audio.0', 'entity' => 'article-14', 'column' => 'attribs', 'type' => 'url', 'sample' => 'images/audio/old.mp3', 'maxCharacters' => 2048, 'requiresEvidence' => true, 'jsonPath' => ['ctm_audio', 'audio-src', 0], 'role' => 'business.audio', 'group' => ['id' => 'article-14.audio.0', 'kind' => 'business', 'item' => 0]];
    if (isset($options['slot'])) $slot = array_replace($slot, $options['slot']);
    $ordinary = ['key' => 'article-14.title', 'entity' => 'article-14', 'column' => 'title', 'type' => 'text', 'sample' => $row['title'], 'maxCharacters' => 40];
    $link = ['key' => 'article-14.shop', 'entity' => 'article-14', 'column' => 'attribs', 'jsonPath' => ['ctm_audio', 'audio-apple', 0], 'type' => 'url', 'sample' => 'https://shop.example/item', 'maxCharacters' => 2048];
    $store = new AudioSourceStore(); $log = new AudioSourceLog(); $writer = new AudioSourceWriter($log, $store);
    $writer->rows = ['article' => [114 => $row], 'templateStyle' => [7 => ['id' => '7', 'title' => 'Default', 'template' => 'ja_symphony', 'client_id' => '0', 'home' => '1', 'params' => '{}']], 'menuItem' => []];
    $map = ['roleVocabularyVersion' => $options['vocabulary'] ?? '3', 'entities' => [['key' => 'article-14', 'kind' => 'article', 'sourceId' => 14, 'identity' => ['title' => $row['title']]]], 'slots' => [$slot, $ordinary, $link], 'pages' => []];
    $files = ['manifest' => ['id' => 'audio/test/1'], 'content-map' => $map, 'presentation-lock' => ['entities' => ['article-14' => array_diff_key($row, ['id' => true])], 'assignments' => [], 'fileRoots' => [], 'files' => [], 'inventoryCounts' => ['article' => 1], 'access' => $store->access()]];
    foreach ($files as $name => $data) file_put_contents($dir . '/' . $name . '.json', json_encode($data));
    if (!empty($options['forgedReview'])) file_put_contents($root . '/lib/hideable-slots.json', json_encode(['contracts' => ['audio/test/1' => ['contentMapSha256' => hash_file('sha256', $dir . '/content-map.json'), 'measured' => [$slot['key']]]]]));
    $contract = new QuickstartContract($writer, $store, $root, $dir);
    $engine = new Engine('synthetic-audio-test-token', [], null, null, null, null, $writer, null, $log, null, null, null, $contract);
    $call = static fn(string $action, array $params = []) => $engine->handle(['token' => 'synthetic-audio-test-token', 'action' => $action, 'params' => $params]);
    $before = $call('content.contract'); $call('content.contract', ['operation' => 'bind']);
    return compact('root', 'dir', 'row', 'slot', 'store', 'log', 'writer', 'contract', 'call', 'before', 'receiverPath', 'callerPath');
};
$asApply = static function (array $f, array $params): array {
    static $number = 0; $number++;
    return ($f['call'])('content.contract', ['operation' => 'apply', 'apply_id' => 'contract-audio-' . $number, 'request_id' => 'audio-' . $number, 'expected_revision' => ($f['call'])('content.contract')['revision']] + $params) + ['testApplyId' => 'contract-audio-' . $number];
};
$asError = static fn(array $result) => $result['errors'][0]['code'] ?? $result['error']['code'] ?? null;
try {
    $f = $asFixture();
    $asCheck('public inspect resolves actual article identity', [$f['before']['ok'], $f['contract']->inspect()['ids']['article-14']], [true, 114]);
    $asCheck('only exact reviewed receiver exposes conditional hide', array_column($f['before']['slots'], 'canHide', 'key'), ['article-14.audio.0' => true]);
    foreach (['https://audio.example/New.MP3?token=a%2Fb&v=2', 'HTTPS://audio.example/new.mp3', 'images/audio/new.mp3', '/images/audio/new.mp3?token=a%2Fb', '/media/audio/new.mp3', '/music/images/audio/new.mp3?token=a%2Fb'] as $source) {
        $revision = ($f['call'])('content.contract')['revision'];
        try { $plan = count($f['contract']->plan(['expected_revision' => $revision, 'changes' => ['article-14.audio.0' => $source], 'evidence' => ['article-14.audio.0' => 'Synthetic customer supplied exact source']])['operations']); } catch (ContractProblem $e) { $plan = $e->reason; }
        $asCheck('public revision-aware audio plan ' . $source, $plan, 1);
        $r = $asApply($f, ['changes' => ['article-14.audio.0' => $source], 'evidence' => ['article-14.audio.0' => 'Synthetic customer supplied exact source']]);
        $asCheck('public audio apply', $r['ok'], true);
        if ($r['ok']) {
            $asCheck('public readback preserves exact address and query', array_column(($f['call'])('content.contract')['slots'], 'current', 'key')['article-14.audio.0'], $source);
            $asCheck('ordinary contract receipt used', count(array_filter($f['log']->entries($r['testApplyId']), static fn($e) => $e['op'] === 'contract')), 1);
            $asCheck('public receipt revert', ($f['call'])('apply.revert', ['apply_id' => $r['testApplyId']])['ok'], true);
            $asCheck('revert restores exact original row', $f['writer']->rows['article'][114], $f['row']);
        }
    }
    foreach (['mailto:a@example.test', 'tel:123', '#song', 'index.php?Itemid=4', '//audio.example/a.mp3', 'http://audio.example/a.mp3', 'javascript:a.mp3', 'https://u:p@audio.example/a.mp3', 'images/../outside.mp3', 'images/%2e%2e/outside.mp3', 'images/a%2fb.mp3', 'images/a%5cb.mp3', 'images/%252e/a.mp3', 'images/audio/new.mp3#part', 'images/audio/new.mp3?bad=%ZZ', 'images/audio/not-audio.mp3', 'images/audio/escape.mp3', 'outside.mp3', '/wrong/images/audio/new.mp3', 'images/audio/missing.mp3', 'https://audio.example/a.ogg'] as $source) {
        $saved = [$f['writer']->rows, $f['log']->rows, $f['store']->binding];
        $r = $asApply($f, ['changes' => ['article-14.audio.0' => $source], 'evidence' => ['article-14.audio.0' => 'Synthetic source']]);
        $asCheck('unsafe/nonmedia audio refused ' . $source, $r['ok'], false);
        $asCheck('refusal preserves rows receipts baseline', [$f['writer']->rows, $f['log']->rows, $f['store']->binding], $saved);
    }
    $asCheck('audio requires customer evidence', $asError($asApply($f, ['changes' => ['article-14.audio.0' => 'https://audio.example/new.mp3']])), 'SLOT_EVIDENCE_REQUIRED');
    $asCheck('ordinary occupancy guard remains', $asError($asApply($f, ['changes' => ['article-14.audio.0' => ''], 'evidence' => ['article-14.audio.0' => 'Synthetic customer source']])), 'SLOT_EMPTY_STATE');
    $r = $asApply($f, ['empty_slots' => ['article-14.audio.0']]);
    $asCheck('source-proven neutral absence uses public empty_slots', $r['ok'], true);
    if ($r['ok']) {
        $asCheck('absence affects only exact source row', json_decode($f['writer']->rows['article'][114]['attribs'], true)['ctm_audio'], ['audio-src' => [''], 'audio-title' => ['Synthetic track'], 'audio-apple' => ['https://shop.example/item']]);
        $asCheck('absence receipt revert', ($f['call'])('apply.revert', ['apply_id' => $r['testApplyId']])['ok'], true);
        $asCheck('absence restores exact row/revision', [$f['writer']->rows['article'][114], ($f['call'])('content.contract')['revision']], [$f['row'], $f['before']['revision']]);
    }
    $asCheck('ordinary title cannot borrow caller hide flag', $asError($asApply($f, ['empty_slots' => ['article-14.title'], 'canHide' => true, 'role' => 'business.audio'])), 'SLOT_NOT_HIDEABLE');
    $asCheck('ordinary CTA validation remains', $asError($asApply($f, ['changes' => ['article-14.shop' => 'mailto:shop@example.test']])), null);
    foreach ([['slot' => ['role' => 'business.audio', 'group' => ['id' => 'article-14.audio.1', 'kind' => 'business', 'item' => 1]]], ['vocabulary' => '2'], ['slot' => ['column' => 'title', 'jsonPath' => null, 'sample' => 'Synthetic audio article']]] as $options) {
        $g = $asFixture($options + ['forgedReview' => true]);
        $asCheck('wrong row/version/address cannot forge source hide', $asError($asApply($g, ['empty_slots' => ['article-14.audio.0'], 'audioSourceProved' => true])), 'SLOT_NOT_HIDEABLE');
        $asCheck('wrong row/version/address cannot use audio value path', $asApply($g, ['changes' => ['article-14.audio.0' => 'https://audio.example/new.mp3'], 'evidence' => ['article-14.audio.0' => 'Synthetic source']])['ok'], false);
    }
    foreach (['edited', 'missing', 'local', 'component', 'suffix', 'caller', 'other-template', 'menu-layout', 'menu-audio', 'row-layout', 'receiver-symlink', 'unknown-style'] as $mutation) {
        $g = $asFixture(['forgedReview' => true]);
        if ($mutation === 'edited') file_put_contents($g['root'] . '/' . $g['receiverPath'], '<?php echo "unconditional";');
        elseif ($mutation === 'missing') unlink($g['root'] . '/' . $g['receiverPath']);
        elseif ($mutation === 'caller') file_put_contents($g['root'] . '/' . $g['callerPath'], '<?php echo "other";');
        elseif ($mutation === 'other-template') $g['writer']->rows['templateStyle'][8] = ['id' => '8', 'template' => 'other', 'client_id' => '0'];
        elseif ($mutation === 'unknown-style') unset($g['writer']->rows['templateStyle'][7]['client_id']);
        elseif ($mutation === 'row-layout') { $data = json_decode($g['writer']->rows['article'][114]['attribs'], true); $data['article_layout'] = 'alternate'; $g['writer']->rows['article'][114]['attribs'] = json_encode($data); }
        elseif ($mutation === 'receiver-symlink') { $path = $g['root'] . '/' . $g['receiverPath']; copy($path, $g['root'] . '/same.php'); unlink($path); symlink($g['root'] . '/same.php', $path); }
        elseif ($mutation === 'menu-audio') $g['writer']->rows['menuItem'][9] = ['id' => '9', 'client_id' => '0', 'link' => 'index.php?option=com_content&view=article', 'params' => json_encode(['ctm_audio' => ['audio-src' => ['https://other.example/a.mp3']]])];
        elseif ($mutation === 'menu-layout') $g['writer']->rows['menuItem'][9] = ['id' => '9', 'client_id' => '0', 'link' => 'index.php?option=com_content&view=article&layout=other', 'params' => '{}'];
        else {
            $path = ['local' => '/templates/ja_symphony/local/html/layouts/joomla/content/audio.php', 'component' => '/templates/ja_symphony/html/layouts/com_content/joomla/content/audio.php', 'suffix' => '/templates/ja_symphony/html/layouts/joomla/content/audio.en-GB.php'][$mutation];
            if (!is_dir(dirname($g['root'] . $path))) mkdir(dirname($g['root'] . $path), 0777, true); file_put_contents($g['root'] . $path, '<?php echo "override";');
        }
        $state = ($g['call'])('content.contract');
        $asCheck('actual source mutation removes hide proof ' . $mutation, isset(array_column($state['slots'], null, 'key')['article-14.audio.0']['canHide']), false);
        $asCheck('actual source mutation refuses caller/registry waiver ' . $mutation, $asError($asApply($g, ['empty_slots' => ['article-14.audio.0'], 'canHide' => true])), 'SLOT_NOT_HIDEABLE');
        $asCheck('unproven receiver cannot accept new audio source ' . $mutation, $asError($asApply($g, ['changes' => ['article-14.audio.0' => 'https://audio.example/new.mp3'], 'evidence' => ['article-14.audio.0' => 'Synthetic source']])), 'SLOT_AUDIO_INVALID');
    }
    $g = $asFixture(['slot' => ['requiresEvidence' => false]]);
    $asCheck('audio provenance cannot be disabled by ordinary slot metadata', $asError($asApply($g, ['changes' => ['article-14.audio.0' => 'https://audio.example/new.mp3']])), 'SLOT_EVIDENCE_REQUIRED');
    foreach (['missing', 'malformed', 'wrong-path'] as $mutation) {
        $g = $asFixture(); $registry = $g['root'] . '/lib/link-layouts.json';
        if ($mutation === 'missing') unlink($registry);
        elseif ($mutation === 'malformed') file_put_contents($registry, '{');
        else { $data = json_decode(file_get_contents($registry), true); $hash = array_key_first($data['audioLayouts']); $data['audioLayouts'][$hash]['path'] = '../audio.php'; file_put_contents($registry, json_encode($data)); }
        $guard = new QuickstartContract($g['writer'], $g['store'], $g['root'], $g['dir']);
        $asCheck('absent or invalid trusted source registry closes only eligibility ' . $mutation, isset(array_column($guard->inspect()['slots'], null, 'key')['article-14.audio.0']['canHide']), false);
    }
    $registry = json_decode(file_get_contents(__DIR__ . '/../lib/link-layouts.json'), true);
    $review = $registry['audioLayouts']['35c1db4bbd79371b44745541599806e51f4f45ea2e4de77419a7a5defacbe2bd'] ?? [];
    $asCheck('shipped audio source review pins corrected owner-aware receiver', [$review['path'] ?? null, $review['fields'] ?? null, count($review['files'] ?? [])], ['templates/ja_symphony/html/layouts/joomla/content/audio.php', ['ctm_audio.audio-src' => 'hidden'], 4]);
    $r = ($f['call'])('content.contract', ['operation' => 'apply', 'apply_id' => 'contract-audio-stale', 'request_id' => 'audio-stale', 'expected_revision' => 'stale', 'changes' => ['article-14.audio.0' => 'https://audio.example/new.mp3'], 'evidence' => ['article-14.audio.0' => 'Synthetic source']]);
    $asCheck('ordinary stale revision guard remains', $asError($r), 'REVISION_STALE');
} finally { $asRemove($asRoot); }
if ($asStandalone) { echo 'Audio source assertions: ' . $asCount . ', failures: ' . $asFailures . PHP_EOL; exit($asFailures ? 1 : 0); }
