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
	public function install_plugin( $url ) {
		$shape = Claude_Cowork_Package_Url::check( $url );
		if ( true !== $shape['ok'] ) {
			return array( 'ok' => false, 'error' => $shape['error'] );
		}

		$this->load_upgrader();

		$before   = array_keys( get_plugins() );
		$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$done     = $upgrader->install( $url );

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
		wp_clean_plugins_cache();
		$added = array_values( array_diff( array_keys( get_plugins() ), $before ) );
		$file  = isset( $added[0] ) ? $added[0] : '';
		$data  = '' !== $file ? get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false ) : array();

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
	 * @return array{ok:bool, error?:string}
	 */
	public function activate_plugin_file( $file ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( ! array_key_exists( $file, get_plugins() ) ) {
			return array( 'ok' => false, 'error' => "no such plugin: {$file}" );
		}

		$result = activate_plugin( $file );
		if ( is_wp_error( $result ) ) {
			return array( 'ok' => false, 'error' => $result->get_error_message() );
		}
		return array( 'ok' => true );
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
