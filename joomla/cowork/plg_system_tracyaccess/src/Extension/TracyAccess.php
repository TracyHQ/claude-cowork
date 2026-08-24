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
 * customer's own site: it writes to `#__users`, which is nothing the cowork component does or
 * should ever do. Kept in its own package for exactly that reason.
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
        // WHO the viewer is — preferred from Tracy's own Worker (`x-tracy-email`), and falling back
        // to the Cloudflare Access JWT while a clone is still fronted by Access. Both are trustworthy
        // on the same ground: only the fleet-bar Worker reaches this origin, so a header it sets (like
        // Cloudflare's) cannot be forged by a client. Null from both means a guest, or a request that
        // did not come through the Worker — stand aside and let Joomla behave as it always would.
        $email = $this->workerEmail() ?? $this->emailFromAccessJwt();
        if ($email === null) {
            return;
        }

        $app     = $this->getApplication();
        $current = $app->getIdentity();

        // The tier the fleet-bar Worker vouched for. An editor gets a backend session; a viewer
        // (and anyone the Worker did not mark editor — a missing header means no seat) reads the
        // front-end as a guest and gets none. If they were an editor a moment ago and just lost
        // it, end the session now rather than let a stale login outlive the downgrade.
        if ($this->tier() !== 'editor') {
            if ($current instanceof User && !$current->guest && strtolower((string) $current->email) === $email) {
                $app->logout($current->id, ['clientid' => $app->isClient('administrator') ? 1 : 0]);
            }
            return;
        }

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

    /**
     * The viewer's email as the fleet-bar Worker vouched for it, or null.
     *
     * Read from `x-tracy-email`, which only the Worker can set — it strips any client value before
     * the request reaches this origin, the same guarantee that lets `x-tracy-tier` be trusted. This
     * is what frees the clone's auto-login from Cloudflare Access: the Worker resolves identity from
     * its own session cookie (`tracy_sess`) and hands it over here, so removing Access no longer
     * blinds the plugin. Lower-cased to match `userForEmail`; a value without an `@` is not an email
     * and is ignored, so a misconfigured header cannot mint a junk user.
     */
    private function workerEmail(): ?string
    {
        $value = strtolower(trim((string) ($_SERVER['HTTP_X_TRACY_EMAIL'] ?? '')));
        return $value !== '' && strpos($value, '@') !== false ? $value : null;
    }

    /**
     * The email from the Cloudflare Access JWT, or null — the legacy path, kept so a clone still
     * fronted by Access (before the migration off it) keeps auto-logging in. Removed once every
     * clone is served identity by the Worker.
     */
    private function emailFromAccessJwt(): ?string
    {
        $token = $this->accessHeader();
        if ($token === null) {
            return null;
        }
        $aud        = trim((string) $this->params->get('aud', ''));
        $teamDomain = trim((string) $this->params->get('team_domain', ''));
        if ($aud === '' || $teamDomain === '') {
            return null;
        }
        require_once __DIR__ . '/../../lib/AccessJwt.php';
        return \AccessJwt::verifiedEmail($token, $this->jwks($teamDomain), $aud, 'https://' . $teamDomain, time());
    }

    /** The raw Access assertion, or null. The bare email header is deliberately ignored. */
    private function accessHeader(): ?string
    {
        $token = $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? '';
        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * The CMS tier the fleet-bar Worker vouched for: 'editor' (may sign in to the backend) or
     * 'viewer' (read-only). Read from `x-tracy-tier`, which only the Worker can set — it strips any
     * client value before the request reaches origin, so this is trusted the way the Access
     * assertion is. Anything that is not exactly 'editor' — a viewer, or a missing header meaning no
     * seat — is treated as viewer, the safe side: a stray header never mints a backend login.
     */
    private function tier(): string
    {
        $value = strtolower(trim((string) ($_SERVER['HTTP_X_TRACY_TIER'] ?? '')));
        return $value === 'editor' ? 'editor' : 'viewer';
    }

    /**
     * The Tracy Role the fleet-bar Worker vouched for: owner|admin|developer|editor. Read from
     * `x-tracy-role`, which only the Worker sets — it strips any client value before the request
     * reaches origin, trusted on the same ground as `x-tracy-tier`. This is what lets the backend
     * group follow the Role (ADR 0038) instead of collapsing everyone to one group. Anything
     * unrecognised — including a missing header from an older Worker — falls to `editor`, the safe
     * floor: an unknown Role never lands someone in an elevated group by accident.
     */
    private function role(): string
    {
        $value = strtolower(trim((string) ($_SERVER['HTTP_X_TRACY_ROLE'] ?? '')));
        return \in_array($value, ['owner', 'admin', 'developer', 'editor'], true) ? $value : 'editor';
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
            $this->applyGroup($existing);
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
            // Create in Manager, never straight into the role's real group: Joomla's User::save()
            // refuses, during a guest request, to place a user in a group higher than the acting
            // identity's, so binding Super Users here would fail the whole save. The role's real
            // group is written afterwards by applyGroup, which goes around that guard.
            'groups'    => [$this->groupIdByTitle('Manager', 6)],
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
        $this->applyGroup($user);
        return $user;
    }

    /**
     * Ensures the user holds the group their Role maps to, writing `#__user_usergroup_map` directly.
     *
     * NOT through `User::save()`: Joomla guards that call so a request acting as a guest (which is
     * what this plugin is until the login below runs) cannot place anyone in a group higher than
     * its own — so `save()` silently drops an Owner into nothing above Manager. The map row is the
     * plugin's to write; it already mints these accounts, and the seat book upstream is the
     * authority on the Role. Additive: it grants the Role's group, it does not strip whatever else
     * the account already holds (a downgrade is handled by the seat losing editor tier, which logs
     * the session out).
     */
    private function applyGroup(User $user): void
    {
        $groupId = $this->workGroupId();
        if (in_array($groupId, array_map('intval', (array) $user->groups), true)) {
            return;
        }
        $db = $this->getDatabase();
        $userId = (int) $user->id;
        // Values are integers this code casts itself, so they are inlined rather than bound — it
        // keeps the method free of any ParameterType dependency, which the wrapping catch would
        // otherwise swallow as a bare login failure if the class ever resolved differently.
        $exists = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__user_usergroup_map'))
                ->where($db->quoteName('user_id') . ' = ' . $userId)
                ->where($db->quoteName('group_id') . ' = ' . (int) $groupId)
        )->loadResult();
        if ($exists > 0) {
            return;
        }
        // insertObject takes its object BY REFERENCE, so — like User::bind above — a literal cannot
        // be passed in PHP 8 ("Only variables should be passed by reference"), which the wrapping
        // catch would swallow as a silent login failure. Hold it in a variable.
        $row = (object) ['user_id' => $userId, 'group_id' => (int) $groupId];
        $db->insertObject('#__user_usergroup_map', $row);
    }

    /**
     * The Joomla group this viewer's Role maps to (ADR 0038, ADR 0085).
     *
     * Owner and Admin — the people who run the site — get `Super Users`: full control of their own
     * site's working copy, which is what "admin" means to the person who owns it. Developer and
     * Editor land in `Manager`, the narrowest backend group that still edits content: a seat is the
     * right to do the WORK (ADR 0023), not automatically the keys to the box. An unrecognised Role
     * falls to Manager, never up.
     *
     * The mapping keys off `x-tracy-role`, sent per request by the Worker; the flat `work_group`
     * param survives only as the Manager-tier override for a site that wants to name that group.
     */
    private function workGroupId(): int
    {
        $role = $this->role();
        if ($role === 'owner' || $role === 'admin') {
            return $this->groupIdByTitle('Super Users', 8);
        }
        $title = trim((string) $this->params->get('work_group', 'Manager')) ?: 'Manager';
        return $this->groupIdByTitle($title, 6);
    }

    /** The `#__usergroups` id for a group title, or a known-default id when the title is missing. */
    private function groupIdByTitle(string $title, int $fallback): int
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__usergroups'))
            ->where($db->quoteName('title') . ' = :title')
            ->bind(':title', $title)
            ->setLimit(1);
        $id = (int) $db->setQuery($query)->loadResult();
        // A clone always has the core groups; the numeric fallback (Super Users 8, Manager 6) is a
        // last resort rather than a silent wrong group.
        return $id > 0 ? $id : $fallback;
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
