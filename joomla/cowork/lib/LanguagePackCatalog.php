<?php
/**
 * The packages this site will install to add a language — a reviewed file shipped inside the
 * receiver, never a URL a caller hands over.
 *
 * 🔒 THE CALLER NAMES A LOCALE, NOT AN ARCHIVE. `extension.install` takes a URL, which is right for
 * a site nobody has bound: a caller holding the token may add to it. A site under a content-only
 * contract has given that up, and the whole point of the contract is that the set of bytes which
 * may reach it is decided in review rather than at call time. So this door takes `zh-CN` and looks
 * the rest up here; a request carrying its own URL, hash or size is refused even when they happen
 * to be right, because accepting them is accepting the SHAPE of a request that could be wrong.
 *
 * The pin is the host of the first hop plus the sha256 and the byte count. The final URL cannot be
 * pinned: downloads.joomla.org answers 303 to a presigned address that expires in sixty seconds.
 */
final class LanguagePackCatalog
{
    private array $packs;
    private string $sourceLanguage;

    public function __construct(array $catalog, string $sourceLanguage)
    {
        if (($catalog['schemaVersion'] ?? '') !== 'tracy-joomla-language-packs/v1')
            throw new RuntimeException('Unsupported language-pack catalog');
        $this->sourceLanguage = $sourceLanguage;
        $this->packs = [];
        foreach ((array) ($catalog['packs'] ?? []) as $tag => $pack) {
            if (
                ($pack['tag'] ?? null) !== $tag
                || !preg_match('/^[a-z]{2,3}-[A-Z]{2,4}$/D', (string) $tag)
                || !preg_match('/^[a-f0-9]{64}$/D', (string) ($pack['sha256'] ?? ''))
                || !is_int($pack['bytes'] ?? null) || $pack['bytes'] <= 0
                || !is_array($pack['platformMajors'] ?? null) || !$pack['platformMajors']
                || strtolower((string) parse_url((string) ($pack['url'] ?? ''), PHP_URL_SCHEME)) !== 'https'
                || strtolower((string) parse_url((string) $pack['url'], PHP_URL_HOST)) !== 'downloads.joomla.org'
            ) throw new RuntimeException('Invalid language pack in catalog: ' . $tag);
            $this->packs[$tag] = $pack;
        }
        if (!$this->packs) throw new RuntimeException('The language-pack catalog carries no packs');
    }

    /** Every locale this receiver can add on this Joomla, the source language included. */
    public function locales(int $major): array
    {
        $tags = [];
        foreach ($this->packs as $tag => $pack) if (in_array($major, $pack['platformMajors'], true)) $tags[] = $tag;
        return array_values(array_unique(array_merge([$this->sourceLanguage], $tags)));
    }

    /**
     * The archive for one locale, or null when the locale is the source language — which needs no
     * pack because Joomla ships it, and must not be reported as unsupported.
     */
    public function pack(string $locale, int $major): ?array
    {
        if ($locale === $this->sourceLanguage) return null;
        if (!isset($this->packs[$locale]))
            throw new RuntimeException(
                'No verified language pack for ' . $locale . '. Verified: ' . implode(', ', array_values(array_diff($this->locales($major), [$this->sourceLanguage])))
            );
        $pack = $this->packs[$locale];
        if (!in_array($major, $pack['platformMajors'], true))
            throw new RuntimeException('The ' . $locale . ' language pack does not cover Joomla ' . $major);
        return $pack;
    }

    /** The name a content-language row carries, taken from the reviewed catalog rather than invented. */
    public function name(string $locale): string
    {
        return (string) ($this->packs[$locale]['name'] ?? $locale);
    }
}
