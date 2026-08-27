<?php

/**
 * Plugin Name: Tracy Claude Cowork
 * Plugin URI:  https://github.com/TracyHQ/claude-cowork
 * Update URI:  https://github.com/TracyHQ/claude-cowork
 * Description: Lets an AI assistant work on this site over one token-authenticated endpoint: it can read the database, the files and what is installed, and — only when you ask for it — install a plugin or theme, turn it on, edit a post, or add a file to your media. Edits to posts and media are recorded so they can be put back. Installing is additive and the CMS owns the uninstall, so an install is undone the same way you would undo it yourself. Remove the token to switch it off.
 * Version:     0.8.0
 * Author:      Tracy
 * License:     GPL-2.0-or-later
 * Text Domain: claude-cowork
 */

defined('ABSPATH') || exit;

// Update checks. Its own file because it is the one part of this plugin that talks to somebody
// else's host, and the rest of the plugin must stay readable without it.
require_once __DIR__ . '/update.php';

/**
 * The whole HTTP surface, and deliberately the only WordPress-aware file of any size.
 *
 * Everything real — routing an action, bounding the work, dumping the database, walking the
 * webroot, writing tar, uploading a part — lives in `lib/`, which knows nothing about WordPress
 * and is unit tested on its own. This file reads one request, hands it to the engine as a plain
 * array, and prints the answer. The Joomla component is the same shape for the same reason:
 * this runs on a customer's production site, so the less that has to be trusted there, the
 * better.
 *
 * ## Why admin-ajax and not the REST API
 *
 * The REST API is the modern answer and the wrong one here. It arrived in **WordPress 4.7**
 * (December 2016), it can be switched off or filtered by a security plugin, and reaching it
 * over a pretty permalink depends on rewrite rules that a broken .htaccess takes away —
 * `?rest_route=` survives that, but not the other two.
 *
 * `admin-ajax.php` has existed since **WordPress 2.8**, is a real file with a real path, and
 * needs neither permalinks nor a filter's permission. It is the oldest stable routing contract
 * WordPress has, which is the same test the Joomla component applied when it chose
 * `index.php?option=` over `com_ajax`.
 *
 * One endpoint, not two: a second door is a second thing to keep safe for a convenience nobody
 * asked for.
 */

const CLAUDE_COWORK_ACTION = 'claude_cowork';
const CLAUDE_COWORK_TOKEN_OPTION = 'claude_cowork_token';

/**
 * `lib/` is plain PHP with no namespace, so it is required rather than autoloaded, and only on
 * the request that needs it — every other page load on the site pays nothing for this plugin
 * being installed.
 */
function claude_cowork_load_engine(): void
{
    $lib = __DIR__ . '/lib';

    foreach (['SqlValue', 'RowSource', 'DbDumper', 'FileWalker', 'TarStream', 'Uploader', 'Token', 'SiteWriter', 'ChangeStamp', 'Engine', 'MysqliRowSource'] as $class) {
        require_once $lib . '/' . $class . '.php';
    }

    // WordPress names its files `class-*.php`, and the write side is new code written to that
    // convention rather than to the PSR shape the engine inherited from the Joomla original.
    require_once $lib . '/class-claude-cowork-packages.php';
    require_once $lib . '/class-claude-cowork-writers.php';
}

/**
 * This plugin's own version, read from the header above.
 *
 * Read rather than held as a constant so there is one place a version lives and nothing to keep
 * in step. It exists because a caller has to be able to tell an old copy from a new one: an
 * already-installed plugin is deliberately not reinstalled on every run, and without a version
 * "already installed" silently means "will never be updated" — so every action added later
 * would be dead code on every site that already has this.
 */
function claude_cowork_version(): ?string
{
    $data = get_file_data(__FILE__, ['Version' => 'Version'], 'plugin');
    $version = trim((string) ($data['Version'] ?? ''));

    return $version === '' ? null : $version;
}

/**
 * Reads through the connection WordPress already opened. The engine never receives database
 * credentials, and no action can point it at another server.
 *
 * Only mysqli is wired. A site running a drop-in that replaces `$wpdb` (SQLite, for one) leaves
 * `db.*` answering 'unavailable' rather than half-working, which is the honest result — and the
 * files half still works, so such a site can still be sized and packed.
 */
function claude_cowork_build_dumper(): ?DbDumper
{
    global $wpdb;

    $connection = $wpdb->dbh ?? null;

    return $connection instanceof mysqli ? new DbDumper(new MysqliRowSource($connection)) : null;
}

