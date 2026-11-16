<?php
// A child isolates the labelled parser adapter from the real ContentSlots class already loaded by run.php.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && ($argv[1] ?? '') === '--svg-probe') {
    final class ContentSlots {
        public static int $parses = 0;
        public static function html(string $html): DOMDocument {
            self::$parses++;
            $doc = new DOMDocument('1.0', 'UTF-8'); $old = libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="contract-root">' . $html . '</div></body></html>', LIBXML_NONET);
            libxml_clear_errors(); libxml_use_internal_errors($old); return $doc;
        }
    }
    require_once __DIR__ . '/../lib/SvgScalar.php';
    $svgProbeHtml = '<section><p>Keep context</p>';
    for ($svgProbeN = 1; $svgProbeN <= 128; $svgProbeN++) {
        $svgProbeLabel = in_array($svgProbeN, [1, 64, 128], true) ? ' aria-label="Same label"' : '';
        $svgProbeHtml .= '<svg viewBox="0 0 ' . $svgProbeN . ' 4"' . $svgProbeLabel . '><path d="M1 1"/></svg>';
    }
    $svgProbeHtml .= '</section>';
    $svgProbePaths = SvgScalar::paths($svgProbeHtml);
    echo json_encode(['parses' => ContentSlots::$parses, 'paths' => $svgProbePaths]);
    exit(0);
}

// Standalone/include-safe normal regression; every specimen is synthetic, no CMS or database.
$svgScalarLib = __DIR__ . '/../lib';
require_once $svgScalarLib . '/ContentSlots.php';
$svgScalarChecks = [];
$svgScalarCheck = static function (string $name, callable $test) use (&$svgScalarChecks): void {
    try { $ok = $test() === true; $error = null; }
    catch (Throwable $e) { $ok = false; $error = get_class($e) . ': ' . $e->getMessage(); }
    $svgScalarChecks[] = ['name' => $name, 'pass' => $ok, 'error' => $error];
};
$svgScalarRefuses = static function (callable $fn): bool {
    try { $fn(); } catch (RuntimeException $e) { return true; }
    return false;
};
$svgScalarRead = static function (string $html, string $path): string {
    $nodes = (new DOMXPath(ContentSlots::html($html)))->query($path);
    if (!$nodes || $nodes->length !== 1) throw new RuntimeException('Missing test receiver');
    return ContentSlots::slotText($nodes->item(0));
};
$svgScalarWrite = static fn(string $html, string $path, string $value): string =>
    ContentSlots::htmlPatch($html, [['xpath' => $path, 'value' => $value]]);
$svgScalarA = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 120 90" preserveAspectRatio="xMidYMid slice" role="img" aria-label="Synthetic Image"><defs><path id="PathA" d="M1 2L3 4"/></defs><use xlink:href="#PathA"/><rect width="100%" height="100%" fill="#eee"/></svg>';
$svgScalarB = "<svg viewBox='0 0 80 40' aria-label='Synthetic Image cap'><path d='M0 0h80v40H0z'/></svg>";
$svgScalarHtml = '<section><p>Ordinary <em>prose</em>.</p>' . $svgScalarA . $svgScalarB . '</section>';
$svgScalarPathA = '/html/body/div/section/svg[1]/@aria-label';
$svgScalarPathB = '/html/body/div/section/svg[2]/@aria-label';
$svgScalarCheck('reader offers only both exact SVG root aria scalars', static function () use ($svgScalarHtml, $svgScalarPathA, $svgScalarPathB): bool {
    $aria = array_values(array_filter(ContentSlots::htmlSlots($svgScalarHtml), static fn(array $s): bool => str_ends_with($s['xpath'], '/@aria-label')));
    return array_column($aria, 'xpath') === [$svgScalarPathA, $svgScalarPathB]
        && array_column($aria, 'sample') === ['Synthetic Image', 'Synthetic Image cap'];
});
$svgScalarCheck('scalar write changes only selected attribute bytes; geometry/namespaces/path exact', static fn(): bool =>
    $svgScalarWrite($svgScalarHtml, $svgScalarPathA, 'New diagram') === str_replace('aria-label="Synthetic Image"', 'aria-label="New diagram"', $svgScalarHtml));
