<?php
/**
 * A retired edition leaves the sitemap (TracyHQ/tch#1013, D11): after `multilingual.retire` took a Tracy Business wp7
 * 1.3.4 stand down to its source edition, wp-sitemap.xml still named a users sitemap for each of the 41 languages
 * (measured 09/10/2026). Its posts are right (drafted), so only the per-language names are left out, and a sitemap
 * page asked for in a retired language lists nothing (WordPress answers 404).
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/MultilingualHooks.php';
require_once __DIR__ . '/../lib/RetiredSitemaps.php';

if (!class_exists('WP_Sitemaps_Provider')) {
    /** Core's base class, as far as the wrapper uses it. */
    abstract class WP_Sitemaps_Provider
    {
        public $name = '';
        public $object_type = '';
        abstract public function get_url_list($page_num, $object_subtype = '');
        abstract public function get_max_num_pages($object_subtype = '');
        public function get_sitemap_type_data() { return []; }
        public function get_sitemap_url($name, $page) { return '/' . $name . '-' . $page . '.xml'; }
        public function get_object_subtypes() { return []; }
        /** Core 7.1: one entry per page of each type. */
        public function get_sitemap_entries()
        {
            $out = [];
            foreach ($this->get_sitemap_type_data() as $type) {
                for ($page = 1; $page <= $type['pages']; $page++) {
                    $out[] = ['loc' => $this->get_sitemap_url($type['name'], $page)];
                }
            }
            return $out;
        }
    }
}
if (!function_exists('pll_current_language')) {
    /** The language Polylang gives this request. */
    function pll_current_language($field = 'slug')
    {
        return WP_Fake::$requestLanguage !== '' ? WP_Fake::$requestLanguage : false;
    }
}

/** Polylang's users provider: no subtype, so one entry per language. */
final class FakeUsersSitemap extends WP_Sitemaps_Provider
{
    public $name = 'users';
    public $object_type = 'user';
    public function get_url_list($page_num, $object_subtype = '') { return [['loc' => '/author/editorial/']]; }
    public function get_max_num_pages($object_subtype = '') { return 1; }
    public function get_sitemap_type_data()
    {
        return [['name' => '', 'pages' => 1], ['name' => '---pll-sep---af', 'pages' => 1], ['name' => '---pll-sep---pt-br', 'pages' => 1], ['name' => 'page---pll-sep---pt', 'pages' => 1]];
    }
}

echo "\nRetired sitemaps\n";

$retiredBinding = static function (array $retired): void {
    WP_Fake::reset();
    WP_Fake::$options[MultilingualHooks::STORE_OPTION] = json_encode(['multilingual' => ['status' => 'complete', 'retired' => $retired, 'live' => ['en']]]);
    MultilingualHooks::reset();
};

check('a retired language\'s sitemap name is recognised', RetiredSitemaps::retiredName('page---pll-sep---af', ['af']), true);
check('a users sitemap (no subtype) too', RetiredSitemaps::retiredName('---pll-sep---af', ['af']), true);
check('pt is not pt-br', RetiredSitemaps::retiredName('page---pll-sep---pt-br', ['pt']), false);
check('the source edition\'s sitemap (no language) stays', RetiredSitemaps::retiredName('page', ['af']), false);

$retiredBinding([]);
$plain = new FakeUsersSitemap();
check('no retired set: the provider is not wrapped', RetiredSitemaps::wrap($plain, 'users'), $plain);

$retiredBinding(['af', 'pt-br']);
$wrapped = RetiredSitemaps::wrap($plain, 'users');
checkTrue('a retired set: the provider is wrapped', $wrapped instanceof RetiredSitemapsProvider);
check('under its own name and type', [$wrapped->name, $wrapped->object_type], ['users', 'user']);
check('wrapped once', RetiredSitemaps::wrap($wrapped, 'users'), $wrapped);
check('the index keeps the source edition and the live ones only',
    array_column($wrapped->get_sitemap_entries(), 'loc'), ['/-1.xml', '/page---pll-sep---pt-1.xml']);
check('a sitemap page in the source edition lists what it listed', $wrapped->get_url_list(1, ''), [['loc' => '/author/editorial/']]);
WP_Fake::$requestLanguage = 'af';
check('a sitemap page asked for in a retired language lists nothing (404)', [$wrapped->get_url_list(1, ''), $wrapped->get_max_num_pages('')], [[], 0]);
WP_Fake::$requestLanguage = '';
check('a retired subtype name lists nothing', $wrapped->get_url_list(1, 'page---pll-sep---af'), []);
check('something that is not a provider is passed on', RetiredSitemaps::wrap('x', 'users'), 'x');
$retiredBinding([]);
