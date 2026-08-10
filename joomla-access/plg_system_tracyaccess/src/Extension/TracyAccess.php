<?php

/**
 * @package     Tracy Access for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Plugin\System\TracyAccess\Extension;

use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Authentication\AuthenticationResponse;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Signs a coworker in to a fleet CLONE as themselves, from the Cloudflare Access JWT that already
 * proved they hold a seat.
 *
 * This plugin exists ONLY on a clone, installed at provision time. It must never reach a
 * customer's own site: it writes to `#__users`, which is the opposite of everything the reader
 * component is for. Kept in its own package for exactly that reason.
 *
 * Why it is safe to trust a request header at the origin: the origin has no public address, only
 * a tunnel, and every request has passed Cloudflare Access. But the network is not the check —
 * the signature is. The header is verified (RS256, this clone's `aud`, the team's issuer, expiry)
 * on every request, and the bare `Cf-Access-Authenticated-User-Email` header is never trusted on
 * its own, because a header is only as good as what signed it.
 */
final class TracyAccess extends CMSPlugin implements SubscriberInterface, DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    /** JWKS is cached this long; Cloudflare rotates keys slowly and a miss refetches anyway. */
    private const JWKS_TTL = 86400;

    public static function getSubscribedEvents(): array
    {
        return ['onAfterInitialise' => 'onAfterInitialise'];
    }

    public function onAfterInitialise(): void
    {
        try {
            $this->syncIdentity();
        } catch (\Throwable $e) {
            // Never let this take a page down. A failure here means the normal Joomla login
            // screen, not a broken site — the same fail-closed rule the verifier follows.
            $this->warn('tracyaccess: ' . $e->getMessage());
        }
    }

    private function syncIdentity(): void
    {
        $token = $this->accessHeader();
        if ($token === null) {
            // No Access JWT: a request that did not come through the tunnel, which in normal
            // operation cannot happen. Stand aside and let Joomla behave as it always would.
            return;
        }

        $aud        = trim((string) $this->params->get('aud', ''));
        $teamDomain = trim((string) $this->params->get('team_domain', ''));
        if ($aud === '' || $teamDomain === '') {
            $this->warn('tracyaccess: not configured (aud/team_domain missing)');
            return;
        }

        require_once __DIR__ . '/../../lib/AccessJwt.php';

        $email = \AccessJwt::verifiedEmail(
            $token,
            $this->jwks($teamDomain),
            $aud,
            'https://' . $teamDomain,
            time()
        );

        // Null covers three cases that all end the same way: an unverifiable token, and a valid
        // one carrying no email (a service token — Tracy's own reads must not mint a user).
        if ($email === null) {
            return;
        }

        $app     = $this->getApplication();
        $current = $app->getIdentity();

        // Already this person: nothing to do. Re-verifying every request is cheap; re-logging in
        // every request is not, and would churn the session needlessly.
        if ($current instanceof User && !$current->guest && strtolower((string) $current->email) === $email) {
            return;
        }

        $user = $this->userForEmail($email);
        if ($user === null) {
            return;
        }

        // Someone else is in this browser's session. Log them out first, so the new identity
        // does not inherit anything of the old one — two people sharing a machine must not
        // share a login.
        if ($current instanceof User && !$current->guest) {
            $app->logout($current->id, ['clientid' => $app->isClient('administrator') ? 1 : 0]);
        }

        $this->logIn($user);
    }

    /**
     * Logs a verified user in through Joomla's own machinery — not by setting the session by hand.
     *
     * `$session->set('user', ...)` looks like it should work and does not: the administrator app
     * decides on each request whether someone is logged in, and that decision reads more than the
     * session key — it wants the state that a real login sets up (session record, metadata, the
     * events other plugins hang off). Measured on Joomla 4.3.4 (2026-08-11): setting the session
     * user directly left the very page that triggered it still rendering its login form.
     *
     * The JWT IS the authentication, so this skips the password check and goes straight to the
     * step Joomla runs AFTER a password succeeds: fire `onUserLogin`, which `plg_user_joomla`
     * turns into a proper session, then `onUserAfterLogin`. Exactly what `CMSApplication::login()`
     * does once credentials check out.
     */
    private function logIn(User $user): void
    {
        $app = $this->getApplication();

        $response = new AuthenticationResponse();
        $response->status   = Authentication::STATUS_SUCCESS;
        $response->type     = 'TracyAccess';
        $response->username = $user->username;
        $response->email    = $user->email;
        $response->fullname = $user->name;

        $options = ['action' => $app->isClient('administrator') ? 'core.login.admin' : 'core.login.site'];

        PluginHelper::importPlugin('user');
        $results = $app->triggerEvent('onUserLogin', [(array) $response, $options]);

        // A user plugin returning false means the login did not complete (blocked, not authorised
        // for this client). Do not run the after-login events on a login that failed.
        if (!in_array(false, $results, true)) {
            $app->triggerEvent('onUserAfterLogin', [$options]);
        }
    }

    /** The raw Access assertion, or null. The bare email header is deliberately ignored. */
    private function accessHeader(): ?string
    {
        $token = $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? '';
        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Finds the Joomla user for this email, creating one the first time.
     *
     * Lazy on purpose: a seat is not a Joomla account until its holder actually opens the clone,
     * so there is nothing to keep in step with the seat book. The password is random and unusable
     * — the only way in is the Access JWT, which is the whole point.
     */
    private function userForEmail(string $email): ?User
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('email') . ' = :email')
            ->bind(':email', $email)
            ->setLimit(1);
        $id = (int) $db->setQuery($query)->loadResult();

        if ($id > 0) {
            $existing = new User($id);
            $this->ensureGroup($existing);
            return $existing;
        }

        $user = new User();
        // Joomla's User::bind() takes its array BY REFERENCE, so a literal cannot be passed in
        // PHP 8 — the data has to be a variable. Measured against a real Joomla 4.3.4 (2026-08-11):
        // passing a literal throws "could not be passed by reference", caught and swallowed, so
        // the login silently did nothing.
        $data = [
            'name'      => $email,
            'username'  => $email,
            'email'     => $email,
            // A bcrypt of random bytes: a real hash that no password will ever match.
            'password'  => UserHelper::hashPassword(bin2hex(random_bytes(32))),
            'groups'    => [$this->workGroupId()],
            // Marks the rows this plugin owns, so a cleanup can find them and a human can tell
            // them from the customer's own admins.
            // Joomla wraps params into a Registry itself, so this is an array, not a
            // JSON string — passing a string throws inside save().
            'params'    => ['tracyaccess_created' => 1],
        ];
        if (!$user->bind($data) || !$user->save()) {
            $this->warn('tracyaccess: could not create user for ' . $email . ': ' . implode('; ', $user->getErrors()));
            return null;
        }
        return $user;
    }

    /** Puts an existing user into the work group if they are not already in it. */
    private function ensureGroup(User $user): void
    {
        $groupId = $this->workGroupId();
        if (!in_array($groupId, array_map('intval', (array) $user->groups), true)) {
            $user->groups[] = $groupId;
            $user->save();
        }
    }

    /**
     * The Joomla group everyone through the door lands in.
     *
     * NOT Super Users, and by default NOT Administrator either. Administrator in Joomla can
     * install extensions and manage users, which on a clone — a full copy of the customer's site,
     * password hashes and all — is the power to exfiltrate everything. A seat means the right to
     * do the WORK (ADR 0023), and the narrowest core group that lets someone edit content in the
     * backend is `Manager`. The group name is a param, so a site that genuinely wants more can
     * raise it, but the default does not hand a content editor the keys to the box.
     *
     * The provision account keeps Super Users; nobody through Access is ever put there.
     */
    private function workGroupId(): int
    {
        $title = trim((string) $this->params->get('work_group', 'Manager')) ?: 'Manager';
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__usergroups'))
            ->where($db->quoteName('title') . ' = :title')
            ->bind(':title', $title)
            ->setLimit(1);
        $id = (int) $db->setQuery($query)->loadResult();
        // A clone always has the core groups; falling back to Manager's usual id is a last resort
        // rather than a silent Super User.
        return $id > 0 ? $id : 6;
    }

    /**
     * The team's key set, cached to a file for a day.
     *
     * The one network call this plugin makes, and only when the cache is cold or a `kid` is
     * unknown. Fail-closed: if the keys cannot be fetched and none are cached, it returns an
     * empty set, the verifier trusts nothing, and the normal login screen appears.
     */
    private function jwks(string $teamDomain): array
    {
        $file = $this->cachePath($teamDomain);
        $cached = $this->readCache($file);
        if ($cached !== null) {
            return $cached;
        }

        $url = 'https://' . $teamDomain . '/cdn-cgi/access/certs';
        $body = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => 5, 'ignore_errors' => true],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]));
        $keys = is_string($body) ? (json_decode($body, true)['keys'] ?? null) : null;

        if (is_array($keys) && $keys !== []) {
            @file_put_contents($file, json_encode($keys), LOCK_EX);
            return $keys;
        }

        // Fetch failed. A stale cache is better than none — Cloudflare's keys outlive their TTL,
        // and locking the whole site out because the certs endpoint blinked is the wrong trade.
        return $this->readCache($file, true) ?? [];
    }

    private function cachePath(string $teamDomain): string
    {
        $tmp = Factory::getApplication()->get('tmp_path', sys_get_temp_dir());
        return rtrim($tmp, '/') . '/tracyaccess-jwks-' . substr(sha1($teamDomain), 0, 12) . '.json';
    }

    /** @return array<int,array>|null */
    private function readCache(string $file, bool $ignoreAge = false): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        if (!$ignoreAge && (time() - (int) @filemtime($file)) > self::JWKS_TTL) {
            return null;
        }
        $keys = json_decode((string) @file_get_contents($file), true);
        return is_array($keys) && $keys !== [] ? $keys : null;
    }

    private function warn(string $message): void
    {
        Log::add($message, Log::WARNING, 'plg_system_tracyaccess');
    }
}
