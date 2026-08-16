<?php
/**
 * The WordPress half of the write side — how an Apply's changes actually land, and how they read
 * back so they can be undone.
 *
 * Everything here goes through WordPress's own functions rather than through `$wpdb`, and that is
 * the deliberate difference from the Joomla implementation this mirrors. Joomla writes rows
 * because its Table classes add little a raw UPDATE misses. WordPress is the other way round:
 * `wp_update_post` clears the post cache, files a revision, updates the modified date and fires
 * `save_post` — which is also what tells a watching preview the site moved. A row written behind
 * WordPress's back is a change a persistent object cache keeps hidden until something else
 * happens to flush it.
 *
 * @package Claude_Cowork
 */

defined( 'ABSPATH' ) || exit;

/**
 * Posts, post meta and options — the three things an Apply may edit on a WordPress site.
 */
final class Claude_Cowork_Site_Writer implements SiteWriter {

	/**
	 * The only post fields an Apply may set.
	 *
	 * Everything a caller might reasonably want to change about a page, and nothing that decides
	 * who owns it or who may see it: no `post_author`, no `ping_status`, no `post_password`. A
	 * field outside this list is refused rather than dropped — a caller whose change silently went
	 * nowhere reads the page afterwards and blames the cache.
	 *
	 * `post_type` is here because an insert has to say what it is creating; on an update it is
	 * ignored, since turning a page into a post is not an edit, it is a different object.
	 */
	private const POST_FIELDS = array(
		'post_title',
		'post_content',
		'post_excerpt',
		'post_status',
		'post_name',
		'post_parent',
		'menu_order',
		'post_type',
	);

	/**
	 * Options this endpoint will not write, whatever the caller says.
	 *
	 * Each one takes the site away from whoever would have to fix it: `siteurl` and `home` move
	 * the site to an address that may not answer, `active_plugins` can switch this plugin off,
	 * `template` and `stylesheet` change the theme behind `switch_theme`'s back, `user_roles`
	 * rewrites who can do anything, and `claude_cowork_token` is the key to the door being used.
	 * Break one of these and the revert cannot be reached either — which is why this is a refusal
	 * and not a warning.
	 */
	private const PROTECTED_OPTIONS = array(
		'siteurl',
		'home',
		'active_plugins',
		'template',
		'stylesheet',
		'user_roles',
		'claude_cowork_token',
		'claude_cowork_db_version',
	);

	/** Told apart from a real stored value, which may legitimately be null, false or ''. */
	private const ABSENT = "\0claude_cowork_absent";

	/** @var array<int,int> Posts touched this request, so purgeCache cleans those and not the world. */
	private $touched = array();

	public function read( string $kind, int $id, string $key = '' ): ?array {
		if ( 'post' === $kind ) {
			if ( $id <= 0 ) {
				return null;
			}
			$post = get_post( $id, ARRAY_A );
			return is_array( $post ) ? $post : null;
		}

		if ( 'postmeta' === $kind ) {
			if ( $id <= 0 || '' === $key ) {
				return null;
			}
			// `metadata_exists` rather than a truthiness check: a meta legitimately holding '' or
			// '0' exists, and treating it as absent would make its undo a delete.
			if ( ! metadata_exists( 'post', $id, $key ) ) {
				return null;
			}
			return array( 'value' => get_post_meta( $id, $key, true ) );
		}

		if ( 'option' === $kind ) {
			if ( '' === $key ) {
				return null;
			}
			$value = get_option( $key, self::ABSENT );
			return self::ABSENT === $value ? null : array( 'value' => $value );
		}

		throw new RuntimeException( "unknown kind: {$kind}" );
	}

	public function write( string $kind, int $id, array $fields, string $key = '' ): int {
		if ( 'post' === $kind ) {
			return $this->write_post( $id, $fields );
		}
		if ( 'postmeta' === $kind ) {
			return $this->write_postmeta( $id, $fields, $key );
		}
		if ( 'option' === $kind ) {
			return $this->write_option( $fields, $key );
		}
		throw new RuntimeException( "unknown kind: {$kind}" );
	}

