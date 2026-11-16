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

// Source-backed brace content: this selection leaves unrelated normal cases untouched.
$lpBraceOnly = $lpStandalone && in_array('--literal-braces', $_SERVER['argv'] ?? [], true);
$lpBraceHtml = '<p>Before</p><pre><code>&#123;field 1&#125;</code></pre>';
$lpBraceSlots = array_values(array_filter(ContentSlots::htmlSlots($lpBraceHtml), static fn($s) => $s['xpath'] === $lpPath));
$lpCheck('encoded canonical macro-looking example is literal content', array_column($lpBraceSlots, 'sample'), ['{field 1}']);
$lpBraceValue = "  {loadposition Synthetic}\nλ < X &amp; > {Next}  ";
$lpBraceExpected = '<p>Before</p><pre><code>  &#123;loadposition Synthetic&#125;' . "\n" . 'λ &lt; X &amp;amp; &gt; &#123;Next&#125;  </code></pre>';
try { $lpBracePatched = ContentSlots::htmlPatch($lpBraceHtml, [['xpath' => $lpPath, 'value' => $lpBraceValue]]); }
catch (Throwable $lpBraceError) { $lpBracePatched = $lpBraceError->getMessage(); }
$lpCheck('changed capable CODE emits only numeric braces and escaped text', $lpBracePatched, $lpBraceExpected);
if ($lpBracePatched === $lpBraceExpected)
    $lpCheck('changed brace case whitespace angles and entity-looking text read back exact', $lpRead($lpBracePatched, $lpPath), $lpBraceValue);
$lpCheck('same capable value keeps inert raw encoding', ContentSlots::htmlPatch($lpBraceHtml, [['xpath' => $lpPath, 'value' => '{field 1}']]), $lpBraceHtml);
foreach (['<pre><code>{field 1}</code></pre>', '<pre><code>&#123;field 1}</code></pre>',
    '<pre><code>&#123;field 1&rcub;</code></pre>', '<pre><code>&#123;field 1&#125;<b>nested</b></code></pre>',
    '<pre>mixed<code>&#123;field 1&#125;</code></pre>', '<pre><code>&#123;field 1&#125;<!--gap-->tail</code></pre>',
    '<p>&#123;field 1&#125;</p>', '<code>&#123;field 1&#125;</code>',
    '<!-- <pre><code>&#123;field 1&#125;</code></pre> --><pre><code>{field 1}</code></pre>'] as $lpBraceBad) {
    $lpCheck('raw mixed named nested ordinary or fake source does not gain capture capability',
        array_values(array_filter(ContentSlots::htmlSlots($lpBraceBad), static fn($s) => $s['sample'] === '{field 1}')), []);
}

foreach (['config: ${SYNTHETIC}', 'notify: ${ALERT}', 'names := []string{Alpha, Beta}', 'query{job="synthetic"}', 'token: ${NEXT}'] as $lpBraceGrammar) {
    $lpBraceEncoded = strtr(htmlspecialchars($lpBraceGrammar, ENT_NOQUOTES | ENT_HTML5, 'UTF-8'), ['{' => '&#123;', '}' => '&#125;']);
    $lpCheck('synthetic code grammar is captured only from inert source', array_column(ContentSlots::htmlSlots('<pre><code>' . $lpBraceEncoded . '</code></pre>'), 'sample'), [$lpBraceGrammar]);
}
foreach (['<pre><code>{field 1}</code></pre>', '<pre><code>&#123;field 1}</code></pre>',
    '<pre><code>&#123;field 1&rcub;</code></pre>', '<pre><code>&#123;field 1&#125;<b>nested</b></code></pre>',
    '<pre>mixed<code>&#123;field 1&#125;</code></pre>', '<pre><code>&#123;field 1&#125;<!--gap-->tail</code></pre>',
    '<pre><code>&#123;field 1&#125;</code></pre><pre><code>&#123;field 2&#125;</code></pre>'] as $lpBraceBad) {
    $lpCheck('capability fails closed on actual raw mixed named nested or ambiguous source', ContentSlots::inertLiteralBraceSlot(
        ['content' => $lpBraceBad], ['column' => 'content', 'type' => 'text', 'xpath' => $lpPath, 'inertLiteralBrace' => true]), false);
}
foreach (['&#x7B;field 1&#x7D;', '&#000123;field 1&#000125;'] as $lpBraceSpelling) {
    $lpBraceVariant = '<pre><code>' . $lpBraceSpelling . '</code></pre>';
    $lpCheck('numeric spellings prove the actual receiver including equivalent text[1] address', ContentSlots::inertLiteralBraceSlot(
        ['content' => $lpBraceVariant], ['column' => 'content', 'type' => 'text', 'xpath' => $lpPath . '[1]']), true);
    $lpCheck('sibling serialization preserves original numeric spelling', ContentSlots::htmlPatch('<p>Before</p>' . $lpBraceVariant,
        [['xpath' => '/html/body/div/p/text()', 'value' => 'After']]), '<p>After</p>' . $lpBraceVariant);
}
foreach (['<pre><code>&#123;field 1&#125;</code></pre', '<textarea><pre><code>&#123;field 1&#125;</code></pre></textarea>',
    '<template><pre><code>&#123;field 1&#125;</code></pre></template>', '<pre><code>&amp;#123;field 1&amp;#125;</code></pre>'] as $lpBraceBad)
    $lpCheck('unclosed raw-text or double-encoded source has no capability', ContentSlots::inertLiteralBraceSlot(
        ['content' => $lpBraceBad], ['column' => 'content', 'type' => 'text', 'xpath' => $lpPath]), false);