$svgScalarCheck('second receiver independently bound; first identical-looking label untouched', static fn(): bool =>
    $svgScalarWrite($svgScalarHtml, $svgScalarPathB, 'New cap') === str_replace("aria-label='Synthetic Image cap'", "aria-label='New cap'", $svgScalarHtml));
$svgScalarCheck('same-value write is exact original bytes', static fn(): bool =>
    $svgScalarWrite($svgScalarHtml, $svgScalarPathA, 'Synthetic Image') === $svgScalarHtml);
$svgScalarCheck('empty attribute then refill preserves SVG and locator', static function () use ($svgScalarWrite, $svgScalarHtml, $svgScalarPathA): bool {
    $empty = $svgScalarWrite($svgScalarHtml, $svgScalarPathA, '');
    return $empty === str_replace('aria-label="Synthetic Image"', 'aria-label=""', $svgScalarHtml)
        && $svgScalarWrite($empty, $svgScalarPathA, 'Synthetic Image') === $svgScalarHtml;
});
$svgScalarCheck('markup-looking scalar with quotes and ampersands reads back literally', static function () use ($svgScalarWrite, $svgScalarRead, $svgScalarHtml, $svgScalarPathA): bool {
    $value = 'A & "B" <fake> &#65; café';
    $next = $svgScalarWrite($svgScalarHtml, $svgScalarPathA, $value);
    return $svgScalarRead($next, $svgScalarPathA) === $value
        && !str_contains($next, '<fake>') && str_contains($next, 'viewBox="0 0 120 90"');
});
$svgScalarCheck('single-quoted attribute keeps quote style and raw spacing', static fn(): bool =>
    $svgScalarWrite("<svg  viewBox='0 0 2 2' aria-label = 'old'><path d='M1 1'/></svg>", '/html/body/div/svg/@aria-label', "A's")
        === "<svg  viewBox='0 0 2 2' aria-label = 'A&#039;s'><path d='M1 1'/></svg>");
$svgScalarCheck('ordinary text write preserves every untouched SVG byte', static function () use ($svgScalarWrite, $svgScalarHtml, $svgScalarA, $svgScalarB): bool {
    $next = $svgScalarWrite($svgScalarHtml, '/html/body/div/section/p', 'New prose');
    return str_contains($next, $svgScalarA . $svgScalarB) && str_contains($next, '<p>New prose</p>');
});
$svgScalarCheck('mixed ordinary+both SVG changes preserve all geometry for contract masking', static function () use ($svgScalarHtml, $svgScalarPathA, $svgScalarPathB, $svgScalarA, $svgScalarB): bool {
    $next = ContentSlots::htmlPatch($svgScalarHtml, [
        ['xpath' => '/html/body/div/section/p', 'value' => 'Masked'],
        ['xpath' => $svgScalarPathA, 'value' => 'Mask A'], ['xpath' => $svgScalarPathB, 'value' => 'Mask B']]);
    return str_contains($next, str_replace('Synthetic Image', 'Mask A', $svgScalarA))
        && str_contains($next, str_replace('Synthetic Image cap', 'Mask B', $svgScalarB));
});
$svgScalarCheck('duplicate labels are located by address, never value equality', static function () use ($svgScalarA, $svgScalarWrite): bool {
    $html = '<div>' . $svgScalarA . $svgScalarA . '</div>';
    return $svgScalarWrite($html, '/html/body/div/div/svg[2]/@aria-label', 'Second')
        === '<div>' . $svgScalarA . str_replace('Synthetic Image', 'Second', $svgScalarA) . '</div>';
});
$svgScalarCheck('missing and ambiguous attribute addresses refuse', static fn(): bool =>
    $svgScalarRefuses(static fn() => $svgScalarWrite('<svg viewBox="0 0 1 1"></svg>', '/html/body/div/svg/@aria-label', 'x'))
    && $svgScalarRefuses(static fn() => $svgScalarWrite($svgScalarHtml, '/html/body/div/section/svg/@aria-label', 'x')));
