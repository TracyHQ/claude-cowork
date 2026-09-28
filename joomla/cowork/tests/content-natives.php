<?php
// Loaded by run.php. content.read names each content's own Joomla rows (`native`), so an agent can
// call content.get / content.update / content.delete on them without an inspect first.
require_once __DIR__ . '/../lib/ContentProjection.php';

$ntContents = [
    'c-page' => ['id' => 'c-page', 'type' => 'page', 'revision' => 'r1'],
    'c-post' => ['id' => 'c-post', 'type' => 'article', 'revision' => 'r2'],
    'c-none' => ['id' => 'c-none', 'type' => 'shared', 'revision' => 'r3'],
];
$ntRows = [
    // A page that inlined its module: both rows are the page's own.
    'c-page' => [['menuItem', 629], ['module', 441], ['menuItem', 629]],
    'c-post' => [['article', 546]],
];
$ntOut = ContentProjection::natives($ntContents, $ntRows);
check('natives: a page names every row it is made of, in order, once each', $ntOut['c-page']['native'],
    [['kind' => 'menuItem', 'id' => 629], ['kind' => 'module', 'id' => 441]]);
check('natives: an article names its article row', $ntOut['c-post']['native'], [['kind' => 'article', 'id' => 546]]);
check('natives: a content with no known row names none (an empty list, not a guess)', $ntOut['c-none']['native'], []);
check('natives: revisions are untouched (a native id never changes what a content says)', array_column($ntOut, 'revision'), ['r1', 'r2', 'r3']);
