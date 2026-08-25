<?php

/**
 * Update checks for a plugin that does not live on wordpress.org.
 *
 * A site administrator expects one thing from every plugin on the Plugins screen: when a new
 * version exists, the row says so and one click takes it. Without this file Tracy is the only
 * plugin on the site that never does, so the site quietly stays on whatever version was installed
 * the day somebody set it up — and every action added later is dead code there.
 *
 * ## Why the `update_plugins_{$hostname}` filter and not the old transient hack
 *
 * The long-standing recipe is to filter `pre_set_site_transient_update_plugins` and splice a row
 * into WordPress's own update payload. That works and is a blunt instrument: it runs for every
 * plugin on the site, hands you the whole update set to mutate, and one mistake there breaks
 * updates for extensions that have nothing to do with us.
 *
 * WordPress 5.8 added a purpose-built door: declare an `Update URI:` header, and WordPress calls
 * `update_plugins_<host of that URI>` for THIS plugin alone, passing its current data and asking
 * for the newer one. Nothing else on the site is reachable from here. The floor that costs is
 * WordPress 5.8 (July 2021); on anything older the header is ignored, the plugin keeps working,
 * and its updates simply stay manual — which is what they are today anyway.
 *
 * ## Why the URI is the repository and the answer comes from a file beside the code
 *
 * The header's host only selects the filter name; WordPress never fetches the URI itself. Pointing
 * it at the repository means the link an administrator clicks is a real page rather than an
 * endpoint that exists to be machine-read. The version data is `wordpress/update.json` in that
 * same repository, so cutting a release and saying so are one commit — two places that must agree
 * cannot drift when they are edited together, and a test in this repo checks they still do.
 */

defined('ABSPATH') || exit;

/** Where the answer comes from. Raw content of the repository's main branch, no infrastructure. */
const CLAUDE_COWORK_UPDATE_MANIFEST = 'https://raw.githubusercontent.com/TracyHQ/claude-cowork/main/wordpress/update.json';

/**
 * How long an answer is kept. WordPress asks about updates roughly twice a day; a cache is not
 * about saving our own request but about the site's page loads never waiting on somebody else's
 * host. Short enough that a release is visible the same day it is cut.
 */
const CLAUDE_COWORK_UPDATE_TTL = 6 * HOUR_IN_SECONDS;

/** The manifest, or null when it cannot be read. Never throws: an update check is not worth a site. */
function claude_cowork_update_manifest(): ?array
{
    $cached = get_site_transient('claude_cowork_update');
    if (is_array($cached)) {
        return $cached;
    }

    $response = wp_remote_get(CLAUDE_COWORK_UPDATE_MANIFEST, ['timeout' => 5]);
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
        // Remembered as a miss for a shorter while, so a host that is down does not mean a
        // request on every admin page load until it comes back.
        set_site_transient('claude_cowork_update', ['version' => ''], 15 * MINUTE_IN_SECONDS);
        return null;
    }

    $parsed = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($parsed) || !isset($parsed['version'], $parsed['package'])) {
        return null;
    }

    set_site_transient('claude_cowork_update', $parsed, CLAUDE_COWORK_UPDATE_TTL);
    return $parsed;
}

/**
 * Answer WordPress's question about THIS plugin: is there something newer, and where is it.
 *
 * `$update` is passed through untouched whenever the answer is no — another plugin sharing this
 * host would be filtered by the same hook, and returning our data for its row is how a site ends
 * up installing the wrong package over the right one.
 */
function claude_cowork_check_update($update, array $plugin_data, string $plugin_file)
{
    if ($plugin_file !== 'claude-cowork/claude-cowork.php') {
        return $update;
    }

    $manifest = claude_cowork_update_manifest();
    $installed = (string) ($plugin_data['Version'] ?? '');
    if ($manifest === null || $installed === '') {
        return $update;
    }
    if (version_compare((string) $manifest['version'], $installed, '<=')) {
        return $update;
    }

    return [
        'id'           => 'github.com/TracyHQ/claude-cowork',
        'slug'         => 'claude-cowork',
        'plugin'       => $plugin_file,
        'version'      => (string) $manifest['version'],
        'url'          => (string) ($manifest['url'] ?? 'https://github.com/TracyHQ/claude-cowork'),
        'package'      => (string) $manifest['package'],
        'requires'     => (string) ($manifest['requires'] ?? ''),
        'requires_php' => (string) ($manifest['requires_php'] ?? ''),
        'tested'       => (string) ($manifest['tested'] ?? ''),
    ];
}

add_filter('update_plugins_github.com', 'claude_cowork_check_update', 10, 3);

/**
 * Take the update WordPress just found, without waiting for somebody to press a button.
 *
 * Knowing a newer version exists and getting it are two different things. The filter above makes
 * the Plugins screen show a notice; WordPress then waits. On a site nobody administers by hand
 * that wait never ends, and every fix shipped afterwards is dead code there — the same failure as
 * a phone app whose owner never taps Update. Measured on tracy.ai, 25/08/2026: the site ran 0.4.0
 * while 0.6.0 had been released, so every `templatePart` write was refused by a plugin that had
 * never heard of the kind, and the refusal read like a bug in something else entirely.
 *
 * ## Why this plugin decides, and not each site owner
 *
 * The one thing this plugin does is let software act on the site. What it accepts, what it
 * refuses, and what it records are all defined by its own version — so a site left behind is not
 * a site missing a feature, it is a site whose safety rules are a different set from the ones the
 * caller is written against. That is not a preference to leave switched off by default.
 *
 * ## The two ways out, both deliberately left open
 *
 * The Plugins screen keeps its own switch: a site owner who turns automatic updates off for this
 * plugin is obeyed, because `auto_update_plugins` is consulted by WordPress before this filter is
 * ever reached for a plugin listed there. And `update.json` is the single place a release is
 * announced — a version that should not spread is un-announced by editing one line, without
 * touching a single site.
 */
function claude_cowork_auto_update($update, $item)
{
    // `$item` describes whichever plugin WordPress is currently deciding about — this filter runs
    // for ALL of them. Answering for anything but our own file is switching on automatic updates
    // for somebody else's extension, on somebody else's site.
    $plugin = is_object($item) && isset($item->plugin) ? (string) $item->plugin : '';
    if ($plugin !== 'claude-cowork/claude-cowork.php') {
        return $update;
    }

    return true;
}

add_filter('auto_update_plugin', 'claude_cowork_auto_update', 10, 2);