	public function delete( string $kind, int $id, string $key = '' ): void {
		if ( 'post' === $kind ) {
			if ( $id > 0 ) {
				// Forced: this only ever reverses an insert this run made, and leaving it in the
				// trash would leave the site holding something the customer never approved.
				wp_delete_post( $id, true );
			}
			return;
		}
		if ( 'postmeta' === $kind ) {
			if ( $id > 0 && '' !== $key ) {
				delete_post_meta( $id, $key );
				$this->touched[ $id ] = $id;
			}
			return;
		}
		if ( 'option' === $kind ) {
			if ( '' !== $key && ! self::is_protected( $key ) ) {
				delete_option( $key );
			}
			return;
		}
		throw new RuntimeException( "unknown kind: {$kind}" );
	}

	public function purgeCache(): void {
		foreach ( $this->touched as $post_id ) {
			clean_post_cache( $post_id );
		}
		// Options are served out of one cached blob; a write updates it, but a delete on some
		// object-cache drop-ins does not.
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	private function write_post( int $id, array $fields ): int {
		$data = array();
		foreach ( $fields as $field => $value ) {
			if ( in_array( $field, self::POST_FIELDS, true ) ) {
				$data[ $field ] = $value;
			}
		}
		if ( array() === $data ) {
			// Naming both lists turns a silent no-op into one retry.
			throw new RuntimeException(
				'no writable field for kind post; given ' . implode( ', ', array_keys( $fields ) )
				. '; allowed ' . implode( ', ', self::POST_FIELDS )
			);
		}

		if ( $id > 0 ) {
			// An update that carried post_type would be re-typing an existing object, not editing
			// it. Dropped here so a before-state (which holds every column) restores cleanly.
			unset( $data['post_type'] );
			$data['ID'] = $id;
			// wp_slash because WordPress unslashes on the way in: content with a backslash in it
			// loses that backslash on every save that skips this.
			$written = wp_update_post( wp_slash( $data ), true );
		} else {
			if ( ! isset( $data['post_type'] ) || '' === $data['post_type'] ) {
				$data['post_type'] = 'post';
			}
			if ( ! isset( $data['post_status'] ) || '' === $data['post_status'] ) {
				// Not published by default: a new page appearing on a live site without anyone
				// saying so is the one outcome an Apply must not produce by omission.
				$data['post_status'] = 'draft';
			}
			$written = wp_insert_post( wp_slash( $data ), true );
		}

		if ( is_wp_error( $written ) ) {
			throw new RuntimeException( $written->get_error_message() );
		}
		$written = (int) $written;
		if ( $written <= 0 ) {
			throw new RuntimeException( 'WordPress refused the post without saying why' );
		}

		$this->touched[ $written ] = $written;
		return $written;
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	private function write_postmeta( int $id, array $fields, string $key ): int {
		if ( $id <= 0 ) {
			throw new RuntimeException( 'postmeta needs the id of the post it hangs off' );
		}
		if ( '' === $key ) {
			throw new RuntimeException( 'postmeta needs a key, e.g. _yoast_wpseo_metadesc' );
		}
		if ( ! array_key_exists( 'value', $fields ) ) {
			throw new RuntimeException( 'postmeta takes one field: value' );
		}
		if ( null === get_post( $id ) ) {
			throw new RuntimeException( "no such post: {$id}" );
		}

		// Keys beginning with an underscore are WordPress's "protected" meta — hidden from the
		// custom-fields box, and exactly where the SEO plugins keep the descriptions an Apply is
		// most often asked to fix. Refusing them would refuse the common case.
		//
		// The return value is deliberately not checked: update_post_meta answers false both when
		// nothing was stored and when the value it was handed is the one already there. Writing
		// the same description twice is not a failure.
		update_post_meta( $id, $key, wp_slash( $fields['value'] ) );

		$this->touched[ $id ] = $id;
		return $id;
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	private function write_option( array $fields, string $key ): int {
		if ( '' === $key ) {
			throw new RuntimeException( 'option needs a key, e.g. blogname' );
		}
		if ( self::is_protected( $key ) ) {
			throw new RuntimeException( "option not writable: {$key}" );
		}
		if ( ! array_key_exists( 'value', $fields ) ) {
			throw new RuntimeException( 'option takes one field: value' );
		}

		update_option( $key, $fields['value'] );
		return 0;
	}

	private static function is_protected( string $key ): bool {
		if ( in_array( $key, self::PROTECTED_OPTIONS, true ) ) {
			return true;
		}
		// Transients are a cache with an expiry twin; writing one by hand leaves the pair
		// inconsistent and the value is thrown away on its own schedule anyway.
		return 0 === strpos( $key, '_transient_' ) || 0 === strpos( $key, '_site_transient_' );
	}
}

/**
 * A file into `uploads/`, and into the Media Library with it.
 *
 * Putting bytes in the right folder is only half of adding media to WordPress: without an
 * attachment the file is reachable by URL and invisible everywhere a person would look for it.
 * Both halves happen here, so both halves can be undone.
 */
final class Claude_Cowork_Media_Writer implements MediaWriter {

	/** The prefix the engine speaks in, stripped before the path is resolved against the real basedir. */
	private const PREFIX = 'wp-content/uploads/';

	/** @var string */
	private $basedir;

	/**
	 * @param string|null $basedir Where uploads really live. Read from WordPress when not given.
	 */
	public function __construct( $basedir = null ) {
		if ( null === $basedir ) {
			$dirs    = wp_upload_dir();
			$basedir = isset( $dirs['basedir'] ) ? $dirs['basedir'] : ABSPATH . 'wp-content/uploads';
		}
		$this->basedir = rtrim( (string) $basedir, '/' );
	}

	public function read( string $path ): ?string {
		$abs = $this->confine( $path );
		if ( ! is_file( $abs ) ) {
			return null;
		}
		$bytes = file_get_contents( $abs );
		return false === $bytes ? null : $bytes;
	}

	public function write( string $path, string $bytes ): int {
		$abs = $this->confine( $path );
		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( "could not create {$dir}" );
		}
		if ( false === file_put_contents( $abs, $bytes ) ) {
			throw new RuntimeException( "could not write {$path}" );
		}

		$existing = $this->attachment_for( $this->relative( $path ) );
		if ( $existing > 0 ) {
			// The file was replaced under an attachment that already exists. Its dimensions and
			// its thumbnails describe the old bytes, so they are rebuilt rather than left lying.
			$this->regenerate( $existing, $abs );
			return 0;
		}

		return $this->attach( $abs );
	}

	public function delete( string $path ): void {
		$abs = $this->confine( $path );
		if ( is_file( $abs ) ) {
			unlink( $abs );
		}
	}

	public function deleteAttachment( int $attachmentId ): void {
		if ( $attachmentId > 0 ) {
			// true: take the files too. The thumbnails WordPress generated are the reason this
			// exists — deleting the original alone leaves orphans nobody will ever find.
			wp_delete_attachment( $attachmentId, true );
		}
	}

	/** Register a file with the Media Library and build its thumbnails. */
	private function attach( string $abs ): int {
		$name = basename( $abs );
		$type = wp_check_filetype( $name, null );

		$attachment = array(
			'post_mime_type' => empty( $type['type'] ) ? 'application/octet-stream' : $type['type'],
			'post_title'     => sanitize_file_name( pathinfo( $name, PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$id = wp_insert_attachment( $attachment, $abs );
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( $id->get_error_message() );
		}
		$id = (int) $id;
		if ( $id <= 0 ) {
			// The bytes are on disk and reachable by URL; only the library row is missing. Said
			// plainly rather than swallowed, because the undo can no longer clean up a row.
			throw new RuntimeException( 'file written but WordPress would not add it to the Media Library' );
		}

		$this->regenerate( $id, $abs );
		return $id;
	}

	private function regenerate( int $attachmentId, string $abs ): void {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$meta = wp_generate_attachment_metadata( $attachmentId, $abs );
		if ( is_array( $meta ) ) {
			wp_update_attachment_metadata( $attachmentId, $meta );
		}
	}

	/** The attachment whose file is this path, or 0. `_wp_attached_file` is how WordPress itself looks one up. */
	private function attachment_for( string $relative ): int {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
				$relative
			)
		);
		return null === $id ? 0 : (int) $id;
	}

	/**
	 * The path as WordPress stores it: relative to the uploads basedir, with the prefix the engine
	 * speaks in taken off.
	 *
	 * The engine's vocabulary is the webroot-relative path a person recognises from a URL. Where
	 * that folder actually is, is WordPress's business — a site with `UPLOADS` set, or with
	 * `wp-content` moved, has its uploads somewhere else entirely, and writing under ABSPATH by
	 * string arithmetic would put the file where nothing serves it.
	 */
	private function relative( string $path ): string {
		return 0 === strpos( $path, self::PREFIX ) ? substr( $path, strlen( self::PREFIX ) ) : $path;
	}

	/**
	 * Turn a validated path into an absolute one, and prove it stays under the uploads folder.
	 *
	 * Two checks, because either alone has a hole. The string check catches a path that walks out
	 * through folders that do not exist yet — realpath answers false for those, which is not the
	 * same as safe. The realpath check catches the one a string cannot see: a symlink inside
	 * uploads pointing somewhere else. The engine refuses `..` before this is ever reached; this is
	 * the writer refusing on its own behalf, so it stays safe if it is ever called from elsewhere.
	 */
	private function confine( string $path ): string {
		$relative = $this->relative( $path );
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '..' === $segment ) {
				throw new RuntimeException( 'media path escapes the uploads folder' );
			}
		}

		$abs    = $this->basedir . '/' . $relative;
		$parent = realpath( dirname( $abs ) );
		if ( false !== $parent && 0 !== strncmp( $parent . '/', $this->basedir . '/', strlen( $this->basedir ) + 1 ) ) {
			throw new RuntimeException( 'media path escapes the uploads folder' );
		}
		return $abs;
	}
}

