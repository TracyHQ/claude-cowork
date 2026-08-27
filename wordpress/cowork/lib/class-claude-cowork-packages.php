<?php
/**
 * Installing and activating plugins and themes, through WordPress's own machinery.
 *
 * WordPress has two of everything here and Joomla has one. A Joomla "extension" covers
 * components, modules, plugins and templates behind a single installer; WordPress keeps plugins
 * and themes apart, with their own upgraders, their own lists and their own idea of what
 * "active" means — a theme is switched to, a plugin is turned on and others stay on. This class
 * follows WordPress rather than flattening it into the Joomla shape, so the actions read the way
 * a WordPress developer expects and each one maps to one core call.
 *
 * Nothing here reimplements installation. `Plugin_Upgrader` and `Theme_Upgrader` unpack, check
 * the destination, move files and clear caches, and they are what wp-admin itself uses — code
 * that hand-rolled any of it would be code that has to be re-audited every WordPress release.
 *
 * @package Claude_Cowork
 */

defined('ABSPATH') || exit;

/**
 * What a package URL has to look like before this site will fetch it.
 *
 * Deliberately narrow: one https `.zip`. A caller holding the token can add something to a site
 * and cannot name a local path, so the installer can never be pointed at a file on disk and
 * talked into reading it back out.
 */
final class Claude_Cowork_Package_Url {

	/**
	 * @param string $url Candidate package URL.
	 * @return array{ok:bool, error?:string}
	 */
	public static function check( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return array( 'ok' => false, 'error' => 'not a URL' );
		}
		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return array( 'ok' => false, 'error' => 'https required' );
		}
		$path = isset( $parts['path'] ) ? strtolower( $parts['path'] ) : '';
		if ( '.zip' !== substr( $path, -4 ) ) {
			return array( 'ok' => false, 'error' => 'package URL must end in .zip' );
		}
		return array( 'ok' => true );
	}
}

/**
 * Plugins and themes: list what is here, install from a URL, turn one on.
 */
final class Claude_Cowork_Packages {

