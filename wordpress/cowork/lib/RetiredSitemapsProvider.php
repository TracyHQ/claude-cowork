<?php
/**
 * RetiredSitemapsProvider — one sitemap provider seen without the retired editions (see RetiredSitemaps).
 *
 * Its own file because it extends a WordPress class that exists only once core has loaded its sitemaps:
 * `RetiredSitemaps::wrap` requires it at that moment, never earlier. Everything else is the wrapped provider's.
 */
final class RetiredSitemapsProvider extends WP_Sitemaps_Provider
{
    /** @var WP_Sitemaps_Provider */
    private $inner;

    public function __construct(WP_Sitemaps_Provider $inner)
    {
        $this->inner = $inner;
        $this->name = $inner->name;
        $this->object_type = $inner->object_type;
    }

    public function get_url_list($page_num, $object_subtype = '')
    {
        if (RetiredSitemaps::requestRetired() || RetiredSitemaps::retiredName((string) $object_subtype, MultilingualHooks::retired())) {
            return [];
        }
        return $this->inner->get_url_list($page_num, $object_subtype);
    }

    public function get_max_num_pages($object_subtype = '')
    {
        if (RetiredSitemaps::requestRetired() || RetiredSitemaps::retiredName((string) $object_subtype, MultilingualHooks::retired())) {
            return 0;
        }
        return $this->inner->get_max_num_pages($object_subtype);
    }

    public function get_sitemap_type_data()
    {
        return RetiredSitemaps::keptTypes((array) $this->inner->get_sitemap_type_data(), MultilingualHooks::retired());
    }

    public function get_sitemap_url($name, $page)
    {
        return $this->inner->get_sitemap_url($name, $page);
    }

    public function get_object_subtypes()
    {
        return $this->inner->get_object_subtypes();
    }
}