/**
 * Where the before-state of every edit is kept, so an Apply can be undone to exactly what was
 * there.
 *
 * One row per step, ordered by a per-Apply sequence. The step is stored as
 * `base64(serialize($entry))`, not JSON: a media edit's before-state is the file's raw bytes, and
 * JSON cannot carry a byte that is not valid UTF-8, where PHP's own serialization carries any
 * string exactly. base64 keeps the result safe for a text column. On the way back the blob is
 * unserialized with classes forbidden, so a corrupted row can never instantiate anything.
 */
final class Claude_Cowork_Apply_Log implements ApplyLog {

	/** Raised whenever the table's shape changes, so an upgraded site rebuilds it. */
	const DB_VERSION = '1';

	const VERSION_OPTION = 'claude_cowork_db_version';

	/** @var wpdb */
	private $db;

	/** @var string */
	private $table;

	public function __construct( $db = null ) {
		global $wpdb;

		$this->db    = null === $db ? $wpdb : $db;
		$this->table = $this->db->prefix . 'claude_cowork_apply_log';
	}

	/**
	 * Create the table if it is not there, or if its shape has moved.
	 *
	 * Called both from the activation hook and before the first write of any request. The hook
	 * alone is not enough: a site upgraded from a version with no write side may never run it
	 * again, and an Apply whose log table is missing is an Apply that cannot be reverted — the one
	 * failure this whole design exists to prevent.
	 */
	public static function ensure_table(): void {
		global $wpdb;

		if ( get_option( self::VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'claude_cowork_apply_log';
		$collate = $wpdb->get_charset_collate();

		// dbDelta is particular: two spaces after PRIMARY KEY, one field per line, lowercase types.
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				apply_id varchar(190) NOT NULL,
				seq int(11) NOT NULL DEFAULT 0,
				entry longtext NOT NULL,
				created datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY apply_seq (apply_id, seq)
			) {$collate};"
		);

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
	}