/** An unreadable webroot leaves `files.*` reporting 'unavailable', not fatal. */
function claude_cowork_build_walker(): ?FileWalker
{
    try {
        return new FileWalker(untrailingslashit(ABSPATH));
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * What this site is made of, in the words a person who runs it would use.
 *
 * Four aggregate queries rather than WordPress's own counting helpers, on purpose. The helpers
 * changed signature across versions (`wp_count_terms` most notably), and this plugin is meant to
 * reach back through generations of WordPress — a query against the schema is the part that has
 * not moved since 2003.
 *
 * What is deliberately NOT counted: revisions, auto-drafts and anything in the trash. They are
 * real rows and they are not what somebody means by "how many pages does my site have". Media is
 * counted separately rather than folded into posts, because that is how it appears in the admin.
 *
 * Every figure is optional in the reply. A count that could not be taken is left out rather than
 * reported as zero, which reads as an empty site.
 */
function claude_cowork_counts(): array
{
    global $wpdb;

    $counts = [];

    // `post_type` and `post_status` together, because a status decides whether a row is content
    // or bookkeeping. The index on (post_type, post_status, post_date, ID) covers this.
    $rows = $wpdb->get_results(
        "SELECT post_type, post_status, COUNT(*) AS n FROM {$wpdb->posts} GROUP BY post_type, post_status",
        ARRAY_A
    );
    if (is_array($rows)) {
        // Counted by what is EXCLUDED, not by a list of allowed statuses. Editorial-workflow
        // plugins invent their own statuses, and an allow-list quietly reports those posts as
        // not existing — the failure a site owner cannot see, because nothing says a number is
        // short. Trash and auto-draft are bookkeeping in any WordPress.
        $of = static function (array $rows, string $type, array $skip): int {
            $total = 0;
            foreach ($rows as $row) {
                if ($row['post_type'] === $type && !in_array($row['post_status'], $skip, true)) {
                    $total += (int) $row['n'];
                }
            }
            return $total;
        };
        $bookkeeping = ['trash', 'auto-draft'];
        // `inherit` belongs to revisions, which are versions of a post rather than posts.
        $counts['posts'] = $of($rows, 'post', array_merge($bookkeeping, ['inherit']));
        $counts['pages'] = $of($rows, 'page', array_merge($bookkeeping, ['inherit']));
        // Media is the exception: an attachment legitimately carries `inherit` when it hangs off
        // a post, and other statuses when it does not. Measured on a real install, 2026-08-10 —
        // assuming `inherit` alone reported a media library of zero.
        $counts['media'] = $of($rows, 'attachment', $bookkeeping);
    }

    $rows = $wpdb->get_results(
        "SELECT taxonomy, COUNT(*) AS n FROM {$wpdb->term_taxonomy} GROUP BY taxonomy",
        ARRAY_A
    );
    if (is_array($rows)) {
        // Zero, not absent. A site with no tags is a fact; a missing figure means the count
        // could not be taken, and the caller is entitled to tell those apart.
        $counts['categories'] = 0;
        $counts['tags'] = 0;
        foreach ($rows as $row) {
            if ($row['taxonomy'] === 'category') {
                $counts['categories'] = (int) $row['n'];
            } elseif ($row['taxonomy'] === 'post_tag') {
                $counts['tags'] = (int) $row['n'];
            }
        }
    }

    // Approved only. Spam and pending are a moderation queue, not the site's comments.
    $approved = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = '1'");
    if ($approved !== null) {
        $counts['comments'] = (int) $approved;
    }

    $users = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
    if ($users !== null) {
        $counts['users'] = (int) $users;
    }

    // The theme is the one fact about a WordPress site that a person recognises before any
    // number, and it costs nothing here.
    $theme = wp_get_theme();
    if ($theme->exists()) {
        $counts['theme'] = (string) $theme->get('Name');
    }
    $counts['plugins'] = count((array) get_option('active_plugins', []));

    return $counts;
}

/**
 * The single entry point: `POST /wp-admin/admin-ajax.php?action=claude_cowork`.
 *
 * Registered on the `nopriv` hook as well as the logged-in one: the caller is a program holding
 * a token, not a person holding a session, and requiring both would mean the token proves
 * nothing on its own.
 */
function claude_cowork_exec(): void
{
    // Required and armed before the engine, so a death while LOADING the engine is answered too.
    // That is not hypothetical: a lib file with a parse error on an old PHP would otherwise be
    // the one failure this guard exists for, and the one it slept through.
    require_once __DIR__ . '/lib/FatalGuard.php';
    FatalGuard::arm();

    claude_cowork_load_engine();

    // Read the raw body rather than `$_POST`: the request is JSON, and admin-ajax only parses
    // form encoding.
    $request = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($request)) {
        $request = [];
    }

    $token = trim((string) get_option(CLAUDE_COWORK_TOKEN_OPTION, ''));

    // One action the engine cannot answer, and should not learn how to.
    //
    // Counting what a WordPress site is made of means knowing that `wp_posts` holds posts,
    // pages, attachments, menu items and every revision of all of them, separated only by
    // `post_type`. Row-counting that table reports a site with eighty articles as having twelve
    // hundred. That is WordPress knowledge, and the engine is deliberately ignorant of every CMS
    // — teaching it would mean giving it arbitrary SQL, which widens what a stolen token can
    // read on somebody's production site for the sake of a progress screen.
    if (($request['action'] ?? '') === 'site.counts') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            Token::check($token === '' ? null : $token, $request['token'] ?? null)
                ? ['ok' => true, 'counts' => claude_cowork_counts()]
                : ['ok' => false, 'error' => 'unauthorized', 'message' => 'invalid or missing token']
        );
        wp_die('', '', ['response' => null]);
    }

    // The log table is created on activation, but a site upgraded from a version that had no
    // write side may never run that hook again — and an Apply whose log is missing is an Apply
    // that cannot be reverted. Cheap enough to confirm on the requests that can write.
    if (claude_cowork_is_write_action((string) ($request['action'] ?? ''))) {
        Claude_Cowork_Apply_Log::ensure_table();
    }

    $engine = new Engine(
        $token === '' ? null : $token,
        [
            'php'       => PHP_VERSION,
            'wordpress' => get_bloginfo('version'),
            'component' => claude_cowork_version(),
        ],
        claude_cowork_build_dumper(),
        claude_cowork_build_walker(),
        new CurlUploader(120),
        new Claude_Cowork_Packages(),
        new Claude_Cowork_Site_Writer(),
        new Claude_Cowork_Media_Writer(),
        new Claude_Cowork_Apply_Log(),
        new ChangeStamp(ABSPATH)
    );

    $answer = $engine->handle($request);

    // `wp_send_json` would work, but it runs the payload through WordPress's own filters and
    // adds no header this does not. Printing it keeps the answer exactly what the engine built.
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($answer);
    wp_die('', '', ['response' => null]);
}