$lpBraceInjection = '</code><script>{field 1}</script>';
$lpBraceInjectionHtml = ContentSlots::htmlPatch('<pre><code>&#123;field 1&#125;</code></pre>', [['xpath' => $lpPath, 'value' => $lpBraceInjection]]);
$lpCheck('markup-looking replacement stays literal escaped source', $lpBraceInjectionHtml, '<pre><code>&lt;/code&gt;&lt;script&gt;&#123;field 1&#125;&lt;/script&gt;</code></pre>');
$lpCheck('markup-looking replacement exact readback', $lpRead($lpBraceInjectionHtml, $lpPath), $lpBraceInjection);
try { ContentSlots::htmlPatch('<pre><code>plain</code></pre>', [['xpath' => $lpPath, 'value' => '{field 1}']]); $lpBraceRawRefused = false; }
catch (RuntimeException $lpBraceError) { $lpBraceRawRefused = true; }
$lpCheck('unproven direct CODE writer cannot emit raw macro syntax', $lpBraceRawRefused, true);
$lpBraceDir = sys_get_temp_dir() . '/cowork-brace-capability-' . bin2hex(random_bytes(6));
mkdir($lpBraceDir);
try {
    $lpBraceStore = new LiteralPreStore(); $lpBraceLog = new LiteralPreLog();
    $lpBraceWriter = new LiteralPreWriter($lpBraceLog, $lpBraceStore);
    $lpBraceHtml = '<p>Before</p><pre><code>&#123;field 1&#125;</code></pre><pre><code>plain</code></pre>';
    $lpBraceRow = ['id' => '902', 'title' => 'Encoded brace example', 'module' => 'mod_custom', 'position' => 'main',
        'published' => '1', 'access' => '1', 'language' => '*', 'client_id' => '0', 'content' => $lpBraceHtml,
        'params' => json_encode(['example' => $lpBraceHtml, 'config' => json_encode(['example' => $lpBraceHtml])])];
    $lpBraceWriter->rows = ['module' => [902 => $lpBraceRow], 'moduleAssignment' => [902 => ['id' => '902', 'menuids' => '[0]']]];
    $lpBraceSlot = ['key' => 'brace.code', 'entity' => 'brace', 'column' => 'content', 'type' => 'text',
        'sample' => '{field 1}', 'xpath' => '/html/body/div/pre[1]/code/text()', 'maxCharacters' => 500];
    $lpBraceFiles = ['manifest' => ['id' => 'brace-capability/test/v1'],
        'content-map' => ['entities' => [['key' => 'brace', 'kind' => 'module', 'sourceId' => 1,
            'identity' => ['title' => 'Encoded brace example']]], 'slots' => [$lpBraceSlot,
            array_replace($lpBraceSlot, ['key' => 'brace.json', 'column' => 'params', 'jsonPath' => ['example']]),
            array_replace($lpBraceSlot, ['key' => 'brace.nested', 'column' => 'params', 'nestedJson' => 'config', 'jsonPath' => ['example']]),
            array_replace($lpBraceSlot, ['key' => 'brace.prose', 'sample' => 'Before', 'xpath' => '/html/body/div/p/text()']),
            array_replace($lpBraceSlot, ['key' => 'brace.raw', 'sample' => 'plain', 'xpath' => '/html/body/div/pre[2]/code/text()'])], 'pages' => []],
        'presentation-lock' => ['entities' => ['brace' => array_diff_key($lpBraceRow, ['id' => true])],
            'assignments' => [['moduleid' => 1, 'menuid' => 0]], 'fileRoots' => [], 'files' => [],
            'inventoryCounts' => ['module' => 1], 'access' => $lpBraceStore->access()]];
    foreach ($lpBraceFiles as $lpBraceName => $lpBraceFile) file_put_contents($lpBraceDir . '/' . $lpBraceName . '.json', json_encode($lpBraceFile));
    $lpBraceContract = new QuickstartContract($lpBraceWriter, $lpBraceStore, $lpBraceDir, $lpBraceDir);
    $lpBraceEngine = new Engine('literal-brace-test-token', [], null, null, null, null, $lpBraceWriter, null, $lpBraceLog, null, null, null, $lpBraceContract);
    $lpBraceCall = static fn(string $action, array $params = []) => $lpBraceEngine->handle(['token' => 'literal-brace-test-token', 'action' => $action, 'params' => $params]);
    $lpBraceBefore = $lpBraceCall('content.contract');
    $lpCheck('capability public inspect identity', [$lpBraceBefore['ok'], $lpBraceBefore['contract'] ?? null], [true, 'brace-capability/test/v1']);
    $lpCheck('capability public bind', $lpBraceCall('content.contract', ['operation' => 'bind'])['ok'], true);
    $lpBraceBaseline = $lpBraceStore->binding;
    $lpBraceValue = "  {loadposition Synthetic}\nλ < X &amp; > {Next}  ";
    $lpBraceChanges = ['brace.code' => $lpBraceValue, 'brace.json' => $lpBraceValue, 'brace.nested' => $lpBraceValue];
    $lpBraceParams = ['expected_revision' => $lpBraceBefore['revision'], 'changes' => $lpBraceChanges];
    try { $lpBracePlan = count($lpBraceContract->plan($lpBraceParams)['operations']); } catch (ContractProblem $lpBraceError) { $lpBracePlan = $lpBraceError->reason; }
    $lpCheck('capability revision-aware bound plan', $lpBracePlan, 1);
    $lpBraceState = $lpBraceContract->inspect();
    $lpBraceTranslation = new ReflectionMethod(Engine::class, 'translationProblems');
    if (PHP_VERSION_ID < 80100) $lpBraceTranslation->setAccessible(true);
    $lpBraceTranslated = ['key' => MultilingualProfile::derivedKey('vi-VN', 'brace.code'), 'sourceKey' => 'brace.code', 'source' => '{field 1}'] + $lpBraceSlot;
    $lpCheck('translation validator uses resolved source capability', $lpBraceTranslation->invoke($lpBraceEngine,
        [$lpBraceTranslated], [$lpBraceTranslated['key'] => $lpBraceValue], $lpBraceState), []);
    $lpBraceForged = array_replace($lpBraceTranslated, ['xpath' => '/html/body/div/p/text()', 'inertLiteralBrace' => true]);
    $lpCheck('forged translation address cannot borrow CODE capability', count($lpBraceTranslation->invoke($lpBraceEngine,
        [$lpBraceForged], [$lpBraceForged['key'] => '{field 1}'], $lpBraceState)) > 0, true);
    $lpCheck('translation without actual resolved rows has no capability', count($lpBraceTranslation->invoke($lpBraceEngine,
        [$lpBraceTranslated], [$lpBraceTranslated['key'] => '{field 1}'])) > 0, true);
    $lpBraceApplied = $lpBraceCall('content.contract', ['operation' => 'apply', 'apply_id' => 'contract-brace-capability',
        'request_id' => 'brace-capability-1'] + $lpBraceParams);
    $lpCheck('capability public bound HTML JSONHTML and nested JSONHTML apply', $lpBraceApplied['ok'], true);
    if ($lpBraceApplied['ok']) {
        $lpBraceExpected = '<p>Before</p><pre><code>  &#123;loadposition Synthetic&#125;' . "\n" . 'λ &lt; X &amp;amp; &gt; &#123;Next&#125;  </code></pre><pre><code>plain</code></pre>';
        $lpBraceActual = $lpBraceWriter->rows['module'][902]; $lpBraceJson = json_decode($lpBraceActual['params'], true);
        $lpCheck('all three stored source representations stay inert', [$lpBraceActual['content'], $lpBraceJson['example'], json_decode($lpBraceJson['config'], true)['example']], array_fill(0, 3, $lpBraceExpected));
        $lpBraceAfter = $lpBraceCall('content.contract');
        $lpCheck('all three public decoded readbacks are exact', array_intersect_key(array_column($lpBraceAfter['slots'], 'current', 'key'), $lpBraceChanges), $lpBraceChanges);
        $lpCheck('bound revision advances with stable baseline and warnings', [$lpBraceAfter['revision'] !== $lpBraceBefore['revision'], $lpBraceStore->binding, $lpBraceAfter['warnings'] ?? []], [true, $lpBraceBaseline, []]);
        foreach (['brace.raw', 'brace.prose'] as $lpBraceBadKey) {
            $lpBraceSaved = [$lpBraceWriter->rows, $lpBraceLog->rows, $lpBraceStore->binding];
            $lpBraceRefused = $lpBraceCall('content.contract', ['operation' => 'apply', 'apply_id' => 'contract-brace-forged',
                'request_id' => 'brace-forged-1', 'expected_revision' => $lpBraceAfter['revision'], 'inertLiteralBrace' => true, 'changes' => [$lpBraceBadKey => '{field 1}']]);
            $lpCheck('caller boolean cannot make raw CODE or ordinary prose capable', [$lpBraceRefused['ok'], $lpBraceRefused['errors'][0]['code'] ?? null], [false, 'SLOT_NOT_CONTENT']);
            $lpCheck('refusal leaves rows receipt baseline unchanged', [$lpBraceWriter->rows, $lpBraceLog->rows, $lpBraceStore->binding], $lpBraceSaved);
        }
        $lpCheck('capable write has actual contract receipt', count(array_filter($lpBraceLog->entries('contract-brace-capability'), static fn($e) => $e['op'] === 'contract')), 1);
        $lpCheck('capable actual receipt revert', $lpBraceCall('apply.revert', ['apply_id' => 'contract-brace-capability'])['ok'], true);
        $lpCheck('capable revert restores exact original row revision and baseline', [$lpBraceWriter->rows['module'][902], $lpBraceCall('content.contract')['revision'], $lpBraceStore->binding], [$lpBraceRow, $lpBraceBefore['revision'], $lpBraceBaseline]);
        // The decoded text and map are unchanged; only actual current source encoding was removed.
        $lpBraceWriter->rows['module'][902]['content'] = str_replace(['&#123;', '&#125;'], ['{', '}'], $lpBraceHtml);
        $lpBraceUnproven = $lpBraceCall('content.contract');
        $lpBraceSaved = [$lpBraceWriter->rows, $lpBraceLog->rows, $lpBraceStore->binding];
        $lpBraceRefused = $lpBraceCall('content.contract', ['operation' => 'apply', 'apply_id' => 'contract-brace-source-lost',
            'request_id' => 'brace-source-lost-1', 'expected_revision' => $lpBraceUnproven['revision'], 'inertLiteralBrace' => true, 'changes' => ['brace.code' => '{field 1}']]);
        $lpCheck('actual current raw source cannot borrow original map capability', [$lpBraceRefused['ok'], $lpBraceRefused['errors'][0]['code'] ?? null], [false, 'SLOT_NOT_CONTENT']);
        $lpCheck('lost source capability refusal preserves every state', [$lpBraceWriter->rows, $lpBraceLog->rows, $lpBraceStore->binding], $lpBraceSaved);
        $lpBraceUnprovenState = $lpBraceContract->inspect();
        $lpCheck('translation rechecks actual raw row despite same decoded source', count($lpBraceTranslation->invoke($lpBraceEngine,
            [$lpBraceTranslated], [$lpBraceTranslated['key'] => '{field 1}'], $lpBraceUnprovenState)) > 0, true);

    }
} finally {
    foreach (glob($lpBraceDir . '/*.json') as $lpBraceFile) unlink($lpBraceFile);
    rmdir($lpBraceDir);
}
if ($lpBraceOnly) {
    if ($lpFailed) throw new RuntimeException($lpFailed . ' source-backed brace assertions failed');
    return;
}

