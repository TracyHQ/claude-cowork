<?php
/**
 * Token — a constant-time comparison, and an endpoint that is OFF until configured.
 *
 * An empty token means the component was installed but never given one, and every request is
 * refused. That is the neighbourly default: putting the extension on a site does not, by itself,
 * open anything. The token is issued per site and can be revoked by clearing the setting.
 */
final class Token
{
    public static function isConfigured(?string $expected): bool
    {
        return is_string($expected) && strlen($expected) >= 16;
    }

    /** True only when a token is configured AND matches. `hash_equals` defeats timing attacks. */
    public static function check(?string $expected, ?string $provided): bool
    {
        if (!self::isConfigured($expected)) {
            return false;
        }
        if (!is_string($provided) || $provided === '') {
            return false;
        }
        return hash_equals($expected, $provided);
    }
}