add_action('wp_ajax_' . CLAUDE_COWORK_ACTION, 'claude_cowork_exec');
add_action('wp_ajax_nopriv_' . CLAUDE_COWORK_ACTION, 'claude_cowork_exec');

/** The actions that keep an undo log, and so need the table it lives in. */
function claude_cowork_is_write_action(string $action): bool
{
    foreach (['content.', 'media.', 'apply.'] as $prefix) {
        if (strpos($action, $prefix) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Give this site a token of its own, and create the undo log.
 *
 * The undo log is loaded by hand rather than through `claude_cowork_load_engine`: activation runs
 * in wp-admin with nothing of this plugin loaded, and the table's shape is the only thing needed
 * here.
 */
register_activation_hook(__FILE__, static function (): void {
    claude_cowork_seed_token();

    require_once __DIR__ . '/lib/SiteWriter.php';
    require_once __DIR__ . '/lib/class-claude-cowork-writers.php';
    Claude_Cowork_Apply_Log::ensure_table();
});

/**
 * Mints the token this plugin answers on, if it has none.
 *
 * The site generates its own rather than being handed one. That is what makes the two ways in
 * agree: an owner who installed this by hand copies the token off the settings screen below, and
 * Tracy — which installs the same package through the same admin screens — reads that same field.
 * One value, one place it comes from. It also means a package that was ever shared carries no
 * usable token, because the token does not exist until the plugin runs on a site.
 *
 * **Never overwrites an existing one.** An upgrade activates the plugin again, and re-minting
 * would cut off every caller already holding the old value. The Joomla component seeds its token
 * under the same rule and for the same reason (`seedToken`, com_claudecowork/script.php).
 *
 * 24 bytes as hex, matching Joomla: comfortably past the 16-character floor `Token::check`
 * enforces, and still short enough to copy by hand.
 */
function claude_cowork_seed_token(): void
{
    if (trim((string) get_option(CLAUDE_COWORK_TOKEN_OPTION, '')) !== '') {
        return;
    }

    update_option(CLAUDE_COWORK_TOKEN_OPTION, bin2hex(random_bytes(24)));
}

// ---- Telling a watching preview that the site changed ---------------------------------------

/**
 * Note that the site changed, for whoever is watching it.
 *
 * Loads only `ChangeStamp`, not the engine: this runs on ordinary admin requests — a customer
 * saving a post — and pulling in the dumper, the tar writer and the uploader to record one
 * timestamp would make every save slower for a feature the request itself does not use.
 */
function claude_cowork_note_change(string $reason): void
{
    require_once __DIR__ . '/lib/ChangeStamp.php';
    (new ChangeStamp(ABSPATH))->touch($reason);
}

/**
 * Which changes are worth telling a preview about.
 *
 * A deliberately short list. WordPress fires `updated_option` constantly — cron schedules,
 * transients, update checks — and hooking it would have a preview reloading every few seconds
 * on a site nobody is touching, which is worse than not reloading at all. These four are the
 * ones a person watching a preview would say "yes, that changed my site":
 *
 *   switch_theme        a different theme is live — the whole page looks different
 *   save_post           a page or post was published or edited
 *   activated_plugin    something new is running
 *   deactivated_plugin  something stopped running
 *
 * `save_post` fires for autosaves and revisions too, which are not changes anyone can see, so
 * those are filtered out rather than reloading a preview while its author is still typing.
 */
add_action('switch_theme', static function (): void {
    claude_cowork_note_change('theme');
});

add_action('save_post', static function ($post_id, $post = null, $update = null): void {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (function_exists('wp_is_post_revision') && wp_is_post_revision($post_id)) {
        return;
    }
    // A draft is not on the site yet: reloading a preview for one would show the reader nothing
    // new and interrupt whatever they were looking at.
    if ($post !== null && isset($post->post_status) && $post->post_status !== 'publish') {
        return;
    }
    claude_cowork_note_change('content');
}, 10, 3);

add_action('activated_plugin', static function (): void {
    claude_cowork_note_change('plugin');
});

add_action('deactivated_plugin', static function (): void {
    claude_cowork_note_change('plugin');
});

// ---- The one setting -----------------------------------------------------------------------

/**
 * The token, and nothing else.
 *
 * An empty token means the plugin is installed but off, and every request is refused — putting
 * it on a site does not, by itself, open anything. Clearing the field is how a site owner
 * revokes access without uninstalling.
 */
function claude_cowork_register_setting(): void
{
    register_setting('claude_cowork', CLAUDE_COWORK_TOKEN_OPTION, [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
        // Never over the settings REST endpoint: this value is the key to the site's contents,
        // and a setting that can be read remotely is one more place it can leak from.
        'show_in_rest'      => false,
    ]);
}

add_action('admin_init', 'claude_cowork_register_setting');

function claude_cowork_add_settings_page(): void
{
    add_options_page(
        'Tracy Claude Cowork',
        'Claude Cowork',
        'manage_options',
        'claude-cowork',
        'claude_cowork_render_settings_page'
    );
}

add_action('admin_menu', 'claude_cowork_add_settings_page');

function claude_cowork_render_settings_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $token = (string) get_option(CLAUDE_COWORK_TOKEN_OPTION, '');
    ?>
    <div class="wrap">
        <h1>Tracy Claude Cowork</h1>
        <p>This site answers at
            <code><?php echo esc_html(admin_url('admin-ajax.php?action=' . CLAUDE_COWORK_ACTION)); ?></code>
            for a caller holding the token below. Clear the field to switch the endpoint off.</p>
        <p>Work on your site happens on a private copy. Changes reach this site only when you
            approve them, and every one is recorded so it can be put back.</p>
        <form method="post" action="options.php">
            <?php settings_fields('claude_cowork'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="claude_cowork_token">Token</label></th>
                    <td>
                        <input type="text" class="regular-text code" id="claude_cowork_token"
                               name="<?php echo esc_attr(CLAUDE_COWORK_TOKEN_OPTION); ?>"
                               value="<?php echo esc_attr($token); ?>" autocomplete="off"
                               onclick="this.select()">
                        <button type="button" class="button" id="claude-cowork-copy-token">
                            <span class="claude-cowork-copy-label">Copy</span>
                        </button>
                        <p class="description">This site made this token when the plugin was
                            activated, and keeps it across upgrades. Paste it into Tracy when this
                            server cannot be reached from outside. At least 16 characters: a
                            shorter one is treated as no token at all.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <script>
        // No build step and no dependency for one button. The copy affordance is what makes the
        // paste-by-hand route bearable, and it is the same one the Joomla connect screen offers.
        document.getElementById('claude-cowork-copy-token').addEventListener('click', function () {
            var field = document.getElementById('claude_cowork_token');
            field.select();
            navigator.clipboard.writeText(field.value).then(function () {
                var label = document.querySelector('#claude-cowork-copy-token .claude-cowork-copy-label');
                label.textContent = 'Copied';
                setTimeout(function () { label.textContent = 'Copy'; }, 1500);
            });
        });
    </script>
    <?php
}