$svgScalarCheck('script/style/code SVG and nested geometry attrs not offered', static function () use ($svgScalarA): bool {
    $html = '<script>let fake = `' . $svgScalarA . '`;</script><style>/* ' . $svgScalarA . ' */</style><code>' . $svgScalarA . '</code>'
        . '<svg aria-label="Root"><g aria-label="Geometry"><svg aria-label="Nested"></svg></g></svg>';
    $aria = array_values(array_filter(ContentSlots::htmlSlots($html), static fn(array $s): bool => str_ends_with($s['xpath'], '/@aria-label')));
    return count($aria) === 1 && $aria[0]['sample'] === 'Root';
});
$svgScalarCheck('arbitrary SVG geometry/markup writes refuse', static fn(): bool =>
    $svgScalarRefuses(static fn() => $svgScalarWrite($svgScalarHtml, '/html/body/div/section/svg[1]/@viewbox', '0 0 1 1'))
    && $svgScalarRefuses(static fn() => $svgScalarWrite($svgScalarHtml, '/html/body/div/section/svg[1]/defs/path/@d', 'M9 9'))
    && $svgScalarRefuses(static fn() => $svgScalarWrite($svgScalarHtml, '/html/body/div/section/svg[1]', '<path/>')));
$svgScalarCheck('code ancestor SVG aria write refuses', static fn(): bool =>
    $svgScalarRefuses(static fn() => $svgScalarWrite('<code>' . $svgScalarA . '</code>', '/html/body/div/code/svg/@aria-label', 'x')));
$svgScalarCheck('ordinary alt title src href behavior retained', static function () use ($svgScalarWrite): bool {
    $html = '<a href="/old" title="Old"><img src="/old.png" alt="Old"></a>';
    $slots = ContentSlots::htmlSlots($html);
    $next = $svgScalarWrite($html, '/html/body/div/a/@href', '/new?a=1&b=2');
    return count($slots) === 4 && str_contains($next, 'href="/new?a=1&amp;b=2"') && str_contains($next, 'alt="Old"');
});
$svgScalarCheck('non-SVG aria-label is not added to capture', static fn(): bool =>
    ContentSlots::htmlSlots('<div aria-label="Not allocated"></div>') === []);
$svgScalarCheck('unclosed SVG refuses scalar write rather than serialization fallback', static fn(): bool =>
    $svgScalarRefuses(static fn() => $svgScalarWrite('<svg aria-label="Old"><path d="M1 1">', '/html/body/div/svg/@aria-label', 'New')));

$svgCompatGood = '<svg viewBox="0 0 2 2" aria-label="Eligible"><path d="M1 1"/></svg>';
foreach ([
    'HTML named entity' => '<svg viewBox="0 0 4 4" aria-label="Legacy"><text>&nbsp;</text></svg>',
    'undeclared prefix' => '<svg viewBox="0 0 4 4" aria-label="Legacy"><use xlink:href="#legacy"/></svg>',
] as $svgCompatKind => $svgCompatLegacy) {
    $svgCompatHtml = '<section><p>Old prose</p>' . $svgCompatGood . $svgCompatLegacy . '</section>';
    $svgScalarCheck($svgCompatKind . ': ordinary edit retains complete legacy SVG bytes', static function () use ($svgCompatHtml): bool {
        $next = ContentSlots::htmlPatch($svgCompatHtml, [['xpath' => '/html/body/div/section/p', 'value' => 'New prose']]);
        return $next === str_replace('Old prose', 'New prose', $svgCompatHtml);
    });
    $svgScalarCheck($svgCompatKind . ': mixed edit retains legacy SVG and changes proven root only', static function () use ($svgCompatHtml): bool {
        $next = ContentSlots::htmlPatch($svgCompatHtml, [['xpath' => '/html/body/div/section/p', 'value' => 'New prose'],
            ['xpath' => '/html/body/div/section/svg[1]/@aria-label', 'value' => 'New label']]);
        return $next === str_replace(['Old prose', 'aria-label="Eligible"'], ['New prose', 'aria-label="New label"'], $svgCompatHtml);
    });
    $svgScalarCheck($svgCompatKind . ': reader offers ordinary and proven aria but not ineligible scalar', static function () use ($svgCompatHtml): bool {
        $slots = ContentSlots::htmlSlots($svgCompatHtml);
        return count($slots) === 2 && array_column($slots, 'sample') === ['Old prose', 'Eligible'];
    });
    $svgScalarCheck($svgCompatKind . ': targeted new aria refuses without corruption', static fn(): bool =>
        $svgScalarRefuses(static fn() => ContentSlots::htmlPatch($svgCompatHtml,
            [['xpath' => '/html/body/div/section/svg[2]/@aria-label', 'value' => 'Refused']])));
}
$svgCompatMalformed = '<p>Old prose</p><svg aria-label="Unclosed"><path d="M1 1">';
$svgScalarCheck('genuinely unbalanced reader explicitly refuses entire field', static fn(): bool =>
    $svgScalarRefuses(static fn() => ContentSlots::htmlSlots($svgCompatMalformed)));