// A focused standalone selection runs this regression without replaying older literal-PRE cases.
$lpInertOnly = $lpStandalone && in_array('--inert-braces', $_SERVER['argv'] ?? [], true);
foreach (['&#123;loadposition synthetic&#125;', '&#x7B;field 1&#x7D;', '&#000123;emailcloak=off&#000125;'] as $lpInertBody) {
    $lpInertHtml = '<p>Before</p><pre><code>' . $lpInertBody . '</code></pre>';
    $lpInertAfter = ContentSlots::htmlPatch($lpInertHtml, [['xpath' => '/html/body/div/p/text()', 'value' => 'After']]);
    $lpCheck('inert brace source survives sibling edit: ' . $lpInertBody,
        $lpInertAfter, '<p>After</p><pre><code>' . $lpInertBody . '</code></pre>');
    $lpCheck('inert brace decoded CODE unchanged', $lpRead($lpInertAfter, $lpPath), $lpRead($lpInertHtml, $lpPath));
    $lpCheck('source-encoded canonical example is captured as inert content', array_values(array_filter(ContentSlots::htmlSlots($lpInertAfter),
        static fn($s) => $s['xpath'] === $lpPath)), [['xpath' => $lpPath, 'type' => 'text',
            'sample' => html_entity_decode($lpInertBody, ENT_QUOTES | ENT_HTML5, 'UTF-8')]]);
}
$lpInertMultiple = '<p>Before</p><pre><code>&#123;loadposition one&#125;</code></pre><pre><code>&#123;field 1&#125;</code></pre>';
$lpCheck('multiple inert source spans retain independent receivers', ContentSlots::htmlPatch($lpInertMultiple,
    [['xpath' => '/html/body/div/p/text()', 'value' => 'After']]), str_replace('Before', 'After', $lpInertMultiple));
