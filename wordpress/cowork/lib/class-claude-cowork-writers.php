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

		if ( 'term' === $kind ) {
			// `key` is the taxonomy, `id` the term. A term is meaningless without knowing which
			// vocabulary it belongs to — the same slug is a category on one site and a menu on
			// the next.
			if ( '' === $key || $id <= 0 ) {
				return null;
			}
			$term = get_term( $id, $key );
			if ( ! $term || is_wp_error( $term ) ) {
				return null;
			}
			return array(
				'name'        => (string) $term->name,
				'slug'        => (string) $term->slug,
				'description' => (string) $term->description,
				'parent'      => (int) $term->parent,
			);
		}

		if ( 'menuItem' === $kind ) {
			if ( $id <= 0 ) {
				return null;
			}
			$item = get_post( $id, ARRAY_A );
			if ( ! is_array( $item ) || 'nav_menu_item' !== ( $item['post_type'] ?? '' ) ) {
				return null;
			}
			// The row alone does not describe a menu item: what it points AT lives in meta, and
			// an undo that restored the title while losing the destination would look like a
			// success and read as a broken link.
			return array(
				'title'     => (string) $item['post_title'],
				'position'  => (int) $item['menu_order'],
				'type'      => (string) get_post_meta( $id, '_menu_item_type', true ),
				'object'    => (string) get_post_meta( $id, '_menu_item_object', true ),
				'object_id' => (int) get_post_meta( $id, '_menu_item_object_id', true ),
				'url'       => (string) get_post_meta( $id, '_menu_item_url', true ),
				'parent'    => (int) get_post_meta( $id, '_menu_item_menu_item_parent', true ),
			);
		}

		if ( 'templatePart' === $kind ) {
			if ( '' === $key ) {
				return null;
			}
			$existing = $this->find_template_part( $key );
			// Null means the theme's own file is still in charge, which makes the undo of this
			// write a delete — and a delete puts the theme's file back, exactly where it was.
			if ( null === $existing ) {
				return null;
			}
			return array(
				'id'      => (int) $existing->ID,
				'title'   => (string) $existing->post_title,
				'content' => (string) $existing->post_content,
				'area'    => $this->template_part_area( (int) $existing->ID ),
			);
		}

		throw new RuntimeException( "unknown kind: {$kind}" );
	}

	/**
	 * One bounded page of posts, as the content mirror reads them (ADR 0071).
	 *
	 * A post is not one row: its categories and tags live in a taxonomy, its featured image is a
	 * meta key pointing at another post, its SEO title belongs to whichever plugin the site runs,
	 * and its address is whatever the permalink structure says. Assembling that here — once, in
	 * the place that has WordPress loaded — is the difference between a mirror an editor can use
	 * and a row dump they have to decode.
	 *
	 * Only `post` and `page` travel. Revisions, attachments and menu items are in wp_posts too,
	 * and a mirror that carried them would bury the twenty articles somebody edits under ten
	 * thousand rows nobody does.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_posts( int $offset, int $limit, bool $with_body ): array {
		$query = new \WP_Query(
			array(
				'post_type'           => array( 'post', 'page' ),
				'post_status'         => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'offset'              => $offset,
				'posts_per_page'      => $limit,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'suppress_filters'    => true,
			)
		);

		$out = array();
		foreach ( $query->posts as $post ) {
			$out[] = $this->describe_post( $post, $with_body );
		}
		return $out;
	}

	/**
	 * Everything about one post an editor's file should carry.
	 *
	 * Split by what an Apply can put back. Identity and content are writable; the address, the
	 * author's name and the dates are what the site decided, and the mirror reports them so a
	 * person can see where they are without pretending a file can change them.
	 *
	 * @return array<string,mixed>
	 */
	private function describe_post( \WP_Post $post, bool $with_body ): array {
		$row = array(
			'id'             => (int) $post->ID,
			'type'           => $post->post_type,
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'url'            => get_permalink( $post ),
			'parent'         => (int) $post->post_parent,
			'menu_order'     => (int) $post->menu_order,
			'comment_status' => $post->comment_status,
			'created'        => $post->post_date_gmt,
			'modified'       => $post->post_modified_gmt,
			'author'         => $this->describe_author( (int) $post->post_author ),
			'categories'     => $this->term_slugs( $post->ID, 'category' ),
			'tags'           => $this->term_slugs( $post->ID, 'post_tag' ),
			'featured_image' => $this->describe_featured_image( (int) $post->ID ),
			'template'       => (string) get_page_template_slug( $post ),
			'seo'            => $this->describe_seo( (int) $post->ID ),
			'in_menu'        => $this->is_in_a_menu( $post ),
			// The mirror's own checksum — sha256 over the content plus one NUL byte, the exact
			// bytes the desk's articleChecksum hashes (WordPress keeps the whole body in one
			// column, so the second half is empty). Present on every row, bodies or not: it is
			// what turns a summary page into a truthful delta inventory.
			'checksum'       => hash( 'sha256', $post->post_content . "\0" ),
		);
		if ( $with_body ) {
			$row['content'] = $post->post_content;
			$row['excerpt'] = $post->post_excerpt;
		}
		return $row;
	}

	/** The author as a person rather than a number — a file saying `author: 2` tells nobody anything. */
	private function describe_author( int $user_id ): array {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return false === $user
			? array( 'id' => $user_id, 'name' => '', 'slug' => '' )
			: array( 'id' => $user_id, 'name' => $user->display_name, 'slug' => $user->user_nicename );
	}

	/** @return string[] */
	private function term_slugs( int $post_id, string $taxonomy ): array {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		return array_values( array_map( static fn( $term ) => $term->slug, $terms ) );
	}

	/**
	 * The featured image, by id AND by address.
	 *
	 * The id is what an Apply writes back — it is the only stable handle. The URL is for the
	 * person reading the file, who cannot tell which picture `_thumbnail_id: 4417` means.
	 *
	 * @return array<string,mixed>|null
	 */
	private function describe_featured_image( int $post_id ): ?array {
		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb_id <= 0 ) {
			return null;
		}
		return array(
			'id'  => $thumb_id,
			'url' => (string) wp_get_attachment_url( $thumb_id ),
			'alt' => (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
		);
	}

	/**
	 * The SEO title and description, from whichever plugin the site actually runs.
	 *
	 * Read from the meta keys rather than through each plugin's API: a mirror must not require
	 * Yoast to be loaded to describe a site that uses Rank Math, and the keys are the stable part
	 * of both. Empty when the site runs neither, which is most sites.
	 *
	 * @return array<string,string>
	 */
	private function describe_seo( int $post_id ): array {
		$pairs = array(
			'title'       => array( '_yoast_wpseo_title', 'rank_math_title', '_aioseo_title' ),
			'description' => array( '_yoast_wpseo_metadesc', 'rank_math_description', '_aioseo_description' ),
		);
		$seo = array();
		foreach ( $pairs as $field => $keys ) {
			foreach ( $keys as $key ) {
				$value = (string) get_post_meta( $post_id, $key, true );
				if ( '' !== $value ) {
					$seo[ $field ] = $value;
					break;
				}
			}
		}
		return $seo;
	}

	/**
	 * Whether any published menu points at this post — the WordPress half of Joomla's Itemid
	 * question. A page nobody can navigate to still has a URL, and knowing which of the two you
	 * are looking at is the point.
	 */
	private function is_in_a_menu( \WP_Post $post ): bool {
		$menus = wp_get_nav_menus();
		if ( ! is_array( $menus ) ) {
			return false;
		}
		foreach ( $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				if ( (int) $item->object_id === (int) $post->ID && 'post_type' === $item->type ) {
					return true;
				}
				// A category a menu points at carries every post filed under it.
				if ( 'taxonomy' === $item->type && has_term( (int) $item->object_id, (string) $item->object, $post ) ) {
					return true;
				}
			}
		}
		return false;
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
		if ( 'templatePart' === $kind ) {
			return $this->write_template_part( $key, $fields );
		}
		if ( 'term' === $kind ) {
			return $this->write_term( $key, $id, $fields );
		}
		if ( 'menuItem' === $kind ) {
			return $this->write_menu_item( $key, $id, $fields );
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
		if ( 'templatePart' === $kind ) {
			// Deleting the override is what restores the theme's own file, so this is both the
			// undo of a create AND the way to hand a part back to the theme on purpose.
			$existing = '' === $key ? null : $this->find_template_part( $key );
			if ( null !== $existing ) {
				wp_delete_post( (int) $existing->ID, true );
			}
			return;
		}
		if ( 'term' === $kind ) {
			// Only ever the undo of a create this run made — `canTrash` refuses a term a person
			// asked to delete, because re-creating one mints a new id and orphans everything
			// filed under the old.
			if ( $id > 0 && '' !== $key ) {
				wp_delete_term( $id, $key );
			}
			return;
		}
		if ( 'menuItem' === $kind ) {
			if ( $id > 0 ) {
				wp_delete_post( $id, true );
			}
			return;
		}
		throw new RuntimeException( "unknown kind: {$kind}" );
	}

	/**
	 * A post can go to the trash; a template part gives its slot back to the theme.
	 *
	 * Options and meta cannot: neither has a trash to sit in, and a caller who wants one gone is
	 * writing an empty value rather than deleting a row. Saying so here means the engine refuses
	 * with a sentence instead of the writer throwing halfway through.
	 */
	public function canTrash( string $kind ): bool {
		// A term is missing on purpose. Deleting one and creating it again mints a NEW term id,
		// and every post filed under the old one quietly loses its category — an undo that
		// restores the name but not the relationships is worse than refusing.
		return in_array( $kind, array( 'post', 'templatePart', 'menuItem' ), true );
	}

	public function trash( string $kind, int $id ): void {
		if ( 'templatePart' === $kind ) {
			// A template part has no trash of its own: removing the override IS the delete, and
			// what comes back is the theme's own file. The revert re-writes the override.
			if ( $id > 0 ) {
				wp_delete_post( $id, true );
			}
			return;
		}
		if ( 'menuItem' === $kind ) {
			// Forced, not trashed: a menu entry in the trash still belongs to the menu and still
			// renders. Removing it IS the delete, and the before-state is what puts it back.
			if ( $id > 0 ) {
				wp_delete_post( $id, true );
			}
			return;
		}
		if ( 'post' !== $kind ) {
			throw new RuntimeException( "a {$kind} cannot be trashed" );
		}
		if ( $id <= 0 ) {
			throw new RuntimeException( 'trash needs an id' );
		}
		// WordPress's own trash, not a delete: it keeps the row, records the status it had, and
		// leaves the page recoverable from the admin screens long after this Apply's revert
		// window has closed. Forcing a delete here would make Tracy the only way back.
		if ( false === wp_trash_post( $id ) ) {
			throw new RuntimeException( "WordPress would not trash post {$id}" );
		}
		$this->touched[ $id ] = $id;
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
	/**
	 * Refuse a write whose content WordPress changed on the way in.
	 *
	 * `wp_insert_post()` and `wp_update_post()` run `post_content` through KSES whenever the
	 * caller lacks `unfiltered_html`, which deletes every tag outside the allow-list and reports
	 * nothing: the id comes back, the write looks clean, and the row holds less than was sent.
	 * On 27/08/2026 a 16,298-character header carrying a logo was stored as 3,822 characters with
	 * the logo gone, the site's own header emptied, and Apply answering ok — the caller had no way
	 * to know, so it told the customer the job was done.
	 *
	 * Reading the row back is the only way to see it from here, so that is what this does. It is
	 * a refusal rather than a warning because a half-written override renders, and a header that
	 * renders wrong is worse than one that was never touched.
	 *
	 * @param int    $id   The row WordPress just wrote.
	 * @param string $sent The content handed to it, unslashed.
	 * @throws RuntimeException When the stored content differs from what was sent.
	 */
	private function assert_content_survived( int $id, string $sent ): void {
		$row = get_post( $id, ARRAY_A );
		if ( ! is_array( $row ) || ! array_key_exists( 'post_content', $row ) ) {
			return;
		}
		$stored = (string) $row['post_content'];
		if ( $stored === $sent ) {
			return;
		}

		// Not every difference is a loss. WordPress normalises markup on the way in — a closing
		// slash, a space inside a tag, an entity spelled out — and content that comes back a
		// character LONGER has plainly not been stripped of anything. Refusing those would block
		// ordinary work while catching nothing, which is worse than the silence this replaced.
		//
		// What is worth refusing is content that came back with less in it: a tag gone, or a body
		// noticeably shorter than what was sent. KSES removes whole elements, so both show up loudly.
		$lost = array();
		$storedTags = self::tag_names( $stored );
		foreach ( array_unique( self::tag_names( $sent ) ) as $tag ) {
			if ( ! in_array( $tag, $storedTags, true ) ) {
				$lost[] = '<' . $tag . '>';
			}
		}

		$sentLength   = strlen( $sent );
		$storedLength = strlen( $stored );
		// Two percent, and never fewer than 16 characters: enough room for the normalising above,
		// far too little to hide a deleted element.
		$slack   = max( 16, (int) ( $sentLength * 0.02 ) );
		$shorter = $sentLength - $storedLength;

		if ( array() === $lost && $shorter <= $slack ) {
			return;
		}

		$named = array() === $lost
			? sprintf( 'no whole tag disappeared, but %d characters did', $shorter )
			: 'these did not survive: ' . implode( ', ', array_slice( $lost, 0, 8 ) );

		throw new RuntimeException(
			sprintf(
				'WordPress removed part of the content on the way in: %d characters were sent and %d were stored, and %s. '
				. 'This is what KSES does to markup it does not allow when the caller lacks unfiltered_html. '
				. 'An <img> pointing at an uploaded file survives where inline markup does not.',
				$sentLength,
				$storedLength,
				$named
			)
		);
	}

	/**
	 * Every tag name in a fragment, lower-cased, in the order they appear.
	 *
	 * @param string $html Markup to read.
	 * @return string[]
	 */
	private static function tag_names( string $html ): array {
		if ( ! preg_match_all( '/<\s*([a-zA-Z][a-zA-Z0-9:-]*)/', $html, $m ) ) {
			return array();
		}
		return array_map( 'strtolower', $m[1] );
	}

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

		if ( array_key_exists( 'post_content', $data ) ) {
			$this->assert_content_survived( $written, (string) $data['post_content'] );
		}

		$this->touched[ $written ] = $written;
		$this->write_beyond_the_row( $written, $fields );
		return $written;
	}

	/**
	 * The parts of a post that are not columns of its row.
	 *
	 * Categories and tags live in a taxonomy, the featured image is a meta key, the page template
	 * and the SEO fields are meta too. An Apply that wrote only the row would save an edit and
	 * silently drop the category the editor moved it to — worse than refusing, because it looks
	 * like it worked.
	 *
	 * Each is written only when the caller mentioned it: a file that says nothing about tags must
	 * not clear the post's tags.
	 *
	 * @param array<string,mixed> $fields
	 */
	private function write_beyond_the_row( int $post_id, array $fields ): void {
		if ( isset( $fields['categories'] ) && is_array( $fields['categories'] ) ) {
			// By slug, because that is what a person can read and edit in a file; ids belong to
			// the database. Unknown slugs are dropped rather than created — inventing taxonomy
			// terms from a typo is not something a content edit should be able to do.
			$this->set_terms_by_slug( $post_id, 'category', $fields['categories'] );
		}
		if ( isset( $fields['tags'] ) && is_array( $fields['tags'] ) ) {
			$this->set_terms_by_slug( $post_id, 'post_tag', $fields['tags'] );
		}
		if ( array_key_exists( 'featured_image_id', $fields ) ) {
			$thumb = (int) $fields['featured_image_id'];
			if ( $thumb > 0 ) {
				set_post_thumbnail( $post_id, $thumb );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}
		if ( array_key_exists( 'template', $fields ) ) {
			$template = (string) $fields['template'];
			if ( '' === $template ) {
				delete_post_meta( $post_id, '_wp_page_template' );
			} else {
				update_post_meta( $post_id, '_wp_page_template', $template );
			}
		}
		if ( isset( $fields['seo'] ) && is_array( $fields['seo'] ) ) {
			$this->write_seo( $post_id, $fields['seo'] );
		}
	}

	/**
	 * Create or rename one term: a category, a tag, or the menu a menu belongs to.
	 *
	 * Joomla edits a category as a row and a menu as another; WordPress keeps both in taxonomies,
	 * so one kind covers what Joomla needs two for — `key` names the vocabulary and the same code
	 * serves `category`, `post_tag` and `nav_menu` alike.
	 *
	 * Only the taxonomies a site actually registered are accepted. WordPress will happily create a
	 * term in a vocabulary nothing reads, and a caller who mistyped `categories` would be told it
	 * worked and see nothing anywhere.
	 *
	 * @param array<string,mixed> $fields
	 */
	private function write_term( string $taxonomy, int $id, array $fields ): int {
		if ( '' === $taxonomy ) {
			throw new RuntimeException( 'term needs a key: the taxonomy, e.g. "category" or "nav_menu"' );
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			throw new RuntimeException( "this site has no taxonomy called {$taxonomy}" );
		}

		$args = array();
		foreach ( array( 'slug', 'description', 'parent' ) as $field ) {
			if ( array_key_exists( $field, $fields ) ) {
				$args[ $field ] = 'parent' === $field ? (int) $fields[ $field ] : (string) $fields[ $field ];
			}
		}

		if ( $id > 0 ) {
			if ( array_key_exists( 'name', $fields ) ) {
				$args['name'] = (string) $fields['name'];
			}
			$result = wp_update_term( $id, $taxonomy, $args );
		} else {
			if ( ! isset( $fields['name'] ) || '' === $fields['name'] ) {
				throw new RuntimeException( 'a new term needs a name' );
			}
			$result = wp_insert_term( (string) $fields['name'], $taxonomy, $args );
		}

		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}
		$term_id = (int) ( is_array( $result ) ? ( $result['term_id'] ?? 0 ) : $result );
		if ( $term_id <= 0 ) {
			throw new RuntimeException( 'WordPress refused the term without saying why' );
		}
		return $term_id;
	}

	/**
	 * Create or edit one entry of a menu.
	 *
	 * `wp_update_nav_menu_item` rather than a post insert, and that is the whole reason this is a
	 * kind of its own: a menu entry is a `nav_menu_item` post whose destination lives in five meta
	 * keys, and a row written without them is an entry that renders as a link to nowhere. The
	 * function is WordPress's own and writes all of it.
	 *
	 * `key` is the menu — its slug, id or name, whatever the caller has. A menu entry outside a
	 * menu is not a thing.
	 *
	 * @param array<string,mixed> $fields
	 */
	private function write_menu_item( string $menu, int $id, array $fields ): int {
		if ( '' === $menu ) {
			throw new RuntimeException( 'menuItem needs a key: the menu it belongs to' );
		}
		$term = get_term_by( 'slug', $menu, 'nav_menu' );
		if ( ! $term ) {
			$term = get_term_by( 'name', $menu, 'nav_menu' );
		}
		if ( ! $term || is_wp_error( $term ) ) {
			throw new RuntimeException( "this site has no menu called {$menu}" );
		}

		// Named as WordPress names them, so a caller reading its own site's data can pass it
		// straight back. Anything not mentioned keeps what it had — an edit that only moves an
		// entry must not blank the link it points at.
		$existing = $id > 0 ? $this->read( 'menuItem', $id ) : array();
		$args     = array(
			'menu-item-title'     => (string) ( $fields['title'] ?? ( $existing['title'] ?? '' ) ),
			'menu-item-url'       => (string) ( $fields['url'] ?? ( $existing['url'] ?? '' ) ),
			'menu-item-object-id' => (int) ( $fields['object_id'] ?? ( $existing['object_id'] ?? 0 ) ),
			'menu-item-object'    => (string) ( $fields['object'] ?? ( $existing['object'] ?? '' ) ),
			'menu-item-type'      => (string) ( $fields['type'] ?? ( $existing['type'] ?? 'custom' ) ),
			'menu-item-parent-id' => (int) ( $fields['parent'] ?? ( $existing['parent'] ?? 0 ) ),
			'menu-item-position'  => (int) ( $fields['position'] ?? ( $existing['position'] ?? 0 ) ),
			// Published, not draft: a menu entry nobody can see is not an entry, and WordPress
			// defaults a bare insert to draft.
			'menu-item-status'    => 'publish',
		);

		$written = wp_update_nav_menu_item( (int) $term->term_id, $id, $args );
		if ( is_wp_error( $written ) ) {
			throw new RuntimeException( $written->get_error_message() );
		}
		$written = (int) $written;
		if ( $written <= 0 ) {
			throw new RuntimeException( 'WordPress refused the menu item without saying why' );
		}
		$this->touched[ $written ] = $written;
		return $written;
	}

	/**
	 * The override row for one template part of the ACTIVE theme, or null when the theme's own
	 * file is still in charge.
	 *
	 * Scoped to the active theme on purpose. A site that has switched themes keeps the old
	 * theme's overrides in the same table, and a lookup by slug alone would edit a header no
	 * visitor has seen for months.
	 */
	private function find_template_part( string $slug ): ?\WP_Post {
		$found = get_posts(
			array(
				'post_type'        => 'wp_template_part',
				'name'             => $slug,
				'post_status'      => array( 'publish', 'draft', 'auto-draft' ),
				'numberposts'      => 1,
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'tax_query'        => array(
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => get_stylesheet(),
					),
				),
			)
		);
		return isset( $found[0] ) && $found[0] instanceof \WP_Post ? $found[0] : null;
	}

	/** Which part of the layout this override belongs to, as the Site Editor files them. */
	private function template_part_area( int $post_id ): string {
		$terms = wp_get_object_terms( $post_id, 'wp_template_part_area', array( 'fields' => 'names' ) );
		return is_array( $terms ) && isset( $terms[0] ) ? (string) $terms[0] : 'uncategorized';
	}

	/**
	 * Write the override that changes what a block theme renders.
	 *
	 * Three things have to be true together, and WordPress says nothing when one is missing — the
	 * row simply never takes effect, which is the failure this method exists to make impossible:
	 *
	 * 1. `post_type` is `wp_template_part` and the slug is the part's name (`header`, `footer`)
	 * 2. it carries a `wp_theme` term naming the ACTIVE theme, which is how WordPress decides an
	 *    override belongs to the theme being rendered
	 * 3. it carries a `wp_template_part_area` term, which is how the Site Editor groups it
	 *
	 * Published, not drafted. The default for a new post here is `draft` — right for an article
	 * nobody approved, wrong for this: a drafted override is not applied, so the caller would be
	 * told the write succeeded and see no change, which is the worst answer available.
	 *
	 * @param array<string,mixed> $fields
	 */
	private function write_template_part( string $slug, array $fields ): int {
		if ( '' === $slug ) {
			throw new RuntimeException( 'templatePart needs a key: the part slug, e.g. "header"' );
		}
		if ( ! array_key_exists( 'content', $fields ) ) {
			throw new RuntimeException( 'templatePart needs a content field: the block markup to render' );
		}

		$theme    = get_stylesheet();
		$existing = $this->find_template_part( $slug );
		$area     = isset( $fields['area'] ) && '' !== $fields['area']
			? (string) $fields['area']
			: ( null !== $existing ? $this->template_part_area( (int) $existing->ID ) : 'uncategorized' );

		$data = array(
			'post_content' => (string) $fields['content'],
			'post_status'  => 'publish',
			'post_type'    => 'wp_template_part',
			'post_name'    => $slug,
			'post_title'   => isset( $fields['title'] ) && '' !== $fields['title'] ? (string) $fields['title'] : $slug,
		);

		if ( null !== $existing ) {
			$data['ID'] = (int) $existing->ID;
			unset( $data['post_type'] );
			$written = wp_update_post( wp_slash( $data ), true );
		} else {
			$written = wp_insert_post( wp_slash( $data ), true );
		}

		if ( is_wp_error( $written ) ) {
			throw new RuntimeException( $written->get_error_message() );
		}
		$written = (int) $written;
		if ( $written <= 0 ) {
			throw new RuntimeException( 'WordPress refused the template part without saying why' );
		}

		$this->assert_content_survived( $written, (string) $data['post_content'] );

		// Set every time, not only on create: a row whose theme term went missing renders nothing
		// and reports nothing, and re-asserting it costs one query.
		wp_set_object_terms( $written, $theme, 'wp_theme', false );
		wp_set_object_terms( $written, $area, 'wp_template_part_area', false );

		$this->touched[ $written ] = $written;
		return $written;
	}

	/**
	 * @param string[] $slugs
	 */
	private function set_terms_by_slug( int $post_id, string $taxonomy, array $slugs ): void {
		$ids = array();
		foreach ( $slugs as $slug ) {
			$term = get_term_by( 'slug', (string) $slug, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
			}
		}
		wp_set_post_terms( $post_id, $ids, $taxonomy, false );
	}

	/**
	 * SEO title and description, written to whichever plugin the site has.
	 *
	 * Only to keys that ALREADY exist on this site: writing Yoast's keys to a site running Rank
	 * Math leaves rows no plugin reads, and the editor's change appears to have vanished.
	 *
	 * @param array<string,mixed> $seo
	 */
	private function write_seo( int $post_id, array $seo ): void {
		$families = array(
			'_yoast_wpseo_' => array( 'title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc' ),
			'rank_math_'    => array( 'title' => 'rank_math_title', 'description' => 'rank_math_description' ),
			'_aioseo_'      => array( 'title' => '_aioseo_title', 'description' => '_aioseo_description' ),
		);
		foreach ( $families as $keys ) {
			$present = false;
			foreach ( $keys as $key ) {
				if ( '' !== (string) get_post_meta( $post_id, $key, true ) ) {
					$present = true;
					break;
				}
			}
			if ( ! $present ) {
				continue;
			}
			foreach ( $keys as $field => $key ) {
				if ( array_key_exists( $field, $seo ) ) {
					update_post_meta( $post_id, $key, (string) $seo[ $field ] );
				}
			}
			return;
		}
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