	public function record( string $applyId, array $entry ): void {
		$done = $this->db->insert(
			$this->table,
			array(
				'apply_id' => $applyId,
				'seq'      => $this->next_seq( $applyId ),
				'entry'    => base64_encode( serialize( $entry ) ),
				'created'  => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s' )
		);

		if ( false === $done ) {
			throw new RuntimeException( 'could not record the undo for this step' );
		}
	}

	public function entries( string $applyId ): array {
		$rows = $this->db->get_col(
			$this->db->prepare(
				"SELECT entry FROM {$this->table} WHERE apply_id = %s ORDER BY seq ASC",
				$applyId
			)
		);

		$out = array();
		foreach ( (array) $rows as $blob ) {
			$decoded = base64_decode( (string) $blob, true );
			if ( false === $decoded ) {
				continue;
			}
			$entry = unserialize( $decoded, array( 'allowed_classes' => false ) );
			if ( is_array( $entry ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	public function clear( string $applyId ): void {
		$this->db->delete( $this->table, array( 'apply_id' => $applyId ), array( '%s' ) );
	}

	/** The next sequence number for an Apply, so steps replay in the order they happened. */
	private function next_seq( string $applyId ): int {
		$max = $this->db->get_var(
			$this->db->prepare(
				"SELECT COALESCE(MAX(seq), 0) FROM {$this->table} WHERE apply_id = %s",
				$applyId
			)
		);
		return (int) $max + 1;
	}
}