$lpCheck('a changed CODE leaf is not restored over its replacement', ContentSlots::htmlPatch(
    '<p>Before</p><pre><code>&#123;field 1&#125;</code></pre>', [['xpath' => $lpPath, 'value' => 'Plain replacement']]),
    '<p>Before</p><pre><code>Plain replacement</code></pre>');
$lpInertComment = '<!-- <pre><code>&#123;field 9&#125;</code></pre> --><p>Before</p><pre><code>&#123;field 1&#125;</code></pre>';
$lpCheck('a comment cannot supply the source receiver', ContentSlots::htmlPatch($lpInertComment,
    [['xpath' => '/html/body/div/p/text()', 'value' => 'After']]), str_replace('Before', 'After', $lpInertComment));
foreach (['<pre><code>&#123;field 1&#125;<span>nested</span></code></pre>',
    '<pre><code>&#123;field 1&#125;<!--gap-->tail</code></pre>', '<pre>mixed<code>&#123;field 1&#125;</code></pre>',
    '<pre><code>&#123;field 1&#125;</code></pre><pre><code>&#123;field 2&#125;</code></pre>'] as $lpInertBad) {
    try { ContentSlots::htmlPatch($lpInertBad, [['xpath' => $lpPath, 'value' => 'Replacement']]); $lpInertRefused = false; }
    catch (RuntimeException $lpInertError) { $lpInertRefused = true; }
    $lpCheck('inert source does not widen malformed or ambiguous CODE writes', $lpInertRefused, true);
}
$lpCheck('raw plugin directive capture still refuses', ContentSlots::htmlSlots('<pre><code>{loadposition synthetic}</code></pre>'), []);
$lpInertDir = sys_get_temp_dir() . '/cowork-inert-braces-' . bin2hex(random_bytes(6));
mkdir($lpInertDir);
try {
    $lpInertStore = new LiteralPreStore(); $lpInertLog = new LiteralPreLog();
    $lpInertWriter = new LiteralPreWriter($lpInertLog, $lpInertStore);
    $lpInertHtml = '<p>Before</p><pre><code>&#123;loadposition synthetic&#125;</code></pre><pre><code>safe</code></pre>';
    $lpInertRow = ['id' => '901', 'title' => 'Inert brace example', 'module' => 'mod_custom', 'position' => 'main',
        'published' => '1', 'access' => '1', 'language' => '*', 'client_id' => '0', 'content' => $lpInertHtml,
        'params' => json_encode(['example' => $lpInertHtml])];
    $lpInertWriter->rows = ['module' => [901 => $lpInertRow], 'moduleAssignment' => [901 => ['id' => '901', 'menuids' => '[0]']]];
    $lpInertSlot = ['key' => 'inert.prose', 'entity' => 'inert', 'column' => 'content', 'type' => 'text',
        'sample' => 'Before', 'xpath' => '/html/body/div/p/text()', 'maxCharacters' => 200];
    $lpInertFiles = ['manifest' => ['id' => 'inert-braces/test/v1'],
        'content-map' => ['entities' => [['key' => 'inert', 'kind' => 'module', 'sourceId' => 1,
            'identity' => ['title' => 'Inert brace example']]], 'slots' => [$lpInertSlot,
            array_replace($lpInertSlot, ['key' => 'inert.jsonProse', 'column' => 'params', 'jsonPath' => ['example']]),
            array_replace($lpInertSlot, ['key' => 'inert.code', 'sample' => 'safe', 'xpath' => '/html/body/div/pre[2]/code/text()'])], 'pages' => []],
        'presentation-lock' => ['entities' => ['inert' => array_diff_key($lpInertRow, ['id' => true])],
            'assignments' => [['moduleid' => 1, 'menuid' => 0]], 'fileRoots' => [], 'files' => [],
            'inventoryCounts' => ['module' => 1], 'access' => $lpInertStore->access()]];
    foreach ($lpInertFiles as $lpInertName => $lpInertFile) file_put_contents($lpInertDir . '/' . $lpInertName . '.json', json_encode($lpInertFile));
    $lpInertContract = new QuickstartContract($lpInertWriter, $lpInertStore, $lpInertDir, $lpInertDir);
    $lpInertEngine = new Engine('inert-braces-test-token', [], null, null, null, null, $lpInertWriter, null, $lpInertLog, null, null, null, $lpInertContract);
    $lpInertCall = static fn(string $action, array $params = []) => $lpInertEngine->handle(['token' => 'inert-braces-test-token', 'action' => $action, 'params' => $params]);
    $lpInertBefore = $lpInertCall('content.contract');
    if (!$lpInertBefore['ok']) throw new RuntimeException(json_encode($lpInertBefore));
    $lpCheck('inert public inspect and identity', [$lpInertBefore['ok'], $lpInertBefore['contract']], [true, 'inert-braces/test/v1']);
    $lpCheck('inert public bind', $lpInertCall('content.contract', ['operation' => 'bind'])['ok'], true);
    $lpInertBaseline = $lpInertStore->binding;
    $lpInertParams = ['expected_revision' => $lpInertBefore['revision'], 'changes' => ['inert.prose' => 'After', 'inert.jsonProse' => 'After']];
    $lpCheck('inert revision-aware plan', count($lpInertContract->plan($lpInertParams)['operations']), 1);
    $lpInertApply = $lpInertCall('content.contract', ['operation' => 'apply', 'apply_id' => 'contract-inert-sibling',
        'request_id' => 'inert-sibling-1'] + $lpInertParams);
    $lpCheck('inert public bound sibling apply', $lpInertApply['ok'], true);
    $lpInertExpected = str_replace('Before', 'After', $lpInertHtml);
    $lpCheck('bound content retains encoded source', $lpInertWriter->rows['module'][901]['content'], $lpInertExpected);
    $lpCheck('bound JSON HTML retains encoded source', json_decode($lpInertWriter->rows['module'][901]['params'], true)['example'], $lpInertExpected);
    $lpCheck('bound decoded CODE exact', $lpRead($lpInertWriter->rows['module'][901]['content'], '/html/body/div/pre[1]/code/text()'), '{loadposition synthetic}');
    $lpInertAfter = $lpInertCall('content.contract');
    $lpCheck('inert inspect/readback', array_column($lpInertAfter['slots'], 'current', 'key'),
        ['inert.prose' => 'After', 'inert.jsonProse' => 'After', 'inert.code' => 'safe']);
    $lpCheck('inert revision advances', $lpInertAfter['revision'] !== $lpInertBefore['revision'], true);
    $lpCheck('inert baseline and warnings unchanged', [$lpInertStore->binding, $lpInertAfter['warnings'] ?? []], [$lpInertBaseline, []]);
    $lpInertState = [$lpInertWriter->rows, $lpInertLog->rows, $lpInertStore->binding];
    $lpInertRaw = $lpInertCall('content.contract', ['operation' => 'apply', 'apply_id' => 'contract-inert-refusal',
        'request_id' => 'inert-refusal-1', 'expected_revision' => $lpInertAfter['revision'], 'changes' => ['inert.code' => '{loadposition synthetic}']]);
    $lpCheck('inert protection does not permit raw directive write', [$lpInertRaw['ok'], $lpInertRaw['errors'][0]['code'] ?? null], [false, 'SLOT_NOT_CONTENT']);
    $lpCheck('inert directive refusal keeps rows receipts baseline', [$lpInertWriter->rows, $lpInertLog->rows, $lpInertStore->binding], $lpInertState);
    $lpCheck('inert actual contract receipt', count(array_filter($lpInertLog->entries('contract-inert-sibling'), static fn($e) => $e['op'] === 'contract')), 1);
    $lpCheck('inert actual receipt revert', $lpInertCall('apply.revert', ['apply_id' => 'contract-inert-sibling'])['ok'], true);
    $lpCheck('inert exact original row and baseline restored', [$lpInertWriter->rows['module'][901], $lpInertStore->binding], [$lpInertRow, $lpInertBaseline]);
    $lpCheck('inert original revision restored', $lpInertCall('content.contract')['revision'], $lpInertBefore['revision']);
} finally {
    foreach (glob($lpInertDir . '/*.json') as $lpInertFile) unlink($lpInertFile);
    rmdir($lpInertDir);
}
if ($lpInertOnly) {
    if ($lpFailed) throw new RuntimeException($lpFailed . ' inert brace assertions failed');
    return;
}

