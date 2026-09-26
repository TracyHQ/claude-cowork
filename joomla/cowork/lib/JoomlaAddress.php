<?php
/**
 * The path a visitor sees for a menu item or an article, from Joomla's own router.
 *
 * content.read answers inside the Cowork door's request, where the language filter's router rules
 * are not attached: Joomla's router there builds `/projects` for a Russian page the site serves at
 * `/ru/projects`, and `/?Itemid=623` for an alias menu item. Measured on a Tracy Business j6 site
 * (26/09/2026): 163 addresses, every language prefix missing, 34 left as `?Itemid=`. So the prefix
 * is added here from the site's own language settings, the same rule the language filter applies
 * when the site renders; a menu heading, separator or alias is no page of its own and has none.
 *
 * Pure: the rows and the raw router are handed in, so tests run without Joomla.
 */
final class JoomlaAddress
{
    /** @var array<int, array> */
    private array $menu;
    /** @var array<int, array> */
    private array $articles;
    /** @var array<string, string> lang_code => sef, published languages only */
    private array $sef = [];
    private array $filter;
    private string $basePath;
    /** @var callable(string): ?string */
    private $route;

    /**
     * @param array $filter {enabled: bool, removeDefaultPrefix: bool, defaultLanguage: string}
     * @param string $basePath the site's folder as a route starts with it (`/` or `/sub`)
     * @param callable(string): ?string $route Joomla's relative site route of a non-SEF query
     */
    public function __construct(array $menu, array $articles, array $languages, array $filter, string $basePath, callable $route)
    {
        $this->menu = array_column($menu, null, 'id');
        $this->articles = array_column($articles, null, 'id');
        foreach ($languages as $language)
            if ((int) ($language['published'] ?? 0) === 1) $this->sef[(string) $language['lang_code']] = (string) $language['sef'];
        $this->filter = $filter;
        $this->basePath = rtrim($basePath, '/');
        $this->route = $route;
    }

    /**
     * The routed path of `page` (a menu item id) or `article` (an article id); false when the row
     * is not a page of this site (a menu heading, a separator, a link elsewhere); null when the
     * router could not say.
     * @return string|false|null
     */
    public function path(string $kind, int $id)
    {
        if ($kind === 'page') {
            $item = $this->menu[$id] ?? null;
            if ($item === null) return null;
            $type = (string) ($item['type'] ?? '');
            if ($type === 'heading' || $type === 'separator') return false;
            if ($type === 'url') {
                $link = (string) ($item['link'] ?? '');
                return $link !== '' && $link[0] === '/' && ($link[1] ?? '') !== '/' ? $link : false;
            }
            // An alias menu item is a second entry to another item's page: that item's row carries the
            // address, so a pasted link names the page once (26/09: `/en/services` matched six rows).
            if ($type === 'alias') return false;
            return $this->prefixed(($this->route)('index.php?Itemid=' . $id), (string) ($item['language'] ?? '*'));
        }
        if ($kind === 'article') {
            $row = $this->articles[$id] ?? null;
            if ($row === null) return null;
            $query = 'index.php?option=com_content&view=article&id=' . $id . '&catid=' . (int) $row['catid'];
            return $this->prefixed(($this->route)($query), (string) ($row['language'] ?? '*'));
        }
        return null;
    }

    private function prefixed(?string $path, string $language): ?string
    {
        if (!is_string($path) || $path === '' || $path[0] !== '/') return null;
        // A route the router could not make SEF is not an address a customer copies.
        if (preg_match('~[?&](Itemid|option)=~', $path)) return null;
        if (empty($this->filter['enabled'])) return $path;
        $default = (string) ($this->filter['defaultLanguage'] ?? '');
        $language = $language === '*' ? $default : $language;
        $sef = $this->sef[$language] ?? null;
        if ($sef === null) return $path;
        if (!empty($this->filter['removeDefaultPrefix']) && $language === $default) return $path;
        $rest = substr($path, strlen($this->basePath));
        if ($rest === '/' . $sef || str_starts_with($rest, '/' . $sef . '/')) return $path;
        return $this->basePath . '/' . $sef . ($rest === '/' ? '/' : $rest);
    }
}
