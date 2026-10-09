<?php
/**
 * HomeTitle — the browser tab of a site's home page reads the site name alone once Tracy has put the
 * site name in every page title (TCH #1013, D1; spec NAME-1: "Tamarind Bakery", never
 * "Home - Tamarind Bakery" nor "Tamarind Bakery - Tamarind Bakery"), while every other page keeps
 * "Page - Name".
 *
 * Joomla core cannot do this with a setting. With Global Configuration › Site Name in Page Titles on
 * "after" (`sitename_pagetitles` = 2), `HtmlView::setDocumentTitle` formats every non-empty title as
 * `JPAGETITLE` ("%1$s - %2$s"), and the home entry's empty browser page title falls back to the
 * entry's own title ("Home"), never to the site name. So the system plugin, on the home page only,
 * takes the page part back off ({@see of}).
 *
 * 🔒 ONLY WHEN TRACY SET THE SWITCH. `site.identity` keeps the value it last wrote for the switch in
 * configuration.php under {@see MARK}, in the same write and the same undo step as the switch
 * itself, so `apply.revert` takes both back together. A site whose owner turned the switch on in the
 * administrator has no mark, or a mark that differs, and its titles are Joomla's own.
 *
 * No Joomla dependency, like the rest of `lib/`.
 */
final class HomeTitle
{
    /**
     * The configuration.php key holding the `sitename_pagetitles` value Tracy last set through
     * `site.identity`; absent when Tracy never set it. Never read or written by a caller of the door.
     */
    public const MARK = 'tracy_sitename_pagetitles';

    /** Joomla's own en-GB `JPAGETITLE`, used when the language gives none with both places. */
    public const DEFAULT_FORMAT = '%1$s - %2$s';

    /**
     * The home page's title as it should be printed, or null to leave it as it is.
     *
     * The site name alone when the switch is "after" (2), Tracy set it so ({@see MARK} = 2), and the
     * title is exactly what Joomla made of some page title and the site name with `$format` ("Home -
     * Name", "Name - Name"). Any other title, a page title the owner wrote by hand, is left.
     *
     * @param mixed $setting `sitename_pagetitles` as the site holds it now
     * @param mixed $mark    {@see MARK} as the site holds it now (null when absent)
     * @param string $format `JPAGETITLE` in the page's language
     */
    public static function of(string $title, string $sitename, $setting, $mark, string $format = self::DEFAULT_FORMAT): ?string
    {
        if (!is_numeric($setting) || (int) $setting !== 2 || !is_numeric($mark) || (int) $mark !== 2) return null;
        if (trim($sitename) === '' || $title === $sitename) return null;
        if (strpos($format, '%1$s') === false || strpos($format, '%2$s') === false) $format = self::DEFAULT_FORMAT;
        $pattern = '';
        foreach (preg_split('/(%[12]\$s)/', $format, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
            if ($part === '%1$s') $pattern .= '(.+)';
            elseif ($part === '%2$s') $pattern .= preg_quote($sitename, '/');
            else $pattern .= preg_quote($part, '/');
        }
        return preg_match('/^' . $pattern . '$/Dsu', $title) === 1 ? $sitename : null;
    }
}
