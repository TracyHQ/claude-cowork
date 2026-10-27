<?php
/**
 * Rendered words, the door: content.renderedWords {inspect, apply, status} and apply.revert, through the
 * real option writer over the WordPress stubs, with the page itself a fake site (what the loopback sees).
 *
 * Loaded by run.php and by rendered-words-run.php after rendered-words.php (uses check(), $WTOKEN).
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/RenderedWords.php';

echo "\nRendered words: the door\n";

/** The page as the site's own loopback reads it: the theme's HTML, and with the hooks' rules for a visitor. */
final class FakeRenderedSite implements RenderedWordsSite
{
    public string $page;
    public int $code = 200;
    public int $post = 41;
    public string $locale = 'en_US';
    public string $theme = 'theme-diner-1';
    /** A page cache that kept the old page: a visitor reads the theme's words. */
    public bool $cached = false;
    public int $visitCode = 200;
    public int $otherBytes = 0;
    /** @var list<string> */
    public array $purged = [];
    public ?array $drift = null;

    public function __construct(string $page)
    {
        $this->page = $page;
    }

    public function probe(string $path): array
    {
        return ['code' => $this->code, 'body' => $this->page, 'post' => $this->post, 'locale' => $this->locale, 'theme' => $this->theme];
    }

    public function visit(string $path): array
    {
        if ($this->visitCode !== 200 || $this->cached) {
            return ['code' => $this->visitCode, 'body' => $this->page];
        }
        $set = RenderedWords::decodeSet(WP_Fake::$options[RenderedWords::optionKey($this->post, $this->locale)] ?? '');
        $rules = $set !== [] && $set['theme'] === $this->theme ? $set['rules'] : [];
        return ['code' => 200, 'body' => RenderedWords::render($this->page, $rules)['html']];
    }

    public function theme(): string
    {
        return $this->theme;
    }

    public function storedBytes(): int
    {
        $sum = $this->otherBytes;
        foreach (WP_Fake::$options as $key => $value) {
            $sum += RenderedWords::isOption((string) $key) && $key !== RenderedWords::RECEIPTS_OPTION ? strlen((string) $value) : 0;
        }
        return $sum;
    }

    public function purge(string $optionKey): void
    {
        $this->purged[] = $optionKey;
    }

    public function drift(int $post, string $locale): ?array
    {
        return $this->drift;
    }
}

/** The real writer, except that one option's write fails: a database that refused the receipt. */
final class ReceiptFailingWriter implements SiteWriter
{
    private SiteWriter $inner;
    public bool $fail = false;

    public function __construct(SiteWriter $inner)
    {
        $this->inner = $inner;
    }

    public function read(string $kind, int $id, string $key = ''): ?array { return $this->inner->read($kind, $id, $key); }
    public function write(string $kind, int $id, array $fields, string $key = ''): int
    {
        if ($this->fail && $key === RenderedWords::RECEIPTS_OPTION) {
            throw new RuntimeException('database refused the write');
        }
        return $this->inner->write($kind, $id, $fields, $key);
    }
    public function editLock(int $postId): ?array { return $this->inner->editLock($postId); }
    public function delete(string $kind, int $id, string $key = ''): void { $this->inner->delete($kind, $id, $key); }
    public function canTrash(string $kind): bool { return $this->inner->canTrash($kind); }
    public function trash(string $kind, int $id): void { $this->inner->trash($kind, $id); }
    public function purgeCache(): void { $this->inner->purgeCache(); }
}

WP_Fake::reset();
WP_Fake::$options = [];
WP_Fake::$autoload = [];
$rdPage = (string) file_get_contents(__DIR__ . '/fixtures/rendered-words/contact.html');
$rdSite = new FakeRenderedSite($rdPage);
$rdWriter = new ReceiptFailingWriter(new Claude_Cowork_Site_Writer());
$rdLog = new FakeApplyLog();
$rdEngine = (new Engine($WTOKEN, [], null, null, null, null, $rdWriter, null, $rdLog))->renderedWordsSite($rdSite);
$rdCall = static fn(string $action, array $params): array => $rdEngine->handle(['token' => $WTOKEN, 'action' => $action, 'params' => $params]);
$rdDoor = static fn(string $operation, array $params): array => $rdCall('content.renderedWords', ['operation' => $operation] + $params);
$rdKey = RenderedWords::optionKey(41, 'en_US');
$rdPhone = '(084) 555-0110';
$rdSiteUrl = 'http://joomlart.com';
$rdTargets = [['kind' => 'text', 'old' => $rdPhone], ['kind' => 'attribute', 'name' => 'href', 'old' => $rdSiteUrl]];
$rdRules = static function (array $inspected, string $phone, string $href): array {
    return [
        ['kind' => 'text', 'anchor' => $inspected['targets'][0]['candidates'][0]['anchor'], 'old' => '(084) 555-0110', 'new' => $phone, 'expected_matches' => 1],
        ['kind' => 'attribute', 'name' => 'href', 'anchor' => $inspected['targets'][1]['candidates'][0]['anchor'], 'old' => 'http://joomlart.com', 'new' => $href, 'expected_matches' => 4],
    ];
};
$rdApply = static fn(array $inspected, string $apply, string $request, array $rules, ?int $revision = null): array => $rdDoor('apply', ['apply_id' => $apply,
    'request_id' => $request, 'expected_revision' => $revision ?? $inspected['revision'], 'rules' => $rules, 'proof' => $inspected['proof']]);

