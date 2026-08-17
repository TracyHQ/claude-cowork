<?php

/**
 * Plugin Name: Tracy Access for WordPress
 * Description: Signs a coworker in to a Tracy clone from their Cloudflare Access identity — no
 *              WordPress password — and honours the CMS tier the fleet-bar Worker vouched for.
 * Version:     0.1.0
 * License:     GPL-2.0-or-later
 *
 * A must-use plugin: it ships in wp-content/mu-plugins/, is always active, and cannot be turned
 * off from the admin — the clone's auto-login is not a thing an editor should be able to disable.
 *
 * This is WordPress's own implementation, kept deliberately SEPARATE from the Joomla plugin
 * (plg_system_tracyaccess). The two platforms share no code — only the idea. Ported feature by
 * feature, in each platform's own idioms: Joomla fires onUserLogin, WordPress sets an auth cookie.
 *
 * TRUST MODEL. Two headers only this side of the tunnel can set:
 *  - `Cf-Access-Jwt-Assertion` — Cloudflare Access injects it; the signature is verified here.
 *  - `x-tracy-tier` — the fleet-bar Worker sets it, stripping any client value first, so it is
 *    trusted the way the Access assertion is. 'editor' gets a backend session; anything else
 *    (a viewer, or a missing header meaning no seat) reads the front-end as a guest.
 * Both reach the origin only through Cloudflare's tunnel, which no public request can bypass.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Verifies a Cloudflare Access JWT in plain PHP over openssl — no library, no network, no cache.
 *
 * WordPress bundles no JWT verifier, and pulling one in would tie the clone to a Composer setup it
 * does not have. RS256 over openssl is the one primitive always present. WordPress's OWN copy of
 * the routine (the Joomla plugin has its own); shared code between the two platforms is out by
 * design, and a verifier is small enough to own twice rather than couple across a repo boundary.
 */
