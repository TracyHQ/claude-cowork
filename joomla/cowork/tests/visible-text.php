<?php
// Loaded by run.php. VisibleText turns rendered pages into what a visitor reads, and keeps the leaves found there.
require_once __DIR__ . '/../lib/VisibleText.php';
echo "\nVisible text\n";
$vtPages = ['<html><head><title>T</title><style>.a{}</style></head><body><h1>Fast   delivery</h1><a href="https://x.test/go">Go</a><img src="/up/a.jpg"></body></html>'];
$vtSeen = VisibleText::fromPages($vtPages);
check('visible: text is normalised', VisibleText::shows($vtSeen, ['type' => 'text', 'text' => "Fast delivery"]), true);
check('visible: style text is not visible', VisibleText::shows($vtSeen, ['type' => 'text', 'text' => '.a{}']), false);
check('visible: a url shown as href', VisibleText::shows($vtSeen, ['type' => 'url', 'text' => 'https://x.test/go']), true);
check('visible: an image by path', VisibleText::shows($vtSeen, ['type' => 'image', 'text' => 'https://copy.test/up/a.jpg']), true);
check('visible: an unseen leaf', VisibleText::shows($vtSeen, ['type' => 'text', 'text' => 'Draft only']), false);

// VisibleText::unmatched — how many visible text blocks on the pages (split by block elements: p, h1-h6,
// li, td, div text, a, button) have normalised text that is not contained in any leaf text. Blocks shorter
// than 3 characters are ignored (too short to be meaningful, e.g. a lone icon glyph).
$vtUnmatchedPages = ['<html><body><h1>Fast delivery</h1><p>Welcome aboard</p><p>Draft only</p><li>ok</li><a href="/x">Shop now</a></body></html>'];
$vtLeafTexts = ['Fast delivery', 'Welcome aboard'];
check('visible: unmatched counts visible blocks with no matching leaf, ignoring short ones',
    VisibleText::unmatched($vtUnmatchedPages, $vtLeafTexts), 2);

// A block that wraps other block tags (a div around two p's, a p around an a) is not itself a leaf block:
// only the innermost run of text between one block-tag boundary and the next is counted, once each, so the
// outer element's concatenated text is never checked and never double counts its children.
check('visible: unmatched only counts leaf blocks, not their wrapping div',
    VisibleText::unmatched(['<div><p>Welcome aboard</p><p>Ships in 24h</p></div>'], ['Welcome aboard', 'Ships in 24h']), 0);
check('visible: unmatched only counts leaf blocks, not their wrapping p',
    VisibleText::unmatched(['<p>Since <a href="/x">our history</a></p>'], ['Since', 'our history']), 0);
check('visible: unmatched still catches an inner block missing from the leaves',
    VisibleText::unmatched(['<p>Since <a href="/x">our history</a></p>'], ['Since']), 1);