$svgScalarCheck('genuinely unbalanced ordinary writer refuses same field', static fn(): bool =>
    $svgScalarRefuses(static fn() => ContentSlots::htmlPatch($svgCompatMalformed,
        [['xpath' => '/html/body/div/p', 'value' => 'New prose']])));
$svgScalarCheck('stray closing SVG reader and writer agree on refusal', static fn(): bool =>
    $svgScalarRefuses(static fn() => ContentSlots::htmlSlots('<p>Old</p></svg>'))
    && $svgScalarRefuses(static fn() => ContentSlots::htmlPatch('<p>Old</p></svg>', [['xpath' => '/html/body/div/p', 'value' => 'New']])));
// Reuse the normal engine-factory subprocess pattern, with a five-second bound for128icons only.
$svgScalarProbe = null; $svgScalarProbeError = null;
try {
    $svgScalarProcess = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __FILE__, '--svg-probe'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $svgScalarPipes);
    if (!is_resource($svgScalarProcess)) throw new RuntimeException('Could not start SVG probe child');
    stream_set_blocking($svgScalarPipes[1], false); stream_set_blocking($svgScalarPipes[2], false);
    $svgScalarOut = ''; $svgScalarErr = ''; $svgScalarDeadline = microtime(true) + 5;
    while (true) {
        $svgScalarOut .= (string) stream_get_contents($svgScalarPipes[1]);
        $svgScalarErr .= (string) stream_get_contents($svgScalarPipes[2]);
        $svgScalarStatus = proc_get_status($svgScalarProcess);
        if (!$svgScalarStatus['running']) break;
        if (microtime(true) > $svgScalarDeadline) {
            proc_terminate($svgScalarProcess, 9); $svgScalarErr .= ' [timed out]'; break;
        }
        usleep(10000);
    }
    $svgScalarOut .= (string) stream_get_contents($svgScalarPipes[1]);
    $svgScalarErr .= (string) stream_get_contents($svgScalarPipes[2]);
    fclose($svgScalarPipes[1]); fclose($svgScalarPipes[2]); proc_close($svgScalarProcess);
    $svgScalarProbe = json_decode($svgScalarOut, true);
    if ($svgScalarStatus['running'] || $svgScalarStatus['exitcode'] !== 0 || $svgScalarErr !== '' || !is_array($svgScalarProbe))
        throw new RuntimeException('SVG probe child failed: ' . $svgScalarErr);
} catch (Throwable $svgScalarProbeException) { $svgScalarProbeError = $svgScalarProbeException->getMessage(); }
$svgScalarCheck('128 icons use one full-field probe parse in isolated labelled adapter', static function () use ($svgScalarProbe, $svgScalarProbeError): bool {
    if ($svgScalarProbeError !== null) throw new RuntimeException($svgScalarProbeError);
    return $svgScalarProbe['parses'] === 1;
});
$svgScalarCheck('128 icons retain exactly three eligible receiver addresses', static function () use ($svgScalarProbe, $svgScalarProbeError): bool {
    if ($svgScalarProbeError !== null) throw new RuntimeException($svgScalarProbeError);
    return $svgScalarProbe['paths'] === ['/html/body/div/section/svg[1]', '/html/body/div/section/svg[64]', '/html/body/div/section/svg[128]'];
});

if (function_exists('check'))
    foreach ($svgScalarChecks as $svgScalarResult) check('SVG scalar: ' . $svgScalarResult['name'], $svgScalarResult['pass'], true);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    echo json_encode(['checks' => $svgScalarChecks, 'pass' => count(array_filter($svgScalarChecks, static fn(array $c): bool => $c['pass'])),
        'fail' => count(array_filter($svgScalarChecks, static fn(array $c): bool => !$c['pass']))], JSON_PRETTY_PRINT) . "\n";
    exit(count(array_filter($svgScalarChecks, static fn(array $c): bool => !$c['pass'])) ? 1 : 0);
}
