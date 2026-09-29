<?php
/**
 * Writing a global styles post lifts core's `wp_filter_global_styles_post` at the priority core gives
 * it — 9 (`kses_init_filters`) — and puts it back at 9. `remove_filter` removes a callback only at the
 * priority named, so a call without it (default 10) removes nothing: KSES then strips the JSON down
 * to `layout`, the call answers ok, and the site keeps its old palette. Measured 29/09/2026 on a
 * local stand: theme.style with a brand variation answered ok while the global styles post held 115
 * bytes of layout and the page still served the old accent; with priority 9 the post held the whole
 * palette and the page the brand's accent. The theme's own tracy_apply_inspiration() already uses 9.
 *
 * Loaded by run.php. Uses `check()`.
 */
declare(strict_types=1);

echo "\nGlobal styles writes lift the KSES filter at its own priority\n";

$gsSource = (string) file_get_contents(__DIR__ . '/../lib/class-claude-cowork-packages.php');
preg_match_all("/(remove_filter|add_filter)\\(\\s*'content_save_pre',\\s*'wp_filter_global_styles_post'([^)]*)\\)/", $gsSource, $gsCalls, PREG_SET_ORDER);
check('every global-styles write lifts and restores the filter (two writes, four calls)', count($gsCalls), 4);
foreach ($gsCalls as $i => $call) {
    check("{$call[1]} #" . ($i + 1) . ' names priority 9', trim($call[2]), ', 9');
}