$rdBare = (new Engine($WTOKEN, [], null, null, null, null, $rdWriter, null, $rdLog))->handle(['token' => $WTOKEN, 'action' => 'content.renderedWords', 'params' => ['operation' => 'inspect']]);
check('a receiver with no site wired answers unavailable', $rdBare['error'] ?? null, 'unavailable');
$rdInfo = $rdCall('info', []);
check('info: the door, its operations and its limits', [$rdInfo['info']['rendered_words']['operations'] ?? null, $rdInfo['info']['rendered_words']['max_rules_request'] ?? null],
    [['inspect', 'apply', 'status'], 16]);
check('inspect: a full URL is not a path', $rdDoor('inspect', ['path' => 'http://example.test/contact-us/', 'targets' => $rdTargets])['error'] ?? null, 'bad_params');
check('inspect: a protocol-relative path is refused', $rdDoor('inspect', ['path' => '//evil.test/', 'targets' => $rdTargets])['error'] ?? null, 'bad_params');
check('inspect: another attribute is refused', $rdDoor('inspect', ['path' => '/contact-us/', 'targets' => [['kind' => 'attribute', 'name' => 'class', 'old' => 'x']]])['error'] ?? null, 'bad_params');
check('inspect: no targets is refused', $rdDoor('inspect', ['path' => '/contact-us/', 'targets' => []])['error'] ?? null, 'bad_params');

$rdIn = $rdDoor('inspect', ['path' => '/contact-us/', 'targets' => $rdTargets]);
check('inspect: the scope, revision 0, both places of the phone and the links, a proof',
    [$rdIn['ok'] ?? $rdIn, $rdIn['scope'] ?? null, $rdIn['revision'] ?? null, count($rdIn['targets'][0]['candidates'] ?? []), $rdIn['targets'][1]['candidates'][0]['count'] ?? null, is_string($rdIn['proof'] ?? null)],
    [true, ['entity' => ['kind' => 'post', 'id' => 41], 'locale' => 'en_US'], 0, 2, 4, true]);
check('inspect writes nothing', [WP_Fake::$options, $rdLog->entries('rw-1')], [[], []]);
$rdSite->code = 404;
check('inspect: a page that does not answer 200 is unsupported', $rdDoor('inspect', ['path' => '/gone/', 'targets' => $rdTargets])['error'] ?? null, 'unsupported');
$rdSite->code = 200;

