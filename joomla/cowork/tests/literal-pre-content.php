<?php
// Normal standalone/include-safe tests; synthetic memory rows do not prove Joomla transactions.
require_once __DIR__ . '/../lib/Engine.php';

final class LiteralPreStore implements ContractStore {
    public ?array $binding = null;
    public function load(): ?array { return $this->binding; }
    public function save(array $binding): void {
        if ($this->binding !== null && $this->binding !== $binding) throw new RuntimeException('Cannot replace a content-only baseline');
        $this->binding = $binding;
    }
    public function replace(array $binding): void { $this->binding = $binding; }
    public function job(): ?array { return null; }
    public function saveJob(?array $job): void { throw new RuntimeException('Not used by literal PRE tests'); }
    public function access(): array { return ['viewlevels' => [['id' => '1', 'rules' => [1]]]]; }
}
final class LiteralPreLog implements ApplyLog {
    public array $rows = [];
    public function record(string $applyId, array $entry): void { $this->rows[$applyId][] = $entry; }
    public function recordMany(string $applyId, array $entries): void { foreach ($entries as $entry) $this->record($applyId, $entry); }
    public function entries(string $applyId): array { return $this->rows[$applyId] ?? []; }
    public function clear(string $applyId): void { unset($this->rows[$applyId]); }
}
final class LiteralPreWriter implements SiteWriter {
    public array $rows = [];
    private LiteralPreLog $log;
    private LiteralPreStore $store;
    public function __construct(LiteralPreLog $log, LiteralPreStore $store) { $this->log = $log; $this->store = $store; }
    public function transaction(callable $work): array {
        $before = [$this->rows, $this->log->rows, $this->store->binding];
        try { return $work(); }
        catch (Throwable $e) { [$this->rows, $this->log->rows, $this->store->binding] = $before; throw $e; }
    }
    public function read(string $kind, int $id): ?array { return $this->rows[$kind][$id] ?? null; }
    public function list(string $kind, int $offset, int $limit): array { return array_slice(array_values($this->rows[$kind] ?? []), $offset, $limit); }
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

$lpStandalone = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__;
$lpFailed = 0;
$lpCheck = static function (string $name, $actual, $expected) use (&$lpFailed, $lpStandalone): void {
    if (!$lpStandalone) { check('literal PRE: ' . $name, $actual, $expected); return; }
    $pass = $actual === $expected;
    echo ($pass ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$pass) { $lpFailed++; echo json_encode(['actual' => $actual, 'expected' => $expected], JSON_UNESCAPED_UNICODE) . PHP_EOL; }
};
$lpRead = static function (string $html, string $path): string {
    $nodes = (new DOMXPath(ContentSlots::html($html)))->query($path);
    return ContentSlots::slotText($nodes->item(0));
};
$lpPath = '/html/body/div/pre/code/text()';
$lpSample = "SELECT <Widget>\n\tA &amp; B ((strong))Case((/strong))";
$lpHtml = '<h2>Before</h2><pre><code>' . htmlspecialchars($lpSample, ENT_NOQUOTES, 'UTF-8') . '</code></pre><p>After</p>';
$lpSlots = ContentSlots::htmlSlots($lpHtml);
$lpCheck('capture declares exact decoded scalar', array_values(array_filter($lpSlots, static fn($s) => $s['xpath'] === '/html/body/div/pre/code/text()')),
    [['xpath' => $lpPath, 'type' => 'text', 'sample' => $lpSample]]);
$lpValue = "  UPDATE <Other>\n\tX &amp; &#38; & Y ((p))Literal((/p))  ";
$lpPatched = ContentSlots::htmlPatch($lpHtml, [['xpath' => $lpPath, 'value' => $lpValue]]);
$lpCheck('literal write/readback does not interpret entities', $lpRead($lpPatched, $lpPath), $lpValue);
foreach (["Case\tTAB\nLine", "A\r\nB", "&lt;b&gt; &amp; &#38;", "Unicode λ 😀"] as $lpLiteral) {
    try { $lpRoundTrip = $lpRead(ContentSlots::htmlPatch($lpHtml, [['xpath' => $lpPath, 'value' => $lpLiteral]]), $lpPath); }
    catch (RuntimeException $lpError) { $lpRoundTrip = $lpError->getMessage(); }
    $lpCheck('decoded literal round trip: ' . json_encode($lpLiteral), $lpRoundTrip, $lpLiteral);
}
$lpCheck('wrapper and sibling owners stay', (new DOMXPath(ContentSlots::html($lpPatched)))->query('/html/body/div/pre/code | /html/body/div/h2 | /html/body/div/p')->length, 3);
foreach (['<code>inline</code>', '<pre><code><span>nested</span></code></pre>', '<pre>outside<code>mixed</code></pre>', '<pre><code>one<!--gap-->two</code></pre>'] as $lpBad) {
    $lpCheck('unsupported structure is not captured: ' . $lpBad, array_values(array_filter(ContentSlots::htmlSlots($lpBad), static fn($s) => strpos($s['xpath'], '/code') !== false)), []);
}

// These checks preserve the separate mixed-inline behavior; this increment gives it no rewrite anchors.
$lpInline = '<li><strong>Recovery</strong> is one <code>git revert</code> away.</li>';
$lpCheck('same inline words retain CODE placement and emphasis', ContentSlots::htmlPatch($lpInline,
    [['xpath' => '/html/body/div/li', 'value' => 'Recovery is one away.']]), $lpInline);
$lpCheck('changed inline words retain existing unsupported placement behavior', ContentSlots::htmlPatch($lpInline,
    [['xpath' => '/html/body/div/li', 'value' => 'Changed.']]), '<li>Changed.<code>git revert</code></li>');
foreach (['<pre><code>one<span>two</span></code></pre>', '<pre>outside<code>mixed</code></pre>',
    '<pre><code>one<!--gap-->two</code></pre>', '<pre><code>one</code><code>two</code></pre>', '<p>Wrong owner</p>', '<pre><code>one</code></pre><pre><code>two</code></pre>'] as $lpBad) {
    try { ContentSlots::htmlPatch($lpBad, [['xpath' => $lpPath, 'value' => 'Plain replacement']]); $lpReject = false; }
    catch (RuntimeException $lpError) { $lpReject = true; }
    $lpCheck('write refuses malformed or missing structure: ' . $lpBad, $lpReject, true);
}

$lpCheck('ordinary whole-unit emphasis still rewrites', ContentSlots::htmlPatch('<h2>Old <b>title</b></h2>',
    [['xpath' => '/html/body/div/h2', 'value' => 'New heading']]), '<h2>New heading</h2>');
$lpCheck('ordinary attribute entity behavior stays', ContentSlots::htmlPatch('<a href="/old">Go</a>',
    [['xpath' => '/html/body/div/a/@href', 'value' => '/new?a=1&b=2']]), '<a href="/new?a=1&amp;b=2">Go</a>');
$lpCheck('PRE title remains an ordinary attribute slot', ContentSlots::htmlSlots('<pre title="Example"><code>X</code></pre>')[0],
    ['xpath' => '/html/body/div/pre/@title', 'type' => 'text', 'sample' => 'Example']);

$lpBare = '<pre>Legacy <b>words</b></pre>';
$lpCheck('legacy bare PRE captures ordinary scalar pieces', ContentSlots::htmlSlots($lpBare), [
    ['xpath' => '/html/body/div/pre/text()', 'type' => 'text', 'sample' => 'Legacy '],
    ['xpath' => '/html/body/div/pre/b/text()', 'type' => 'text', 'sample' => 'words']]);
$lpCheck('legacy bare PRE has no literal waiver', ContentSlots::literalPreSlot(['type' => 'text', 'xpath' => '/html/body/div/pre/text()']), false);
try { $lpBareWrite = ContentSlots::htmlPatch($lpBare, [['xpath' => '/html/body/div/pre/text()', 'value' => 'Revised ']]); }
catch (RuntimeException $lpError) { $lpBareWrite = $lpError->getMessage(); }
$lpCheck('legacy bare PRE ordinary scalar writes in place', $lpBareWrite, '<pre>Revised <b>words</b></pre>');
$lpCheck('legacy bare PRE root unit keeps ordinary normalization', $lpRead($lpBare, '/html/body/div'), 'Legacy words');
$lpCheck('legacy bare PRE root unit writes ordinary text', ContentSlots::htmlPatch($lpBare,
    [['xpath' => '/html/body/div', 'value' => 'Ordinary replacement']]), 'Ordinary replacement');
foreach ([['<pre title="Example"><code>X</code></pre>', '/html/body/div/pre/@title', $lpPath],
    ['<pre><code title="Example">X</code></pre>', '/html/body/div/pre/code/@title', '/html/body/div/pre/code/@title'],
    ['<code>inline</code>', '/html/body/div/code/text()', '/html/body/div/code/text()']] as [$lpGuardHtml, $lpActualPath, $lpCandidatePath]) {
    $lpGuardNode = (new DOMXPath(ContentSlots::html($lpGuardHtml)))->query($lpActualPath)->item(0);
    try { ContentSlots::checkCodeNode($lpGuardNode, ['type' => 'text', 'xpath' => $lpCandidatePath]); $lpGuardRejected = false; }
    catch (RuntimeException $lpError) { $lpGuardRejected = true; }
    $lpCheck('forged candidate or actual CODE cannot bypass validation: ' . $lpActualPath, $lpGuardRejected, true);
}

$lpDir = sys_get_temp_dir() . '/cowork-literal-pre-' . bin2hex(random_bytes(6));
mkdir($lpDir);
try {
    $lpStore = new LiteralPreStore(); $lpLog = new LiteralPreLog(); $lpWriter = new LiteralPreWriter($lpLog, $lpStore);
    $lpRow = ['id' => '120', 'title' => 'Literal demo', 'module' => 'mod_custom', 'position' => 'main', 'published' => '1',
        'access' => '1', 'language' => '*', 'client_id' => '0', 'params' => json_encode(['example' => $lpHtml]), 'content' => $lpHtml . '<pre>Legacy text</pre>', 'note' => 'kept'];
    $lpWriter->rows = ['module' => [120 => $lpRow], 'moduleAssignment' => [120 => ['id' => '120', 'menuids' => '[0]']]];
    $lpSlot = ['key' => 'example.code', 'entity' => 'example', 'column' => 'content', 'type' => 'text', 'sample' => $lpSample,
        'xpath' => $lpPath, 'maxCharacters' => 100, 'requiresEvidence' => true,
        'fit' => ['measured' => true, 'maxCharacters' => 60, 'lines' => ['desktop' => 2, 'phone' => 2]]];
    $lpFiles = ['manifest' => ['id' => 'literal-pre/test/v1'],
        'content-map' => ['entities' => [['key' => 'example', 'kind' => 'module', 'sourceId' => 20, 'identity' => ['title' => 'Literal demo']]], 'slots' => [$lpSlot, array_replace($lpSlot, ['key' => 'example.jsonCode', 'column' => 'params', 'jsonPath' => ['example'], 'requiresEvidence' => false]), ['key' => 'example.prose', 'entity' => 'example', 'column' => 'content', 'type' => 'text', 'sample' => 'Before', 'xpath' => '/html/body/div/h2/text()', 'maxCharacters' => 100], ['key' => 'example.bare', 'entity' => 'example', 'column' => 'content', 'type' => 'text', 'sample' => 'Legacy text', 'xpath' => '/html/body/div/pre[2]/text()', 'maxCharacters' => 100]], 'pages' => []],
        'presentation-lock' => ['entities' => ['example' => array_diff_key($lpRow, ['id' => true])], 'assignments' => [['moduleid' => 20, 'menuid' => 0]],
            'fileRoots' => [], 'files' => [], 'inventoryCounts' => ['module' => 1], 'access' => $lpStore->access()]];
    foreach ($lpFiles as $lpName => $lpBody) file_put_contents($lpDir . '/' . $lpName . '.json', json_encode($lpBody));
    $lpContract = new QuickstartContract($lpWriter, $lpStore, $lpDir, $lpDir);
    $lpEngine = new Engine('literal-pre-test', [], null, null, null, null, $lpWriter, null, $lpLog, null, null, null, $lpContract);
    $lpCall = static fn(string $action, array $params = []) => $lpEngine->handle(['token' => 'literal-pre-test', 'action' => $action, 'params' => $params]);
    $lpInspect = $lpCall('content.contract');
    $lpCheck('public inspect identity', [$lpInspect['ok'], $lpInspect['contract']], [true, 'literal-pre/test/v1']);
    $lpCheck('public bind', $lpCall('content.contract', ['operation' => 'bind'])['ok'], true);
    $lpRevision = $lpInspect['revision']; $lpBaseline = $lpStore->binding;
    $lpParams = ['expected_revision' => $lpRevision, 'changes' => ['example.code' => $lpValue, 'example.jsonCode' => $lpValue, 'example.bare' => 'Ordinary change'], 'evidence' => ['example.code' => 'Fictional customer supplied example']];
    try { $lpPlan = $lpContract->plan($lpParams); $lpPlanned = count($lpPlan['operations']) === 1; }
    catch (Throwable $lpError) { $lpPlanned = $lpError->getMessage(); }
    $lpCheck('revision-aware public plan', $lpPlanned, true);
    $lpApply = $lpCall('content.contract', ['operation' => 'apply', 'apply_id' => 'contract-literal-pre', 'request_id' => 'literal-1'] + $lpParams);
    $lpCheck('public bound apply', $lpApply['ok'], true);
    $lpCheck('public readback exact literal', $lpContract->slotValue($lpWriter->rows['module'][120], $lpSlot), $lpValue);
    $lpAfter = $lpCall('content.contract');
    $lpReadSlots = array_column($lpAfter['slots'], 'current', 'key');
    $lpCheck('Engine inspect reads exact literal in HTML and JSON HTML', [$lpReadSlots['example.code'], $lpReadSlots['example.jsonCode']], [$lpValue, $lpValue]);
    $lpCheck('public bound bare PRE uses ordinary read/write', $lpReadSlots['example.bare'], 'Ordinary change');
    $lpCheck('revision advances', $lpAfter['revision'] !== $lpRevision, true);
    $lpCheck('apply afterRevision matches inspect', $lpApply['afterRevision'] ?? null, $lpAfter['revision']);
    $lpCheck('baseline remains content-only and warnings empty', [$lpStore->binding, $lpAfter['warnings'] ?? []], [$lpBaseline, []]);
    $lpSnapshot = [$lpWriter->rows, $lpLog->rows, $lpStore->binding];
    $lpStale = $lpCall('content.contract', ['operation' => 'apply', 'apply_id' => 'contract-literal-stale', 'request_id' => 'stale-1'] + $lpParams);
    $lpCheck('stale revision refused', [$lpStale['ok'], $lpStale['errors'][0]['code'] ?? null], [false, 'REVISION_STALE']);
    $lpCheck('stale leaves rows receipts baseline unchanged', [$lpWriter->rows, $lpLog->rows, $lpStore->binding], $lpSnapshot);
    $lpCheck('actual contract receipt present', count(array_filter($lpLog->entries('contract-literal-pre'), static fn($e) => $e['op'] === 'contract')), 1);
    $lpUndo = $lpCall('apply.revert', ['apply_id' => 'contract-literal-pre']);
    $lpCheck('actual receipt apply.revert', $lpUndo['ok'], true);
    $lpRestored = $lpCall('content.contract');
    $lpCheck('undo restores exact before-row bytes and baseline', [$lpWriter->rows['module'][120], $lpStore->binding], [$lpRow, $lpBaseline]);
    $lpCheck('undo revision returns and identity holds', [$lpRestored['revision'], $lpRestored['contract']], [$lpRevision, 'literal-pre/test/v1']);
    foreach ([['', 'SLOT_EMPTY_STATE'], ['{loadposition demo}', 'SLOT_NOT_CONTENT'], [str_repeat('<b>', 40), 'SLOT_TOO_LONG'], [str_repeat('<b>', 24), 'SLOT_TOO_LONG'], ["A\0B", 'SLOT_NOT_CONTENT'], ["A\nB\nC", 'SLOT_TOO_LONG']] as [$lpInvalid, $lpCode]) {
        try { $lpContract->plan(['expected_revision' => $lpRevision, 'changes' => ['example.code' => $lpInvalid], 'evidence' => $lpParams['evidence']]); $lpRefused = false; }
        catch (ContractProblem $lpError) { $lpRefused = $lpError->reason === $lpCode; }
        $lpCheck('ordinary guard remains: ' . $lpCode, $lpRefused, true);
    }
    try { $lpContract->plan(['expected_revision' => $lpRevision, 'changes' => ['example.code' => $lpValue]]); $lpEvidence = false; }
    catch (ContractProblem $lpError) { $lpEvidence = $lpError->reason === 'SLOT_EVIDENCE_REQUIRED'; }
    $lpCheck('evidence required', $lpEvidence, true);
    try { $lpContract->plan(['expected_revision' => $lpRevision, 'changes' => ['example.prose' => '<b>Markup</b>']]); $lpProseRefused = false; }
    catch (ContractProblem $lpError) { $lpProseRefused = $lpError->reason === 'SLOT_NOT_CONTENT'; }
    $lpCheck('ordinary prose still refuses markup', $lpProseRefused, true);
    try { $lpContract->plan(['expected_revision' => $lpRevision, 'changes' => ['example.bare' => '<b>Markup</b>']]); $lpBareRefused = false; }
    catch (ContractProblem $lpError) { $lpBareRefused = $lpError->reason === 'SLOT_NOT_CONTENT'; }
    $lpCheck('legacy bare PRE still refuses ordinary markup', $lpBareRefused, true);
    $lpWriter->rows['module'][120]['content'] = '<h2>Before</h2><pre><code>Still a text leaf<span>Nested</span></code></pre><p>After</p>';
    $lpMalformedInspect = $lpCall('content.contract');
    $lpCheck('inspect validates actual current DOM rather than xpath alone', [$lpMalformedInspect['ok'], strpos($lpMalformedInspect['message'] ?? '', 'literal PRE/CODE') !== false], [false, true]);
    $lpBadState = [$lpWriter->rows, $lpLog->rows, $lpStore->binding];
    $lpBadApply = $lpCall('content.contract', ['operation' => 'apply', 'apply_id' => 'contract-literal-drift', 'request_id' => 'drift-1'] + $lpParams);
    $lpCheck('current structure drift refused before write', $lpBadApply['ok'], false);
    $lpCheck('structure refusal has no mutation', [$lpWriter->rows, $lpLog->rows, $lpStore->binding], $lpBadState);
} finally {
    foreach (glob($lpDir . '/*.json') as $lpFile) unlink($lpFile);
    rmdir($lpDir);
}
if ($lpStandalone && $lpFailed) throw new RuntimeException($lpFailed . ' literal PRE assertions failed');
