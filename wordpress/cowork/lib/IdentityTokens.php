<?php
/**
 * Identity tokens a Tracy quickstart's copy may carry.
 *
 * A Tracy quickstart keeps the customer's name, contact details and social links in one place and
 * writes `{site.name}`, `{contact.email}`... everywhere the copy needs them; the theme replaces the
 * tokens as the page is rendered. Those braces look exactly like a template directive, which a
 * content-only contract refuses as executable structure. So the list is CLOSED and spelled out
 * here: these names, nothing else, and every other `{...}` stays a directive. Same list as the
 * Joomla receiver's `IdentityTokens::NAMES`, because the same copy is written for both platforms.
 */
final class IdentityTokens
{
    public const NAMES = [
        'site.name', 'site.legalName', 'site.slogan', 'site.monogram',
        'contact.email', 'contact.phone', 'contact.tel', 'contact.address', 'contact.hours',
        'social.facebook', 'social.instagram', 'social.linkedin', 'social.tiktok', 'social.youtube',
    ];

    /** The text with every identity token taken out, for the directive check to look at. */
    public static function strip(string $value): string
    {
        $pairs = [];
        foreach (self::NAMES as $name) {
            $pairs['{' . $name . '}'] = '';
        }
        return strtr($value, $pairs);
    }

    /** Whether the text carries a directive once identity tokens are set aside. */
    public static function hasDirective(string $value): bool
    {
        return (bool) preg_match('/\{\/?[a-z][^{}]*\}/i', self::strip($value));
    }
}