// Refused before anything is read or written.
$rdGood = $rdRules($rdIn, '+84 28 3822 0000', 'tel:+842838220000');
check('apply: no request_id is refused', $rdDoor('apply', ['apply_id' => 'rw-1', 'expected_revision' => 0, 'rules' => $rdGood, 'proof' => $rdIn['proof']])['error'] ?? null, 'bad_params');
check('apply: a contract- apply_id is refused', $rdApply($rdIn, 'contract-x', 'req-1', $rdGood)['error'] ?? null, 'bad_params');
check('apply: a rule that names its own id is refused', $rdApply($rdIn, 'rw-1', 'req-1', [['id' => 'x'] + $rdGood[0]])['error'] ?? null, 'bad_params');
check('apply: the same place and words twice is refused', $rdApply($rdIn, 'rw-1', 'req-1', [$rdGood[0], ['new' => 'x'] + $rdGood[0]])['error'] ?? null, 'bad_params');
check('apply: 17 rules is refused', $rdApply($rdIn, 'rw-1', 'req-1', array_fill(0, 17, $rdGood[0]))['error'] ?? null, 'bad_params');
check('apply: a javascript: address is refused', $rdApply($rdIn, 'rw-1', 'req-1', [['new' => 'javascript:alert(1)'] + $rdGood[1]])['error'] ?? null, 'bad_params');
$rdExpired = RenderedWords::sign(RenderedWords::key($WTOKEN), ['v' => 1, 'path' => '/contact-us/', 'post' => 41, 'locale' => 'en_US', 'revision' => 0, 'theme' => 'theme-diner-1', 'exp' => time() - 1]);
check('apply: an expired proof is stale', $rdApply(['proof' => $rdExpired] + $rdIn, 'rw-1', 'req-1', $rdGood)['error'] ?? null, 'stale_proof');
$rdForeign = RenderedWords::sign(RenderedWords::key('another-site-token-0123'), ['v' => 1, 'path' => '/contact-us/', 'post' => 41, 'locale' => 'en_US', 'revision' => 0, 'theme' => 'theme-diner-1', 'exp' => time() + 60]);
check('apply: another site\'s proof is stale', $rdApply(['proof' => $rdForeign] + $rdIn, 'rw-1', 'req-1', $rdGood)['error'] ?? null, 'stale_proof');
$rdSite->theme = 'theme-other';
check('apply: a theme switched since the inspection is a conflict', $rdApply($rdIn, 'rw-1', 'req-1', $rdGood)['error'] ?? null, 'conflict');
$rdSite->theme = 'theme-diner-1';
$rdSite->page = str_replace('<li><a class="button-twitter" href="http://joomlart.com"><span>twitter</span></a></li>', '', $rdPage);
$rdDrifted = $rdApply($rdIn, 'rw-1', 'req-1', $rdGood);
check('apply: a page that changed since the inspection (3 links, not 4) is drift, nothing written', [$rdDrifted['error'] ?? null, count($rdDrifted['problems'] ?? []), WP_Fake::$options, $rdLog->entries('rw-1')],
    ['drift', 1, [], []]);
$rdSite->page = $rdPage;
$rdSite->otherBytes = RenderedWords::MAX_SITE_BYTES;
check('apply: past the site\'s byte budget is too_large', $rdApply($rdIn, 'rw-1', 'req-1', $rdGood)['error'] ?? null, 'too_large');
$rdSite->otherBytes = 0;
check('content.update cannot write a rules option, nor the receipts', [
    $rdCall('content.update', ['kind' => 'option', 'key' => $rdKey, 'apply_id' => 'x-1', 'fields' => ['value' => '{}']])['error'] ?? null,
    $rdCall('content.delete', ['kind' => 'option', 'key' => RenderedWords::RECEIPTS_OPTION, 'apply_id' => 'x-1'])['error'] ?? null], ['bad_params', 'bad_params']);

// Apply.
$rdA = $rdApply($rdIn, 'rw-1', 'req-1', $rdGood);
$rdStored = RenderedWords::decodeSet(WP_Fake::$options[$rdKey] ?? '');
check('apply: stored, revision 1, verified on the page a visitor reads, the page caches dropped',
    [$rdA['ok'] ?? $rdA, $rdA['revision'] ?? null, $rdA['render'] ?? null, $rdA['written'] ?? null, $rdSite->purged], [true, 1, 'verified', [['kind' => 'option', 'id' => 0, 'key' => $rdKey]], [$rdKey]]);
check('apply: the set holds the scope, the theme and both rules under this apply_id',
    [$rdStored['post'] ?? null, $rdStored['locale'] ?? null, $rdStored['theme'] ?? null, array_column($rdStored['rules'] ?? [], 'apply_id'), array_column($rdStored['rules'] ?? [], 'new')],
    [41, 'en_US', 'theme-diner-1', ['rw-1', 'rw-1'], ['+84 28 3822 0000', 'tel:+842838220000']]);
check('apply: the rules option is not autoloaded', WP_Fake::$autoload[$rdKey] ?? 'unset', false);
check('apply: one log step, with the before-image (none) and the revision it wrote', [count($rdLog->entries('rw-1')), array_key_exists('before', $rdLog->entries('rw-1')[0]) ? $rdLog->entries('rw-1')[0]['before'] : 'x', $rdLog->entries('rw-1')[0]['renderedWords'] ?? null],
    [1, null, ['request' => 'req-1', 'afterRevision' => 1, 'afterDigest' => hash('sha256', (string) WP_Fake::$options[$rdKey])]]);
$rdVisitor = $rdSite->visit('/contact-us/')['body'];
check('the visitor\'s page: the phone and the four links replaced, the form untouched',
    [substr_count($rdVisitor, '+84 28 3822 0000'), substr_count($rdVisitor, 'href="tel:+842838220000"'), strpos($rdVisitor, substr($rdPage, (int) strpos($rdPage, '<form'), 200)) !== false],
    [1, 4, true]);

