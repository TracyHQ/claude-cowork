<?php

/**
 * Plugin Name: Tracy Claude Cowork
 * Plugin URI:  https://github.com/TracyHQ/claude-cowork
 * Description: Lets an AI assistant read this site over one token-authenticated endpoint: its database, its files and what it has installed. This plugin only reads. Your work happens on a private copy, and nothing reaches this site unless you approve it.
 * Version:     0.1.5
 * Author:      Tracy
 * License:     GPL-2.0-or-later
 * Text Domain: claude-cowork
 */

defined('ABSPATH') || exit;

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

    foreach (['SqlValue', 'RowSource', 'DbDumper', 'FileWalker', 'TarStream', 'Uploader', 'Token', 'Engine', 'MysqliRowSource'] as $class) {
        require_once $lib . '/' . $class . '.php';
    }
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

    $engine = new Engine(
        $token === '' ? null : $token,
        [
            'php'       => PHP_VERSION,
            'wordpress' => get_bloginfo('version'),
            'component' => claude_cowork_version(),
        ],
        claude_cowork_build_dumper(),
        claude_cowork_build_walker(),
        new CurlUploader(120)
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
        <p>The endpoint only reads. Work on your site happens on a private copy, and reaching this
            site is a separate step you approve.</p>
        <form method="post" action="options.php">
            <?php settings_fields('claude_cowork'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="claude_cowork_token">Token</label></th>
                    <td>
                        <input type="text" class="regular-text code" id="claude_cowork_token"
                               name="<?php echo esc_attr(CLAUDE_COWORK_TOKEN_OPTION); ?>"
                               value="<?php echo esc_attr($token); ?>" autocomplete="off">
                        <p class="description">At least 16 characters. A shorter one is treated as no token at all.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
