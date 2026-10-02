<?php
/**
 * SecretLeaf — which settings and values a derived content map must never list as slots.
 *
 * A derived contract maps the words, links and pictures of an imported site's own rows to slots, wherever a
 * theme or a builder keeps them: module and menu params, widget and plugin options. Until a derive has
 * rendered the pages to see what is visible, it keeps EVERY string leaf of those settings (`DerivedMap::build`,
 * `$seen === null`), so a password, an API key or a webhook address that the owner typed into a plugin's
 * settings was a slot like any heading: listed by `content.contract inspect`, shown by `content.read`, and
 * copied by the host into files that an AI agent and the model behind it read.
 *
 * Two independent signals; either one leaves a leaf out:
 *   - the NAME of the setting (a JSON or serialized key, an option's name): `smtp`, `api_key`, `password`,
 *     `client_secret`... Everything under such a name goes, whatever it looks like;
 *   - the SHAPE of a value, for the settings that are named innocently: a Google, Stripe or AWS key, a JWT, a
 *     Slack or GitHub token, a webhook address, a private key block, a URL that carries `user:password@` or a
 *     token, an opaque token.
 *
 * ⚠ BIASED TOWARDS LEAVING OUT, on purpose: a heading nobody can edit by slot costs one `content.update` of
 * its record; a key that reached a model's context is out for good. What is never left out is what a customer
 * asks to change: words, e-mails, phone numbers, ordinary links, picture paths. `tests/secret-leaf.php` pins
 * both sides.
 *
 * Plain PHP, no CMS. Identical in the Joomla and WordPress engines.
 */
declare(strict_types=1);

final class SecretLeaf
{
    /** A setting NAME that holds a credential or a private value, whatever its value looks like. */
    private const NAME = '/secret|passw(?:or)?d|token|api[_-]?key|apikey|private[_-]?key|privatekey|credential|webhook'
        . '|licen[sc]e[_-]?key|smtp|client[_-]?secret|access[_-]?key|signature[_-]?(?:key|secret)|bearer/i';

    /**
     * `pass` and `pwd` as a word of their own (`db_pass`), never inside `passport` or `bypass`; `salt` only as
     * the last word (`auth_salt`), so a setting about Salt Lake City is left alone.
     */
    private const NAME_WORD = '/(?:^|[^a-z])(?:pass|pwd)(?:$|[^a-z])|(?:^|[^a-z])salt$/i';

    /** Shapes that give a value away wherever it sits. Each is anchored on its own prefix, never on a length. */
    private const SHAPES = [
        // Google API key
        '/(?<![0-9A-Za-z])AIza[0-9A-Za-z_-]{30,}/',
        // Stripe secret, publishable and restricted keys
        '/(?<![0-9A-Za-z])(?:sk|pk|rk)_(?:live|test)_[0-9A-Za-z]{10,}/',
        // AWS access key id
        '/(?<![0-9A-Za-z])(?:AKIA|ASIA)[0-9A-Z]{16}(?![0-9A-Za-z])/',
        // JWT: three base64url parts, the first opening with the encoded `{"`
        '/(?<![0-9A-Za-z])eyJ[0-9A-Za-z_-]{8,}\.[0-9A-Za-z_-]{8,}\.[0-9A-Za-z_-]{8,}/',
        // Slack and GitHub tokens
        '/(?<![0-9A-Za-z])xox[abprs]-[0-9A-Za-z-]{10,}/',
        '/(?<![0-9A-Za-z])gh[pousr]_[0-9A-Za-z]{30,}/',
        '/(?<![0-9A-Za-z])github_pat_[0-9A-Za-z_]{40,}/',
        // Slack and Discord webhook addresses: the address IS the credential
        '~hooks\.slack\.com/services/[0-9A-Za-z/_-]{10,}~i',
        '~discord(?:app)?\.com/api/webhooks/\d+/[0-9A-Za-z_-]{20,}~i',
        // A private key block
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        // An Authorization header value
        '~^(?:bearer|basic)\s+[0-9A-Za-z._\~+/=-]{16,}$~i',
        // A URL that carries `user:password@`. The scheme is bounded so a long run of letters cannot make the
        // scan quadratic.
        '~(?<![a-z0-9+.-])[a-z][a-z0-9+.-]{1,20}://[^\s/:@]+:[^\s/@]+@~i',
        // A URL that carries a secret-looking query parameter
        '~[?&;#](?:api[_-]?key|apikey|key|token|access[_-]?token|auth[_-]?token|secret|client[_-]?secret|password|passwd|pwd|sig|signature)=[^&#\s]{6,}~i',
    ];

    /** A value that is nothing but hex, 32 characters or more (an MD5, a SHA, a Mailchimp key `…-us12`). */
    private const HEX = '/^[0-9a-fA-F]{32,}(?:-[a-z]{2}\d{1,2})?$/';

    /**
     * The characters of an opaque token. No `/`, `.` or `:`, so a link, a path or an e-mail address is never
     * one: those are ordinary content and are judged by the shapes above only.
     */
    private const OPAQUE = '/^[0-9A-Za-z_+=-]{24,}$/';

    /** How much of a value is read. A secret is short; the cap keeps a 20 kB paragraph cheap. */
    private const SCAN = 4096;

    /**
     * Whether a setting NAME says its value is a credential.
     *
     * @param string $name an option's name, or one key of a JSON or serialized setting
     */
    public static function name(string $name): bool
    {
        return preg_match(self::NAME, $name) === 1 || preg_match(self::NAME_WORD, $name) === 1;
    }

    /**
     * Whether a value's SHAPE says it is a credential.
     */
    public static function value(string $value): bool
    {
        $shown = substr(trim($value), 0, self::SCAN);
        if ($shown === '') return false;
        if (preg_match(self::HEX, $shown) === 1 || self::opaque($shown)) return true;
        foreach (self::SHAPES as $shape) {
            if (preg_match($shape, $shown) === 1) return true;
        }
        return false;
    }

    /**
     * An opaque token: no whitespace, 24 characters or more, letters and digits mixed. A name made of words and
     * short numbers (`Summer-Sale-2024-Collection-Page`) is not one.
     */
    private static function opaque(string $value): bool
    {
        if (preg_match(self::OPAQUE, $value) !== 1 || preg_match('/[A-Za-z]/', $value) !== 1 || preg_match('/\d/', $value) !== 1) return false;
        $parts = array_values(array_filter(preg_split('/[-_]/', $value) ?: [], static fn($p) => $p !== ''));
        if (count($parts) > 1) {
            foreach ($parts as $part) {
                if (preg_match('/^(?:[A-Za-z]{2,}|\d{1,4})$/', $part) !== 1) return true;
            }
            return false;
        }
        return true;
    }
}