final class Tracy_Access_Jwt
{
    /**
     * The verified, lowercased email, or null — the only failure a caller sees (fail-closed).
     *
     * Null, not an error, for a valid token with no `email` claim: that is a service token (Tracy's
     * own programmatic reads), and a program must never have a WordPress user minted for it.
     *
     * @param array<int,array> $jwks the team's key set (the `keys` array from the certs endpoint)
     */
    public static function verified_email(string $token, array $jwks, string $expected_aud, string $expected_iss, int $now): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$raw_header, $raw_payload, $raw_signature] = $parts;

        $header = self::decode_json($raw_header);
        $payload = self::decode_json($raw_payload);
        if ($header === null || $payload === null) {
            return null;
        }

        // RS256 only. `alg: none` and an HMAC alg keyed on the public modulus are the two classic
        // JWT forgeries; naming the single algorithm we accept closes both.
        if (($header['alg'] ?? '') !== 'RS256' || !isset($header['kid'])) {
            return null;
        }

        $jwk = self::key_for_kid($jwks, (string) $header['kid']);
        if ($jwk === null) {
            return null;
        }

        $pem = self::jwk_to_pem($jwk);
        if ($pem === null) {
            return null;
        }

        $signature = self::base64url_decode($raw_signature);
        if (openssl_verify($raw_header . '.' . $raw_payload, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }

        // The signature is genuine; now the claims. A valid signature over the wrong audience is a
        // token minted for another clone — replaying it here is exactly what `aud` stops.
        if (($payload['iss'] ?? '') !== $expected_iss) {
            return null;
        }
        if (!self::audience_matches($payload['aud'] ?? null, $expected_aud)) {
            return null;
        }
        if (!isset($payload['exp']) || (int) $payload['exp'] <= $now) {
            return null;
        }
        if (isset($payload['nbf']) && (int) $payload['nbf'] > $now) {
            return null;
        }

        $email = $payload['email'] ?? null;
        return is_string($email) && $email !== '' ? strtolower($email) : null;
    }

    /** `aud` may be a string or an array of strings; match either, in constant time. */
    private static function audience_matches($aud, string $expected): bool
    {
        if (is_string($aud)) {
            return hash_equals($expected, $aud);
        }
        if (is_array($aud)) {
            foreach ($aud as $one) {
                if (is_string($one) && hash_equals($expected, $one)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function key_for_kid(array $jwks, string $kid): ?array
    {
        foreach ($jwks as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $kid && ($key['kty'] ?? null) === 'RSA') {
                return $key;
            }
        }
        return null;
    }

    /**
     * An RSA public JWK (`n`, `e`) as a PEM, built by hand: the modulus and exponent wrapped in the
     * ASN.1 DER of a SubjectPublicKeyInfo. Two INTEGERs in a SEQUENCE, that inside a BIT STRING,
     * beside the rsaEncryption algorithm identifier, in an outer SEQUENCE.
     */
    private static function jwk_to_pem(array $jwk): ?string
    {
        if (!isset($jwk['n'], $jwk['e'])) {
            return null;
        }
        $modulus = self::base64url_decode((string) $jwk['n']);
        $exponent = self::base64url_decode((string) $jwk['e']);
        if ($modulus === '' || $exponent === '') {
            return null;
        }

        $rsa_public_key = self::der_sequence(self::der_integer($modulus) . self::der_integer($exponent));
        // OID 1.2.840.113549.1.1.1 (rsaEncryption) + NULL parameters.
        $algorithm = self::der_sequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00");
        $spki = self::der_sequence($algorithm . self::der_bit_string($rsa_public_key));

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function der_length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function der_integer(string $bytes): string
    {
        // A leading 0x80+ byte would read as negative, so prepend a zero to keep it positive.
        if ($bytes !== '' && (ord($bytes[0]) & 0x80)) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::der_length(strlen($bytes)) . $bytes;
    }

    private static function der_sequence(string $contents): string
    {
        return "\x30" . self::der_length(strlen($contents)) . $contents;
    }

    private static function der_bit_string(string $contents): string
    {
        // The leading 0x00 is the "unused bits" count, always zero for a whole-byte payload.
        return "\x03" . self::der_length(strlen($contents) + 1) . "\x00" . $contents;
    }

    private static function base64url_decode(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder) {
            $padded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode($padded, true);
        return $decoded === false ? '' : $decoded;
    }

    private static function decode_json(string $segment): ?array
    {
        $decoded = json_decode(self::base64url_decode($segment), true);
        return is_array($decoded) ? $decoded : null;
    }
}

/**
 * Signs the Access-verified person in, on every request, honouring their tier.
 *
 * On `init` and not later: a WordPress admin page bounces an unauthenticated request to
 * wp-login.php during `admin_init`, so the auth cookie has to be set before then. Like the Joomla
 * plugin, this logs the person in through WordPress's real machinery (`wp_set_auth_cookie`) rather
 * than faking a session — the admin decides who is logged in from more than one flag.
 */
function tracy_access_sync_identity(): void
{
    $token = isset($_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION']) ? (string) $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] : '';
    if ($token === '') {
        // No Access assertion: a request that did not come through the tunnel, which in normal
        // operation cannot happen. Stand aside and let WordPress behave as it always would.
        return;
    }

    $aud = trim((string) get_option('tracy_access_aud', ''));
    $team_domain = trim((string) get_option('tracy_access_team_domain', ''));
    if ($aud === '' || $team_domain === '') {
        return;
    }

    $jwks = tracy_access_jwks($team_domain);
    if ($jwks === null) {
        return;
    }

    $email = Tracy_Access_Jwt::verified_email($token, $jwks, $aud, 'https://' . $team_domain, time());
    if ($email === null) {
        return;
    }

    $current = wp_get_current_user();
    $signed_in_as_them = $current instanceof WP_User && $current->ID > 0 && strtolower((string) $current->user_email) === $email;

    // The tier the fleet-bar Worker vouched for. An editor gets a backend session; a viewer (and
    // any request the Worker did not mark editor — a missing header means no seat) reads the
    // front-end as a guest. If they were an editor a moment ago and just lost it, end the session
    // now rather than let a stale login outlive the downgrade.
    if (tracy_access_tier() !== 'editor') {
        if ($signed_in_as_them) {
            wp_logout();
        }
        return;
    }

    if ($signed_in_as_them) {
        return;
    }

    $user = tracy_access_user_for_email($email);
    if ($user === null) {
        return;
    }

    // Someone else holds this browser's session: clear it before the new identity takes over, so
    // two people sharing a machine never share a login.
    if ($current instanceof WP_User && $current->ID > 0) {
        wp_logout();
    }

    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);
    do_action('wp_login', $user->user_login, $user);
}

/**
 * The CMS tier the fleet-bar Worker vouched for: 'editor' or 'viewer'. Read from `x-tracy-tier`,
 * which only the Worker can set (it strips any client value before origin), so it is trusted the
 * way the Access assertion is. Anything not exactly 'editor' — a viewer, or a missing header
 * meaning no seat — is treated as viewer: a stray header never mints a backend login.
 */
function tracy_access_tier(): string
{
    $value = isset($_SERVER['HTTP_X_TRACY_TIER']) ? strtolower(trim((string) $_SERVER['HTTP_X_TRACY_TIER'])) : '';
    return $value === 'editor' ? 'editor' : 'viewer';
}

/**
 * The WordPress user for this email, created the first time they open the clone.
 *
 * Lazy on purpose: a seat is not a WordPress account until its holder actually arrives, so there
 * is nothing to keep in step with the seat book. The password is long and random and never used —
 * the only way in is the Access JWT. The role is the configured work role (default `editor`:
 * edits content, touches no settings), deliberately not `administrator` — the same restraint the
 * Joomla plugin shows by choosing Manager over Super User.
 */
function tracy_access_user_for_email(string $email): ?WP_User
{
    $existing = get_user_by('email', $email);
    if ($existing instanceof WP_User) {
        return $existing;
    }

    $role = tracy_access_work_role();
    $login = tracy_access_login_from_email($email);

    $user_id = wp_insert_user([
        'user_login' => $login,
        'user_email' => $email,
        'user_pass' => wp_generate_password(64, true, true),
        'display_name' => $email,
        'role' => $role,
    ]);
    if (is_wp_error($user_id)) {
        return null;
    }
    // A user WordPress made carries a marker, so a later cleanup can tell Tracy's rows from the
    // customer's own accounts — the same intent as the Joomla plugin's `tracyaccess_created` param.
    update_user_meta($user_id, 'tracy_access_created', 1);

    $user = get_user_by('id', $user_id);
    return $user instanceof WP_User ? $user : null;
}

/** A unique, valid login derived from the email — WordPress needs a login even when it keys on email. */
function tracy_access_login_from_email(string $email): string
{
    $base = sanitize_user(current(explode('@', $email)), true);
    if ($base === '') {
        $base = 'coworker';
    }
    $login = $base;
    $suffix = 1;
    while (get_user_by('login', $login) instanceof WP_User) {
        $login = $base . '-' . (++$suffix);
    }
    return $login;
}

/** The role a signed-in editor gets. Set by provision; `editor` when unset. Ignored if not a real role. */
function tracy_access_work_role(): string
{
    $role = trim((string) get_option('tracy_access_work_role', 'editor')) ?: 'editor';
    return wp_roles()->is_role($role) ? $role : 'editor';
}

/**
 * The team's Access signing keys, cached in a transient.
 *
 * Fetched from the team's own certs endpoint. Cached for a day so a request is not a fetch, but a
 * stale-cache miss (a key rotated since) falls through to a live fetch rather than fail. Returns
 * null when there is no usable key set at all — fail-closed, so a fetch outage denies rather than
 * admits.
 */
function tracy_access_jwks(string $team_domain): ?array
{
    $cache_key = 'tracy_access_jwks_' . substr(sha1($team_domain), 0, 12);
    $cached = get_transient($cache_key);
    if (is_array($cached) && !empty($cached)) {
        return $cached;
    }

    $response = wp_remote_get('https://' . $team_domain . '/cdn-cgi/access/certs', ['timeout' => 5]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return null;
    }
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    $keys = is_array($body) && isset($body['keys']) && is_array($body['keys']) ? $body['keys'] : null;
    if ($keys === null || $keys === []) {
        return null;
    }

    set_transient($cache_key, $keys, DAY_IN_SECONDS);
    return $keys;
}

add_action('init', 'tracy_access_sync_identity', 1);