foreach (["a <= b\nnext < value", "λ < value &amp; &lt; &amp;lt;", "a <\tb"] as $lpRaw) {
    $lpRawHtml = '<PRE title="quoted > boundary"><CODE>' . $lpRaw . '</CODE></PRE>';
    $lpDecoded = html_entity_decode($lpRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lpRawSlots = array_values(array_filter(ContentSlots::htmlSlots($lpRawHtml), static fn($s) => $s['xpath'] === $lpPath));
    $lpCheck('raw comparison capture preserves decoded bytes: ' . $lpRaw, $lpRawSlots[0]['sample'] ?? null, $lpDecoded);
    $lpCheck('shared current-value reader preserves raw comparison: ' . $lpRaw, $lpRead($lpRawHtml, $lpPath), $lpDecoded);
    foreach ([$lpDecoded, "new <= value & literal"] as $lpReplacement) {
        $lpCheck('raw comparison write/readback', $lpRead(ContentSlots::htmlPatch($lpRawHtml,
            [['xpath' => $lpPath, 'value' => $lpReplacement]]), $lpPath), $lpReplacement);
    }
}
$lpNativeDom = static function (string $html): string {
    $doc = new DOMDocument('1.0', 'UTF-8'); $old = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="contract-root">' . $html . '</div></body></html>', LIBXML_NONET);
    libxml_clear_errors(); libxml_use_internal_errors($old);
    return $doc->saveHTML();
};
foreach ([
    '<script>"<pre><code>a <= b</code></pre>"</script>',
    '<style>/* <pre><code>a <= b</code></pre> */</style>',
    '<textarea><pre><code>a <= b</code></pre></textarea>',
    '<svg><pre><code>a <= b</code></pre></svg>',
    '<div title="<pre><code>a <= b</code></pre>">Text</div>',
    '<!-- <pre><code>a <= b</code></pre> -->',
    '<pre><pre><code>a <= b</code></pre></pre>',
    '<pre><code>a <= b<span>nested</span></code></pre>',
    '<pre><code>a <= b<!-- comment --></code></pre>',
    '<pre>mixed<code>a <= b</code></pre>',
    '<pre><code>a <= b</code>',
    '<pre><code>a <= b<Widget>other</Widget></code></pre>',
    '<div title="unterminated <pre><code>a <= b</code></pre>',
    '<pre><code>{loadposition demo} a <= b</code></pre>'
] as $lpUnsupported) {
    $lpCheck('unsupported comparator context keeps native parse: ' . $lpUnsupported,
        ContentSlots::html($lpUnsupported)->saveHTML(), $lpNativeDom($lpUnsupported));
}
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
    $lpCheck('decoded literal round trip: ' . json_encode($lpLiteral), $lpRoundTrip, str_replace("\r\n", "\n", $lpLiteral));
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
