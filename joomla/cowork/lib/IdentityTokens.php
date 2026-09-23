<?php

/**
 * Identity tokens a Tracy quickstart's copy may carry.
 *
 * A Tracy quickstart keeps its name, contact and social links in one "[Tracy] Site identity" module;
 * everywhere else the copy says `{site.name}`, `{contact.email}`…, and `plg_system_tracyidentity`
 * (shipped inside the quickstart archive, not in this package) replaces them as the page is sent.
 * Those braces look exactly like a Joomla content-plugin directive, which this receiver refuses as
 * executable structure. So the list is CLOSED and spelled out here: these names, nothing else, and
 * every other `{...}` stays a directive. The list must equal `Identity::NAMES` in the plugin.
 */
final class IdentityTokens
{
    public const NAMES = [
        'site.name', 'site.legalName', 'site.slogan', 'site.monogram',
        'contact.email', 'contact.phone', 'contact.tel', 'contact.address', 'contact.hours',
        'social.facebook', 'social.instagram', 'social.linkedin', 'social.tiktok', 'social.youtube',
    ];

    /** The text with every identity token taken out, for the directive checks to look at. */
    public static function strip(string $value): string
    {
        $pairs = [];
        foreach (self::NAMES as $name) $pairs['{' . $name . '}'] = '';
        return strtr($value, $pairs);
    }

    /** Whether the text carries a Joomla directive once identity tokens are set aside. */
    public static function hasDirective(string $value): bool
    {
        return (bool) preg_match('/\{\/?[a-z][^{}]*\}/i', self::strip($value));
    }
}