// Retries.
$rdIn2 = $rdDoor('inspect', ['path' => '/contact-us/', 'targets' => $rdTargets]);
$rdReplay = $rdApply($rdIn2, 'rw-1', 'req-1', $rdGood, 0);
check('retry: the same request again (a fresh proof) answers its first answer, writes nothing',
    [$rdReplay['ok'] ?? $rdReplay, $rdReplay['replayed'] ?? null, $rdReplay['revision'] ?? null, RenderedWords::decodeSet(WP_Fake::$options[$rdKey])['revision'], count($rdLog->entries('rw-1'))],
    [true, true, 1, 1, 1]);
check('retry: the same request_id for other words is a conflict', $rdApply($rdIn2, 'rw-1', 'req-1', [['new' => '+84 9'] + $rdGood[0]], 0)['error'] ?? null, 'conflict');
check('a used apply_id under a new request_id is refused', $rdApply($rdIn2, 'rw-1', 'req-2', [['new' => '+84 9'] + $rdGood[0]], 1)['error'] ?? null, 'bad_params');
check('an old revision is a conflict', $rdApply($rdIn, 'rw-2', 'req-2', [['new' => '+84 9'] + $rdGood[0]], 0)['error'] ?? null, 'conflict');
check('an expected revision the proof did not see is a conflict', $rdApply($rdIn, 'rw-2', 'req-2', [['new' => '+84 9'] + $rdGood[0]], 1)['error'] ?? null, 'conflict');

// A second apply on the same page: the phone again, merged by rule id.
$rdB = $rdApply($rdIn2, 'rw-2', 'req-2', [['new' => '+84 28 0000 1111'] + $rdGood[0]]);
$rdStoredB = RenderedWords::decodeSet(WP_Fake::$options[$rdKey]);
check('second apply: revision 2, the phone replaced (one rule, latest), the links kept',
    [$rdB['ok'] ?? $rdB, $rdB['revision'] ?? null, array_column($rdStoredB['rules'], 'new'), array_column($rdStoredB['rules'], 'apply_id')],
    [true, 2, ['+84 28 0000 1111', 'tel:+842838220000'], ['rw-2', 'rw-1']]);

// Another locale of the same page is its own option.
$rdSite->locale = 'vi';
$rdVi = $rdDoor('inspect', ['path' => '/vi/lien-he/', 'targets' => $rdTargets]);
$rdViA = $rdApply($rdVi, 'rw-vi', 'req-vi', $rdRules($rdVi, '028 3822 0000', 'tel:02838220000'));
check('locale: vi is its own scope at its own revision 1', [$rdVi['revision'] ?? null, $rdViA['ok'] ?? $rdViA, $rdViA['revision'] ?? null, isset(WP_Fake::$options[RenderedWords::optionKey(41, 'vi')])],
    [0, true, 1, true]);
$rdSite->locale = 'en_US';

// Revert: newest first; the older one conflicts while a later apply stands.
$rdEarly = $rdCall('apply.revert', ['apply_id' => 'rw-1']);
check('revert: an older apply under a later one is a conflict, nothing changed', [$rdEarly['error'] ?? null, RenderedWords::decodeSet(WP_Fake::$options[$rdKey])['revision']], ['conflict', 2]);
$rdRevB = $rdCall('apply.revert', ['apply_id' => 'rw-2']);
check('revert: the later one restores revision 1 exactly', [$rdRevB['ok'] ?? $rdRevB, WP_Fake::$options[$rdKey] === RenderedWords::encodeSet($rdStored)], [true, true]);
$rdRevA = $rdCall('apply.revert', ['apply_id' => 'rw-1']);
check('revert: then the first, and the page has no rules option', [$rdRevA['ok'] ?? $rdRevA, array_key_exists($rdKey, WP_Fake::$options)], [true, false]);
check('revert: the visitor reads the theme\'s page again', $rdSite->visit('/contact-us/')['body'] === $rdPage, true);
check('revert: vi kept its rules', RenderedWords::decodeSet(WP_Fake::$options[RenderedWords::optionKey(41, 'vi')] ?? '')['revision'] ?? null, 1);
$rdReceipts = json_decode((string) WP_Fake::$options[RenderedWords::RECEIPTS_OPTION], true);
check('revert: the receipts say reverted', [$rdReceipts['req-1']['state'], $rdReceipts['req-2']['state'], $rdReceipts['req-vi']['state']], ['reverted', 'reverted', 'applied']);
$rdLate = $rdApply($rdIn2, 'rw-1', 'req-1', $rdGood, 0);
check('a late retry of a reverted request writes nothing again', [$rdLate['replayed'] ?? null, $rdLate['state'] ?? null, array_key_exists($rdKey, WP_Fake::$options)], [true, 'reverted', false]);

