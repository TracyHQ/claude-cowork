<?php
/**
 * On a quickstart site, a term archive that lists no post answers 404 (TracyHQ/tch#1013, D4): with News unticked,
 * WordPress still answered 200 with an empty listing at /category/news/ and at each retired language's
 * /fr/category/news-fr/ (measured 09/10/2026 on a Tracy Business wp7 1.3.4 stand), because core keeps a term archive
 * whose term exists.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/UnpublishedTermHooks.php';

echo "\nUnpublished term archive\n";

check('every post of the term drafted: 404', UnpublishedTermHooks::notFound(true, true, 0, ['draft']), true);
check('a demo term no post was ever filed under (Tracy Business News): 404', UnpublishedTermHooks::notFound(true, true, 0, []), true);
check('a term that still lists a published post is served', UnpublishedTermHooks::notFound(true, true, 0, ['draft', 'publish']), false);
check('an archive that found posts is served', UnpublishedTermHooks::notFound(true, true, 3, ['draft']), false);
check('a site not bound to a quickstart (a customer\'s own) is never touched', UnpublishedTermHooks::notFound(false, true, 0, ['draft']), false);
check('not a term archive: untouched', UnpublishedTermHooks::notFound(true, false, 0, ['draft']), false);
check('a 404 another filter already handled is passed on', UnpublishedTermHooks::filter(true, null), true);

/** A term archive query, as far as the hook reads it. */
final class FakeTermQuery
{
    public $posts = [];
    public bool $is404 = false;
    private $term;
    public function __construct($term) { $this->term = $term; }
    public function is_category() { return true; }
    public function is_tag() { return false; }
    public function is_tax() { return false; }
    public function get_queried_object() { return $this->term; }
    public function set_404() { $this->is404 = true; }
}
/** `$wpdb` answering the statuses of a term's posts. */
final class FakeTermDb
{
    public string $posts = 'wp_posts';
    public string $term_relationships = 'wp_term_relationships';
    public array $statuses = [];
    public function prepare(string $q, ...$a) { return str_replace('%d', (string) $a[0], $q); }
    public function get_col(string $q) { return strpos($q, 'term_taxonomy_id = 7') !== false ? $this->statuses : []; }
}
WP_Fake::reset();
WP_Fake::$options['_tracy_content_contract'] = json_encode(['contract' => 'tracy-business/wp7/1.3.4']);
$previousDb = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new FakeTermDb();
$GLOBALS['wpdb']->statuses = ['draft'];
$q = new FakeTermQuery((object) ['term_taxonomy_id' => 7]);
check('an empty archive of a drafted term is handled as a 404', [UnpublishedTermHooks::filter(false, $q), $q->is404], [true, true]);
$GLOBALS['wpdb']->statuses = ['publish', 'draft'];
$q = new FakeTermQuery((object) ['term_taxonomy_id' => 7]);
check('one still published: WordPress decides', [UnpublishedTermHooks::filter(false, $q), $q->is404], [false, false]);
$q = new FakeTermQuery((object) ['term_taxonomy_id' => 7]);
$q->posts = [1];
$GLOBALS['wpdb']->statuses = ['draft'];
check('an archive with posts is not read at all', [UnpublishedTermHooks::filter(false, $q), $q->is404], [false, false]);
$q = new FakeTermQuery((object) ['term_taxonomy_id' => 7]);
WP_Fake::$options = [];
check('an unbound site keeps its empty archive', [UnpublishedTermHooks::filter(false, $q), $q->is404], [false, false]);
$GLOBALS['wpdb'] = $previousDb;