	/**
	 * Load the parts of wp-admin that do installation.
	 *
	 * These files are not loaded on a front-end request, which is what this plugin answers on.
	 * Required at call time rather than at plugin load so an ordinary page view never pays for
	 * them — the same reason the engine itself is loaded lazily.
	 */
	private function load_upgrader() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		// The AJAX skin is the quiet one: it collects errors instead of printing HTML into the
		// response, which is what every other skin does.
		require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
	}

	/**
	 * Every plugin the site has, and whether it is running.
	 *
	 * Keyed by plugin file (`akismet/akismet.php`) because that is the handle every other plugin
	 * call takes — a name would read better and could not be passed back to `activate`.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function list_plugins() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$list = array();
		foreach ( get_plugins() as $file => $data ) {
			$list[] = array(
				'file'    => $file,
				'name'    => isset( $data['Name'] ) ? $data['Name'] : '',
				'version' => isset( $data['Version'] ) ? $data['Version'] : '',
				'active'  => is_plugin_active( $file ),
			);
		}
		return $list;
	}

	/**
	 * What this install says is core (ADR 0070 addendum: the per-site core source).
	 *
	 * WordPress keeps no `locked` column; what it does keep is the theme's own header. A theme
	 * that ships with WordPress says so there — the WordPress.org author URI and a default-theme
	 * name — and a child theme named `twentyfive-child` says the opposite, which is exactly the
	 * case a name-prefix heuristic gets wrong. Computed here, at the source, so the caller gets
	 * a neutral `core` flag and never re-derives platform semantics.
	 *
	 * @return array<string,mixed>
	 */
	public function core_manifest() {
		$extensions = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$author_uri = untrailingslashit( strtolower( (string) $theme->get( 'AuthorURI' ) ) );
			$shipped    = in_array( $author_uri, array( 'https://wordpress.org', 'http://wordpress.org' ), true );
			$named_like = ( 1 === preg_match( '/^(twenty[a-z]+|classic|default)$/', $stylesheet ) );
			$extensions[] = array(
				'type'    => 'theme',
				'element' => $stylesheet,
				'core'    => $shipped && $named_like,
				'enabled' => ( get_option( 'stylesheet' ) === $stylesheet ),
				'version' => (string) $theme->get( 'Version' ),
			);
		}
		foreach ( $this->list_plugins() as $plugin ) {
			// Plugins live in the Add-ons zone structurally; they are listed so the manifest is
			// a full inventory, never so the gate treats one as core.
			$extensions[] = array(
				'type'    => 'plugin',
				'element' => isset( $plugin['file'] ) ? dirname( (string) $plugin['file'] ) : (string) ( $plugin['name'] ?? '' ),
				'core'    => false,
				'enabled' => (bool) ( $plugin['active'] ?? false ),
				'version' => (string) ( $plugin['version'] ?? '' ),
			);
		}
		return array(
			'platform'        => 'wordpress',
			'platformVersion' => (string) get_bloginfo( 'version' ),
			'extensions'      => $extensions,
		);
	}

	/**
	 * Every theme the site has, and which one is live.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function list_themes() {
		$active = get_option( 'stylesheet' );

		$list = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$list[] = array(
				'stylesheet' => $stylesheet,
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'active'     => ( $stylesheet === $active ),
			);
		}
		return $list;
	}

	/**
	 * Install a plugin from a URL. Does NOT activate it.
	 *
	 * Two steps rather than one because they fail differently and a caller usually wants to know
	 * which happened: a package can install fine and still refuse to activate (a PHP version it
	 * needs, a fatal on load), and a site left with an installed-but-off plugin is recoverable
	 * while one left half-activated is not.
	 *
	 * @param string $url https URL of a .zip.
	 * @return array{ok:bool, error?:string, file?:string, name?:string, version?:string}
	 */
	/**
	 * Ask the site to fetch and install the newest announced version of THIS plugin, now.
	 *
	 * WordPress finds updates on its own schedule: the manifest answer is remembered for six hours
	 * and the cron that acts on it runs twice a day. For a site nobody is watching that is exactly
	 * right. For the person who just cut a release and wants to see it on a Preview, half a day is
	 * not a wait, it is a different working day.
	 *
	 * So this is the same work WordPress would have done, brought forward: forget what was
	 * remembered, ask again, and take the answer if there is one. Nothing here decides WHICH version
	 * is right — `update.json` already said, and the update filter already checked it. This only
	 * removes the waiting.
	 *
	 * Upgrading the plugin whose code is currently executing is safe for the same reason pressing
	 * Update on the Plugins screen is: the files change, the process finishes on the code it already
	 * loaded, and the next request runs the new copy. What it must NOT do is report the version it
	 * has in memory — that is the old one by definition, which is why the answer is read back off
	 * disk after the upgrade.
	 *
	 * IT MUST ALSO SWITCH ITSELF BACK ON. `Plugin_Upgrader::upgrade()` deactivates the plugin it is
	 * about to replace and never reactivates it — on the Plugins screen the surrounding page does
	 * that, and there is no surrounding page here. Measured on vincent-test1.tracy.ai, 27/08/2026:
	 * the upgrade answered `{"ok":true,"before":"0.6.2","after":"0.6.3"}` and every request after it
	 * got `0` from admin-ajax, because the endpoint had been switched off by its own success. That
	 * is worse than the wait this action exists to remove: a site nobody can reach cannot be told to
	 * turn anything back on, and it took wp-cli on the host to recover.
	 *
	 * @return array{ok:bool, error?:string, before?:string, after?:string, updated?:bool}
	 */
	public function self_update() {
		$file = 'claude-cowork/claude-cowork.php';
		$this->load_upgrader();

		// Read BEFORE the upgrader touches anything, and restore only what was true: a plugin that
		// was already off must stay off, or this action becomes a way to switch on a plugin the
		// site had deliberately switched off.
		$was_active = is_plugin_active( $file );

		$before = $this->installed_version( $file );

		// Both caches, because they answer different questions: ours holds the manifest, WordPress's
		// holds the update set built from it. Clearing one and not the other asks a fresh question
		// and reads a stale answer.
		delete_site_transient( 'claude_cowork_update' );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$updates = get_site_transient( 'update_plugins' );
		if ( ! isset( $updates->response[ $file ] ) ) {
			// Nothing newer is announced. Not a failure: it is the answer most of the time.
			return array( 'ok' => true, 'updated' => false, 'before' => $before, 'after' => $before );
		}

		$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$done     = $upgrader->upgrade( $file );

		// Before every return below, including the failing ones: the upgrader deactivates first and
		// a refused package leaves the plugin off just as surely as a successful one does.
		$this->restore_active( $file, $was_active );

		if ( is_wp_error( $done ) ) {
			return array( 'ok' => false, 'error' => $done->get_error_message() );
		}
		if ( true !== $done ) {
			$errors = $upgrader->skin->get_errors();
			$why    = is_wp_error( $errors ) && $errors->has_errors() ? $errors->get_error_message() : 'upgrader refused the package';
			return array( 'ok' => false, 'error' => $why );
		}

		// Read from disk, not from what this process loaded — see the note above.
		wp_clean_plugins_cache();
		$after = $this->installed_version( $file );

		// An upgrade that reports success and leaves the old version is the failure this whole
		// action exists to end. Say so rather than answering ok.
		if ( $after === $before ) {
			return array( 'ok' => false, 'error' => "upgrade reported success but the version is still {$before}" );
		}

		return array( 'ok' => true, 'updated' => true, 'before' => $before, 'after' => $after );
	}

	/**
	 * Put the plugin back on if it was on. Never throws and never reports: the upgrade's own answer
	 * is what the caller asked for, and a reactivation that failed is visible in the next request
	 * either working or not.
	 *
	 * `activate_plugin` is given `$silent = true` so the activation hook does not run again — the
	 * plugin was already installed and seeded, and re-firing that hook on every upgrade is a second
	 * behaviour nobody asked for.
	 */
	private function restore_active( $file, $was_active ) {
		if ( ! $was_active ) {
			return;
		}
		wp_clean_plugins_cache();
		if ( ! is_plugin_active( $file ) ) {
			activate_plugin( $file, '', false, true );
		}
	}

	/** The version on disk right now, read fresh. '' when the file is not there. */
	private function installed_version( $file ) {
		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( ! file_exists( $path ) ) {
			return '';
		}
		$data = get_plugin_data( $path, false, false );
		return isset( $data['Version'] ) ? (string) $data['Version'] : '';
	}

	public function install_plugin( $url ) {
		$shape = Claude_Cowork_Package_Url::check( $url );
		if ( true !== $shape['ok'] ) {
			return array( 'ok' => false, 'error' => $shape['error'] );
		}

		$this->load_upgrader();

		$before   = array_keys( get_plugins() );
		$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
		// `overwrite_package` is what makes this able to update, and not only to add. Without it
		// WordPress refuses any package whose folder already exists — `install()` runs with
		// `clear_destination => false` by default — so installing a newer build of something the
		// site already has answered "Destination folder already exists." and nothing else.
		// Measured on vincent-test1.tracy.ai, 27/08/2026: Tracy Desk's own Update button sends
		// `plugin.install` with the newest release URL, and it could never once succeed.
		//
		// This is the `wp plugin install --force` behaviour, and unlike `upgrade()` it does NOT
		// deactivate what it replaces — which is why the update path belongs here rather than
		// behind the upgrader that has to be talked back into switching the plugin on again.
		$done = $upgrader->install( $url, array( 'overwrite_package' => true ) );

		if ( is_wp_error( $done ) ) {
			return array( 'ok' => false, 'error' => $done->get_error_message() );
		}
		if ( true !== $done ) {
			// `install()` returns false or null when the skin collected an error; the skin holds
			// the reason, and it is a far better message than "installer refused".
			$errors = $upgrader->skin->get_errors();
			$why    = is_wp_error( $errors ) && $errors->has_errors() ? $errors->get_error_message() : 'installer refused the package';
			return array( 'ok' => false, 'error' => $why );
		}

		// Which plugin arrived is answered by diffing the list, not by trusting the archive's
		// folder name: a zip may unpack to a directory that matches nothing a caller guessed.
		//
		// An UPDATE adds nothing to that list, so the diff is empty and the answer would carry no
		// version at all — which is the one field the caller is checking to see whether the update
		// landed. `plugin_info()` reads the folder the package actually unpacked into, so it names
		// the right plugin in both cases; the diff stays first because it is the stronger evidence
		// when there is any.
		wp_clean_plugins_cache();
		$added = array_values( array_diff( array_keys( get_plugins() ), $before ) );
		$file  = isset( $added[0] ) ? $added[0] : (string) $upgrader->plugin_info();
		$data  = '' !== $file && file_exists( WP_PLUGIN_DIR . '/' . $file )
			? get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false )
			: array();

		return array(
			'ok'      => true,
			'file'    => $file,
			'name'    => isset( $data['Name'] ) ? $data['Name'] : '',
			'version' => isset( $data['Version'] ) ? $data['Version'] : '',
		);
	}

	/**
	 * Install a theme from a URL. Does NOT switch to it — see `activate_theme`.
	 *
	 * @param string $url https URL of a .zip.
	 * @return array{ok:bool, error?:string, stylesheet?:string, name?:string, version?:string}
	 */
	public function install_theme( $url ) {
		$shape = Claude_Cowork_Package_Url::check( $url );
		if ( true !== $shape['ok'] ) {
			return array( 'ok' => false, 'error' => $shape['error'] );
		}

		$this->load_upgrader();

		$upgrader = new Theme_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$done     = $upgrader->install( $url );

		if ( is_wp_error( $done ) ) {
			return array( 'ok' => false, 'error' => $done->get_error_message() );
		}
		if ( true !== $done ) {
			$errors = $upgrader->skin->get_errors();
			$why    = is_wp_error( $errors ) && $errors->has_errors() ? $errors->get_error_message() : 'installer refused the package';
			return array( 'ok' => false, 'error' => $why );
		}

		// The theme upgrader knows its own stylesheet; no diffing needed.
		$stylesheet = $upgrader->theme_info() ? $upgrader->theme_info()->get_stylesheet() : '';
		$theme      = '' !== $stylesheet ? wp_get_theme( $stylesheet ) : null;

		return array(
			'ok'         => true,
			'stylesheet' => $stylesheet,
			'name'       => $theme ? $theme->get( 'Name' ) : '',
			'version'    => $theme ? $theme->get( 'Version' ) : '',
		);
	}

	/**
	 * Turn a plugin on.
	 *
	 * `activate_plugin` runs the plugin's own activation hooks, which is the difference between
	 * a plugin that is on and one whose tables were never created.
	 *
	 * @param string $file Plugin file, e.g. `akismet/akismet.php`.
	 * @return array{ok:bool, error?:string, was_active?:bool}
	 */
	public function activate_plugin_file( $file ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( ! array_key_exists( $file, get_plugins() ) ) {
			return array( 'ok' => false, 'error' => "no such plugin: {$file}" );
		}

		// Read it BEFORE activating, the way activate_theme reads the live stylesheet: activating
		// an already-active plugin is a no-op, and once the call returns there is nothing left to
		// tell the two cases apart. A caller that undoes by deactivating would otherwise switch
		// off a plugin the site was legitimately running.
		//
		// The list read here is the SITE-LOCAL one, which is exactly what `activate_plugin()`
		// writes to when called without `$network_wide`. `is_plugin_active()` is the wrong probe:
		// it also answers true for a network-wide activation, and on multisite a plugin can be
		// network-active while this site has no local entry — the call would then still add one,
		// and reporting "was already on" would describe a state change as a no-op.
		$was_active = in_array( $file, (array) get_option( 'active_plugins', array() ), true );

		$result = activate_plugin( $file );
		if ( is_wp_error( $result ) ) {
			return array( 'ok' => false, 'error' => $result->get_error_message() );
		}
		return array( 'ok' => true, 'was_active' => $was_active );
	}

	/**
	 * Make a theme the live one.
	 *
	 * Through `switch_theme` rather than by writing the `stylesheet` option, so the site ends up
	 * in the state a person clicking Activate would leave it in — widgets remapped, the hooks a
	 * theme relies on having run.
	 *
	 * @param string $stylesheet Theme directory, e.g. `twentytwentytwo`.
	 * @return array{ok:bool, error?:string, previous?:string}
	 */
	public function activate_theme( $stylesheet ) {
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return array( 'ok' => false, 'error' => "no such theme: {$stylesheet}" );
		}
		// A broken theme takes the front end down and there is no admin left to undo it from.
		$errors = $theme->errors();
		if ( is_wp_error( $errors ) ) {
			return array( 'ok' => false, 'error' => $errors->get_error_message() );
		}

		$previous = get_option( 'stylesheet' );
		switch_theme( $theme->get_stylesheet() );

		return array( 'ok' => true, 'previous' => $previous );
	}
}