// Status.
$rdStatus = $rdDoor('status', ['request_id' => 'req-vi']);
check('status by request_id: its scope, rules, receipt and drift', [$rdStatus['ok'] ?? $rdStatus, $rdStatus['revision'] ?? null, count($rdStatus['rules'] ?? []), $rdStatus['theme_matches'] ?? null, $rdStatus['request']['state'] ?? null],
    [true, 1, 2, true, 'applied']);
$rdSite->drift = ['at' => 1, 'revision' => 1, 'problems' => ['rule x: expected 4 match(es), found 3']];
check('status by post and locale reports what the front end could not apply', $rdDoor('status', ['post' => 41, 'locale' => 'vi'])['drift']['problems'] ?? null, ['rule x: expected 4 match(es), found 3']);
$rdSite->drift = null;
check('status: an unknown request_id is not_found', $rdDoor('status', ['request_id' => 'nope'])['error'] ?? null, 'not_found');

// A page cache that keeps serving the old page; a visit that does not answer.
$rdIn3 = $rdDoor('inspect', ['path' => '/contact-us/', 'targets' => $rdTargets]);
$rdSite->cached = true;
$rdC = $rdApply($rdIn3, 'rw-3', 'req-3', $rdGood);
check('a page still cached after the purge is cache_verification_failed, the rules stored', [$rdC['ok'] ?? $rdC, $rdC['render'] ?? null, $rdC['revision'] ?? null], [true, 'cache_verification_failed', 1]);
$rdSite->cached = false;
$rdSite->visitCode = 0;
$rdIn4 = $rdDoor('inspect', ['path' => '/contact-us/', 'targets' => $rdTargets]);
$rdD = $rdApply($rdIn4, 'rw-4', 'req-4', [['new' => '+84 1'] + $rdGood[0]]);
check('a visit that does not answer is not_verified', [$rdD['ok'] ?? $rdD, $rdD['render'] ?? null], [true, 'not_verified']);
$rdSite->visitCode = 200;
$rdDrop = $rdApply($rdDoor('inspect', ['path' => '/contact-us/', 'targets' => $rdTargets]), 'rw-drop', 'req-drop', [['new' => $rdSiteUrl] + $rdGood[1]]);
check('new words equal to the old drop the rule', [$rdDrop['ok'] ?? $rdDrop, array_column(RenderedWords::decodeSet(WP_Fake::$options[$rdKey])['rules'], 'kind')], [true, ['text']]);

// The receipt cannot be written: the rules are taken back with it.
$rdBeforeE = WP_Fake::$options[$rdKey];
$rdIn5 = $rdDoor('inspect', ['path' => '/contact-us/', 'targets' => $rdTargets]);
$rdWriter->fail = true;
$rdE = $rdApply($rdIn5, 'rw-5', 'req-5', [['new' => '+84 5'] + $rdGood[0]]);
$rdWriter->fail = false;
check('no receipt: write_failed, the page\'s rules as they were, no log step',
    [$rdE['error'] ?? null, WP_Fake::$options[$rdKey] === $rdBeforeE, $rdLog->entries('rw-5')], ['write_failed', true, []]);
$rdF = $rdApply($rdDoor('inspect', ['path' => '/contact-us/', 'targets' => $rdTargets]), 'rw-5', 'req-5', [['new' => '+84 5'] + $rdGood[0]]);
check('no receipt: a retry of the same request then applies once', [$rdF['ok'] ?? $rdF, $rdF['replayed'] ?? false, $rdF['revision'] ?? null], [true, false, 4]);

// An edit that keeps the revision (a rule's words changed in the database by hand) is still a later edit.
$rdIn6 = $rdDoor('inspect', ['path' => '/contact-us/', 'targets' => $rdTargets]);
$rdKept = WP_Fake::$options[$rdKey];
$rdHand = RenderedWords::decodeSet($rdKept);
$rdHand['rules'][0]['new'] = '+84 hand edit';
$rdHandValue = RenderedWords::encodeSet($rdHand);
WP_Fake::$options[$rdKey] = $rdHandValue;
check('apply: rules changed since the inspection at the same revision are a conflict, nothing written',
    [$rdApply($rdIn6, 'rw-6', 'req-6', [['new' => '+84 6'] + $rdGood[0]])['error'] ?? null, WP_Fake::$options[$rdKey] === $rdHandValue, $rdLog->entries('rw-6')], ['conflict', true, []]);
WP_Fake::$options[$rdKey] = $rdKept;
$rdG = $rdApply($rdDoor('inspect', ['path' => '/contact-us/', 'targets' => $rdTargets]), 'rw-6', 'req-6b', [['new' => '+84 6'] + $rdGood[0]]);
$rdWritten = WP_Fake::$options[$rdKey];
$rdHand = RenderedWords::decodeSet($rdWritten);
$rdHand['rules'][0]['new'] = '+84 hand edit';
$rdHandValue = RenderedWords::encodeSet($rdHand);
WP_Fake::$options[$rdKey] = $rdHandValue;
$rdRevHand = $rdCall('apply.revert', ['apply_id' => 'rw-6']);
check('revert: an edit that kept the revision is a conflict, and the edit stays', [$rdG['ok'] ?? $rdG, $rdRevHand['error'] ?? 'reverted', WP_Fake::$options[$rdKey] === $rdHandValue],
    [true, 'conflict', true]);
WP_Fake::$options[$rdKey] = $rdWritten;
check('revert: with the option back as the apply wrote it, the revert goes through', [$rdCall('apply.revert', ['apply_id' => 'rw-6'])['ok'] ?? null, WP_Fake::$options[$rdKey] === $rdKept], [true, true]);
check('revert: a clean revert answers reverted and nothing else', [array_keys($rdRevB), $rdRevB['reverted'] ?? null], [['ok', 'reverted'], 1]);

// The rules are back but the receipt cannot say so: the revert answer is the one place that says status may be stale.
$rdBeforeR = WP_Fake::$options[$rdKey];
$rdR = $rdApply($rdDoor('inspect', ['path' => '/contact-us/', 'targets' => $rdTargets]), 'rw-7', 'req-7', [['new' => '+84 7'] + $rdGood[0]]);
$rdWriter->fail = true;
$rdRevR = $rdCall('apply.revert', ['apply_id' => 'rw-7']);
$rdWriter->fail = false;
check('revert, no receipt: the rules are back, and the answer warns that status may be stale',
    [$rdR['ok'] ?? $rdR, $rdRevR['ok'] ?? $rdRevR, $rdRevR['reverted'] ?? null, WP_Fake::$options[$rdKey] === $rdBeforeR,
        is_string($rdRevR['warning'] ?? null) && strpos($rdRevR['warning'], 'req-7') !== false && strpos($rdRevR['warning'], 'database refused the write') !== false],
    [true, true, 1, true, true]);
check('revert, no receipt: the stored receipt still says applied', json_decode((string) WP_Fake::$options[RenderedWords::RECEIPTS_OPTION], true)['req-7']['state'] ?? null, 'applied');
$rdLateR = $rdApply($rdDoor('inspect', ['path' => '/contact-us/', 'targets' => $rdTargets]), 'rw-7', 'req-7', [['new' => '+84 7'] + $rdGood[0]]);
check('revert, no receipt: a retry is answered from the receipt and writes nothing', [$rdLateR['replayed'] ?? $rdLateR, WP_Fake::$options[$rdKey] === $rdBeforeR], [true, true]);

// An applied request whose log row is gone (trimmed, cleaned, lost) is still applied: an empty log proves no revert.
$rdInLost = $rdDoor('inspect', ['path' => '/contact-us/', 'targets' => $rdTargets]);
$rdLost = $rdApply($rdInLost, 'rw-lost', 'req-lost', [['new' => '+84 lost'] + $rdGood[0]]);
$rdLostRules = WP_Fake::$options[$rdKey];
$rdLog->clear('rw-lost');
$rdLostStatus = $rdDoor('status', ['request_id' => 'req-lost']);
$rdLostReplay = $rdApply($rdDoor('inspect', ['path' => '/contact-us/', 'targets' => $rdTargets]), 'rw-lost', 'req-lost', [['new' => '+84 lost'] + $rdGood[0]], $rdInLost['revision']);
check('a lost log row: status and a retry still say applied, the rules untouched',
    [$rdLost['ok'] ?? $rdLost, $rdLostStatus['request']['state'] ?? $rdLostStatus, array_column($rdLostStatus['receipts'] ?? [], 'state', 'request_id')['req-lost'] ?? null,
        $rdLostReplay['replayed'] ?? $rdLostReplay, $rdLostReplay['state'] ?? null, $rdLostReplay['revision'] ?? null, WP_Fake::$options[$rdKey] === $rdLostRules],
    [true, 'applied', 'applied', true, 'applied', $rdLost['revision'] ?? -1, true]);

// Receipts are never dropped: a request_id answered long ago still replays, and a full store refuses new requests.
$rdAll = json_decode((string) WP_Fake::$options[RenderedWords::RECEIPTS_OPTION], true);
$rdFiller = $rdAll['req-5'];
check('receipts: a site keeps 1000', RenderedWords::MAX_RECEIPTS, 1000);
for ($i = count($rdAll); $i < 999; $i++) {
    $rdAll['filler-' . $i] = ['request_id' => 'filler-' . $i] + $rdFiller;
}
WP_Fake::$options[RenderedWords::RECEIPTS_OPTION] = json_encode($rdAll, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$rdIn8 = $rdDoor('inspect', ['path' => '/contact-us/', 'targets' => $rdTargets]);
$rdLast = $rdApply($rdIn8, 'rw-8', 'req-8', [['new' => '+84 8'] + $rdGood[0]]);
$rdOld = $rdApply($rdIn2, 'rw-1', 'req-1', $rdGood, 0);
check('receipts: the last free place takes a request, and the oldest reverted one still replays (written nothing)',
    [$rdLast['ok'] ?? $rdLast, $rdOld['replayed'] ?? null, $rdOld['state'] ?? null, count(json_decode((string) WP_Fake::$options[RenderedWords::RECEIPTS_OPTION], true))],
    [true, true, 'reverted', 1000]);
$rdBeforeFull = WP_Fake::$options[$rdKey];
$rdFull = $rdApply($rdDoor('inspect', ['path' => '/contact-us/', 'targets' => $rdTargets]), 'rw-9', 'req-9', [['new' => '+84 9'] + $rdGood[0]]);
check('receipts: a full store refuses a new request, nothing written', [$rdFull['error'] ?? null, $rdFull['code'] ?? null, WP_Fake::$options[$rdKey] === $rdBeforeFull, $rdLog->entries('rw-9')],
    ['too_large', 'RECEIPTS_FULL', true, []]);
check('receipts: a full store still answers a retry of a known request', $rdApply($rdIn8, 'rw-8', 'req-8', [['new' => '+84 8'] + $rdGood[0]])['replayed'] ?? null, true);
check('receipts: a receipt keeps no rules, so a full store stays under 1 MiB', [isset($rdAll['req-5']['answer']['rules']), strlen((string) WP_Fake::$options[RenderedWords::RECEIPTS_OPTION]) < 1048576],
    [false, true]);

// Edit what the visitor sees, twice, then undo each edit. Raw theme bytes remain the one source.
WP_Fake::reset();
WP_Fake::$options = [];
$rcPage = '<html><body><main><p>Original bakery note.</p><aside><p>First revision.</p></aside></main></body></html>';
$rcSite = new FakeRenderedSite($rcPage);
$rcLog = new FakeApplyLog();
$rcEngine = (new Engine($WTOKEN, [], null, null, null, null, new Claude_Cowork_Site_Writer(), null, $rcLog))->renderedWordsSite($rcSite);
$rcCall = static fn(string $action, array $params): array => $rcEngine->handle(['token' => $WTOKEN, 'action' => $action, 'params' => $params]);
$rcInspect = static fn(string $old, array $extra = []): array => $rcCall('content.renderedWords', ['operation' => 'inspect', 'path' => '/contact-us/',
    'targets' => [['kind' => 'text', 'old' => $old]]] + $extra);
$rcApply = static fn(array $seen, string $apply, array $rules): array => $rcCall('content.renderedWords', ['operation' => 'apply', 'apply_id' => $apply,
    'request_id' => $apply, 'proof' => $seen['proof'], 'expected_revision' => $seen['revision'], 'rules' => $rules]);
$rcFirst = $rcInspect('Original bakery note.');
$rcRawRule = ['kind' => 'text', 'anchor' => $rcFirst['targets'][0]['candidates'][0]['anchor'], 'old' => 'Original bakery note.',
    'new' => 'First revision.', 'expected_matches' => 1];
check('composition: first ordinary edit succeeds', $rcApply($rcFirst, 'compose-first', [$rcRawRule])['render'] ?? null, 'verified');
$rcKey = RenderedWords::optionKey(41, 'en_US');
$rcBefore = WP_Fake::$options[$rcKey];
$rcSecond = $rcInspect('First revision.', ['composition' => 'whole-output']);
$rcCandidates = $rcSecond['targets'][0]['candidates'] ?? [];
check('composition: visible output and equal raw text at a different anchor retain distinct provenance',
    [count($rcCandidates), $rcCandidates[0]['replaces_rule'] ?? null, isset($rcCandidates[1]['replaces_rule'])],
    [2, RenderedWords::ruleId($rcRawRule), false]);
$rcIncoming = ['old' => 'First revision.', 'new' => 'For bakery enquiries, contact us.', 'replaces_rule' => RenderedWords::ruleId($rcRawRule)] + $rcRawRule;
check('composition: an ordinary raw proof cannot authorize editing a generated output',
    $rcApply($rcInspect('Original bakery note.'), 'compose-no-proof', [$rcIncoming])['error'] ?? null, 'stale_proof');
check('composition: an unrelated rule id is refused without a write',
    [$rcApply($rcSecond, 'compose-wrong-id', [['replaces_rule' => str_repeat('a', 16)] + $rcIncoming])['error'] ?? null, WP_Fake::$options[$rcKey] === $rcBefore], ['drift', true]);
$rcSite->page = str_replace('Original bakery note.', 'Changed outside Tracy.', $rcPage);
check('composition: external raw drift is refused without a write',
    [$rcApply($rcSecond, 'compose-drift', [$rcIncoming])['error'] ?? null, WP_Fake::$options[$rcKey] === $rcBefore], ['drift', true]);
$rcSite->page = $rcPage;
$rcSite->locale = 'vi';
check('composition: a different locale cannot consume the inspected edit', $rcApply($rcSecond, 'compose-locale', [$rcIncoming])['error'] ?? null, 'drift');
$rcSite->locale = 'en_US';
$rcSite->post = 99;
check('composition: a different page cannot consume the inspected edit', $rcApply($rcSecond, 'compose-page', [$rcIncoming])['error'] ?? null, 'drift');
$rcSite->post = 41;
check('composition: substrings of generated output are explicitly unsupported', $rcInspect('revision', ['composition' => 'whole-output'])['error'] ?? null, 'unsupported');
$rcDone = $rcApply($rcSecond, 'compose-second', [$rcIncoming]);
$rcStored = RenderedWords::decodeSet(WP_Fake::$options[$rcKey]);
check('composition: second edit stores a -> c, never a -> b -> c',
    [$rcDone['render'] ?? $rcDone, count($rcStored['rules']), $rcStored['rules'][0]['old'], $rcStored['rules'][0]['new']],
    ['verified', 1, 'Original bakery note.', 'For bakery enquiries, contact us.']);
check('composition: visitor gets the new output and the unrelated raw equal phrase stays',
    [strpos($rcSite->visit('/contact-us/')['body'], '<p>For bakery enquiries, contact us.</p>') !== false,
        strpos($rcSite->visit('/contact-us/')['body'], '<aside><p>First revision.</p></aside>') !== false], [true, true]);
check('composition: receipt records the visible before/after', $rcDone['visible_changes'] ?? null,
    [['anchor' => $rcRawRule['anchor'], 'before' => 'First revision.', 'after' => 'For bakery enquiries, contact us.']]);
check('composition: identical retry replays without another revision',
    [$rcApply($rcSecond, 'compose-second', [$rcIncoming])['replayed'] ?? null, RenderedWords::decodeSet(WP_Fake::$options[$rcKey])['revision']], [true, 2]);
check('composition: older inspection conflicts after a newer edit', $rcApply($rcSecond, 'compose-stale', [$rcIncoming])['error'] ?? null, 'conflict');
check('composition: older undo cannot overwrite the new edit', $rcCall('apply.revert', ['apply_id' => 'compose-first'])['error'] ?? null, 'conflict');
check('composition: undo second restores the first rule byte-for-byte',
    [$rcCall('apply.revert', ['apply_id' => 'compose-second'])['ok'] ?? null, WP_Fake::$options[$rcKey] === $rcBefore], [true, true]);
check('composition: undo first restores the original visitor HTML',
    [$rcCall('apply.revert', ['apply_id' => 'compose-first'])['ok'] ?? null, $rcSite->visit('/contact-us/')['body'] === $rcPage], [true, true]);
$rcReceiptStore = json_decode(WP_Fake::$options[RenderedWords::RECEIPTS_OPTION], true);
check('composition: durable receipts retain no before/after payload multiplied by request history',
    isset($rcReceiptStore['compose-second']['answer']['visible_changes']), false);
