<?php
/**
 * wp-ja-essence — what this target adds on top of the source theme: the JA Essence stylesheet, the dark
 * mode switch and the header drawer. Loaded by the generic hook at the end of functions.php (the source
 * theme has no inc/extra.php, so it is inert there). Functions carry the `wp_ja_essence_` prefix.
 *
 * @package wp-ja-essence
 */

defined( 'ABSPATH' ) || exit;

/** The assets; design pages (fixture, artifact) bring their own stylesheet and take none of these. */
function wp_ja_essence_enqueue_assets(): void {
	if ( in_array( tracy_page_kind(), array( 'fixture', 'artifact' ), true ) ) {
		return;
	}
	$version = wp_get_theme()->get( 'Version' );
	wp_enqueue_style( 'wp-ja-essence', get_theme_file_uri( 'assets/css/wp-ja-essence.css' ), array( 'tracy', 'tracy-layout', 'tracy-sections' ), $version );
	// One stylesheet per page group (home/landing, category/listing, detail/forms): each group owns its file.
	foreach ( array( 'home', 'category', 'detail' ) as $group ) {
		wp_enqueue_style( "wp-ja-essence-$group", get_theme_file_uri( "assets/css/je-$group.css" ), array( 'wp-ja-essence' ), $version );
	}
	// In the head, blocking, so a visitor who chose dark never sees a light frame.
	wp_enqueue_script( 'wp-ja-essence-dark', get_theme_file_uri( 'assets/js/wp-ja-essence-dark.js' ), array(), $version, array( 'in_footer' => false ) );
	wp_enqueue_script( 'wp-ja-essence', get_theme_file_uri( 'assets/js/wp-ja-essence.js' ), array(), $version, array( 'strategy' => 'defer' ) );
}
add_action( 'wp_enqueue_scripts', 'wp_ja_essence_enqueue_assets', 11 );

/** The pattern category the overlay's patterns file under. */
function wp_ja_essence_register(): void {
	register_block_pattern_category( 'wp-ja-essence', array( 'label' => __( 'JA Essence', 'wp-ja-essence' ) ) );
}
add_action( 'init', 'wp_ja_essence_register' );

/** The stylesheet in the editor too, so sections look the same there. */
function wp_ja_essence_editor_styles(): void {
	add_editor_style( 'assets/css/wp-ja-essence.css' );
}
add_action( 'after_setup_theme', 'wp_ja_essence_editor_styles', 11 );

/**
 * The header is two bars, as the source's is; the classes are restated after the source theme's filter so a
 * catalogue archetype can never float the header or recolour the hero copy (gotcha #7, #12).
 *
 * @param string[] $classes The body classes, with the source theme's already added.
 * @return string[]
 */
function wp_ja_essence_body_class( array $classes ): array {
	$navs      = array_map( static fn( string $nav ): string => 'tracy-nav-' . $nav, TRACY_NAVS );
	$heros     = array_map( static fn( string $hero ): string => 'tracy-hero-' . $hero, TRACY_HEROS );
	$classes   = array_values( array_diff( $classes, $navs, $heros ) );
	$classes[] = 'tracy-nav-top-left';
	$classes[] = 'tracy-hero-split';
	return $classes;
}
add_filter( 'body_class', 'wp_ja_essence_body_class', 11 );

/**
 * Categories that only mark a listing and are never drawn: `blog-normal` holds the ten "normal layout" articles
 * that /category/category-style-4 lists (the source's second copy of three categories). They keep the label of the
 * category they stand for in the `je_label` meta, and no chip, badge or count shows the marker itself.
 */
const WP_JA_ESSENCE_HIDDEN_CATEGORIES = array( 'blog-normal' );

/** The five article pages (Layout 1-3, Video, Gallery) are pages in WordPress and belong to a category like a post. */
function wp_ja_essence_page_categories(): void {
	register_taxonomy_for_object_type( 'category', 'page' );
}
add_action( 'init', 'wp_ja_essence_page_categories' );

/**
 * The term ids of the hidden categories.
 *
 * @return int[]
 */
function wp_ja_essence_hidden_term_ids(): array {
	$ids = array();
	foreach ( WP_JA_ESSENCE_HIDDEN_CATEGORIES as $slug ) {
		$term = get_term_by( 'slug', $slug, 'category' );
		if ( $term ) {
			$ids[] = (int) $term->term_id;
		}
	}
	return $ids;
}

/**
 * What a listing may show, as WP_Query arguments. The source keeps a few articles twice under different categories
 * (same title, a different Joomla article): the port keeps those copies too, flagged `je_copy_of` (the article they
 * copy), so every category still counts and lists what the source lists. A copy is listed only by the one category it
 * is filed under; every list that spans several categories or none (home, trending, latest, more reading, search, tags,
 * authors) leaves the copies out, so no article shows twice. `je_unlisted` marks a copy that repeats a title inside its
 * own category: it keeps its address and is listed nowhere.
 *
 * @param array $query     WP_Query arguments.
 * @param int[] $term_ids  The category term ids the query is limited to (empty: no category limit).
 * @param bool  $children  Whether the terms bring their sub-categories.
 * @return array
 */
function wp_ja_essence_listing_args( array $query, array $term_ids, bool $children ): array {
	$total = count( $term_ids );
	if ( $children ) {
		foreach ( $term_ids as $id ) {
			$kids = get_term_children( $id, 'category' );
			$total += is_array( $kids ) ? count( $kids ) : 0;
		}
	}
	$single = 1 === $total;
	if ( $term_ids && ( ! isset( $query['post_type'] ) || 'post' === $query['post_type'] ) ) {
		// An article page filed under the category is listed with the posts.
		$query['post_type'] = array( 'post', 'page' );
	}
	$meta   = isset( $query['meta_query'] ) && is_array( $query['meta_query'] ) ? $query['meta_query'] : array();
	$meta[] = array(
		'key'     => 'je_unlisted',
		'compare' => 'NOT EXISTS',
	);
	if ( ! $single ) {
		$meta[] = array(
			'key'     => 'je_copy_of',
			'compare' => 'NOT EXISTS',
		);
	}
	$query['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- two NOT EXISTS checks on indexed meta.
	return $query;
}

/**
 * The queries the section patterns name, resolved at render time. A pattern cannot know a category's id (the
 * seeder creates it), so a section names its categories by slug (`wpJaEssenceCategory`, comma separated, with their
 * sub-categories as the source's "show child category articles"); "more reading" lists the other posts
 * (`wpJaEssenceOthers`). A tax query the owner sets in the editor wins over the slugs. Every post query then goes
 * through wp_ja_essence_listing_args(): copies and unlisted articles stay out of the lists that span categories.
 *
 * @param array    $query The WP_Query arguments the block built.
 * @param WP_Block $block The post template block.
 * @return array
 */
function wp_ja_essence_query_vars( array $query, WP_Block $block ): array {
	$ctx = $block->context['query'] ?? array();
	if ( ! empty( $ctx['wpJaEssenceCategory'] ) && empty( $ctx['taxQuery'] ) ) {
		$ids = array();
		foreach ( explode( ',', (string) $ctx['wpJaEssenceCategory'] ) as $slug ) {
			$term = get_term_by( 'slug', sanitize_title( $slug ), 'category' );
			if ( $term ) {
				$ids[] = (int) $term->term_id;
			}
		}
		$query['tax_query'] = array(
			array(
				'taxonomy'         => 'category',
				'field'            => 'term_id',
				'terms'            => $ids ? $ids : array( 0 ),
				'include_children' => true,
			),
		);
	}
	if ( ! empty( $ctx['wpJaEssenceOthers'] ) ) {
		$current = get_queried_object_id();
		if ( $current ) {
			$query['post__not_in'] = array( $current );
		}
	}
	if ( isset( $query['post_type'] ) && 'post' !== $query['post_type'] ) {
		return $query;
	}
	$terms    = array();
	$children = false;
	foreach ( (array) ( $query['tax_query'] ?? array() ) as $clause ) {
		if ( is_array( $clause ) && 'category' === ( $clause['taxonomy'] ?? '' ) ) {
			$terms    = array_merge( $terms, array_map( 'intval', (array) ( $clause['terms'] ?? array() ) ) );
			$children = $children || ! isset( $clause['include_children'] ) || (bool) $clause['include_children'];
		}
	}
	return wp_ja_essence_listing_args( $query, array_values( array_filter( array_unique( $terms ) ) ), $children );
}
add_filter( 'query_loop_block_query_vars', 'wp_ja_essence_query_vars', 10, 2 );

/**
 * The main query of the public archives (category, tag, author, search, the posts page) follows the same rules as the
 * query loops: copies only under their own category, article pages with their category's posts.
 *
 * @param WP_Query $query The query about to run.
 */
function wp_ja_essence_main_query( WP_Query $query ): void {
	if ( is_admin() || ! $query->is_main_query() || ! ( $query->is_category() || $query->is_tag() || $query->is_author() || $query->is_search() || $query->is_home() ) ) {
		return;
	}
	$term_ids = array();
	if ( $query->is_category() ) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			$term_ids = array( (int) $term->term_id );
		}
	}
	$args = wp_ja_essence_listing_args( array( 'post_type' => $query->get( 'post_type' ) ? $query->get( 'post_type' ) : 'post' ), $term_ids, true );
	if ( $query->is_category() ) {
		$query->set( 'post_type', $args['post_type'] );
	}
	$meta = $query->get( 'meta_query' );
	$query->set( 'meta_query', array_merge( is_array( $meta ) ? $meta : array(), $args['meta_query'] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
}
add_action( 'pre_get_posts', 'wp_ja_essence_main_query' );

/**
 * The category chips (Categories block) leave a hidden category out.
 *
 * @param string $content The rendered block.
 * @return string
 */
function wp_ja_essence_categories_block( string $content ): string {
	foreach ( wp_ja_essence_hidden_term_ids() as $id ) {
		$content = (string) preg_replace( '#<li class="[^"]*\bcat-item-' . $id . '\b[^"]*">.*?</li>\s*#s', '', $content );
	}
	return $content;
}
add_filter( 'render_block_core/categories', 'wp_ja_essence_categories_block' );

/**
 * A card's category badge: the hidden categories never print; an article filed under one prints the label it
 * stands for (`je_label`, a link to that category) so a "normal layout" card still reads Health, Design or Fashion.
 *
 * @param string   $content The rendered block.
 * @param array    $block   The parsed block.
 * @param WP_Block $instance The block instance.
 * @return string
 */
function wp_ja_essence_post_terms( string $content, array $block, $instance ): string {
	if ( 'category' !== ( $block['attrs']['term'] ?? '' ) ) {
		return $content;
	}
	$hidden = wp_ja_essence_hidden_term_ids();
	if ( ! $hidden ) {
		return $content;
	}
	$post_id = isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : (int) get_the_ID();
	$terms   = get_the_terms( $post_id, 'category' );
	if ( ! is_array( $terms ) || ! array_filter( $terms, static fn( $t ) => in_array( (int) $t->term_id, $hidden, true ) ) ) {
		return $content;
	}
	$links = array();
	$label = trim( (string) get_post_meta( $post_id, 'je_label', true ) );
	$named = '' !== $label ? get_term_by( 'name', $label, 'category' ) : false;
	if ( $named instanceof WP_Term && ! in_array( (int) $named->term_id, $hidden, true ) ) {
		$links[] = '<a href="' . esc_url( (string) get_term_link( $named ) ) . '" rel="tag">' . esc_html( $named->name ) . '</a>';
	}
	foreach ( $terms as $term ) {
		if ( ! in_array( (int) $term->term_id, $hidden, true ) ) {
			$links[] = '<a href="' . esc_url( (string) get_term_link( $term ) ) . '" rel="tag">' . esc_html( $term->name ) . '</a>';
		}
	}
	if ( ! $links ) {
		return '';
	}
	$class = trim( 'taxonomy-category wp-block-post-terms ' . (string) ( $block['attrs']['className'] ?? '' ) );
	return '<div class="' . esc_attr( $class ) . '">' . implode( '<span class="wp-block-post-terms__separator">, </span>', $links ) . '</div>';
}
add_filter( 'render_block_core/post-terms', 'wp_ja_essence_post_terms', 10, 3 );

/**
 * The search page at the source's address. The source's search (com_finder) lives at
 * /pages/j-pages/smart-search and its menus link there; the seeder keeps that route's page as a draft
 * (patterns.map.json `views.search.servedAtPath`) and this answers the path with the theme's search view: `s`
 * (WordPress's form) or `q` (the source's) is the term, and an empty term shows the form and no results.
 */
const WP_JA_ESSENCE_SEARCH_PATHS = array( 'pages/j-pages/smart-search' );

/**
 * @param array $vars The parsed query variables.
 * @return array
 */
function wp_ja_essence_search_request( array $vars ): array {
	if ( is_admin() || empty( $vars['pagename'] ) || ! in_array( trim( (string) $vars['pagename'], '/' ), WP_JA_ESSENCE_SEARCH_PATHS, true ) ) {
		return $vars;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only search term.
	$term = isset( $_GET['s'] ) ? wp_unslash( $_GET['s'] ) : ( isset( $_GET['q'] ) ? wp_unslash( $_GET['q'] ) : '' );
	// phpcs:enable
	$term = sanitize_text_field( (string) $term );
	$out  = array( 's' => $term );
	if ( '' === $term ) {
		$out['post__in'] = array( 0 );
	}
	return $out;
}
add_filter( 'request', 'wp_ja_essence_search_request' );

/**
 * The source's article URL for the representative post stays alive: Joomla serves the article at
 * /category/category-style-3/<alias>, and the post is answered there too (no redirect to /<slug>/), so the one route
 * the single template is measured at is the same address on both sides.
 *
 * @param array $vars The parsed request query vars.
 * @return array
 */
function wp_ja_essence_article_url( array $vars ): array {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( '/category/category-style-3/vintage-inspired-martini-cocktail-glasses' === rtrim( $path, '/' ) ) {
		return array( 'name' => 'vintage-inspired-martini-and-cocktail-glasses' );
	}
	return $vars;
}
add_filter( 'request', 'wp_ja_essence_article_url' );

/**
 * No canonical redirect away from the article's source URL (see wp_ja_essence_article_url).
 *
 * @param string|false $redirect The canonical URL, or false.
 * @return string|false
 */
function wp_ja_essence_article_url_canonical( $redirect ) {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	return '/category/category-style-3/vintage-inspired-martini-cocktail-glasses' === rtrim( $path, '/' ) ? false : $redirect;
}
add_filter( 'redirect_canonical', 'wp_ja_essence_article_url_canonical' );

/**
 * The heading an article page prints: the seeder keeps a page's source heading (an article's title) in
 * `tracy_page_heading` when it differs from the menu word the page is titled with ("Gallery", "Layout 1").
 *
 * @param int|WP_Post $post The page or post.
 * @return string
 */
function wp_ja_essence_display_title( $post ): string {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	if ( 'page' === $post->post_type ) {
		$heading = trim( (string) get_post_meta( $post->ID, 'tracy_page_heading', true ) );
		if ( '' !== $heading ) {
			return $heading;
		}
	}
	return get_the_title( $post );
}

/**
 * The page heading on the page itself (the title block named `page.title`) and in every list that shows the article page
 * (the category lists, "Articles - Latest"): its source heading, not the menu word it is titled with.
 *
 * @param string   $content  The rendered post title block.
 * @param array    $block    The parsed block.
 * @param WP_Block $instance The block instance.
 * @return string
 */
function wp_ja_essence_page_heading( string $content, array $block, $instance = null ): string {
	$on_page = 'page.title' === ( $block['attrs']['metadata']['name'] ?? '' ) && is_page();
	$post_id = $on_page ? get_queried_object_id() : (int) ( $instance->context['postId'] ?? 0 );
	if ( ! $post_id || 'page' !== get_post_type( $post_id ) ) {
		return $content;
	}
	$heading = trim( (string) get_post_meta( $post_id, 'tracy_page_heading', true ) );
	if ( '' === $heading ) {
		return $content;
	}
	$safe = esc_html( $heading );
	return (string) preg_replace_callback(
		'/^(\s*<(h[1-6]|p)\b[^>]*>(?:\s*<a\b[^>]*>)?)(.*?)((?:<\/a>\s*)?<\/\2>\s*)$/s',
		static fn( array $m ): string => $m[1] . $safe . $m[4],
		$content
	);
}
add_filter( 'render_block_core/post-title', 'wp_ja_essence_page_heading', 10, 3 );

/**
 * A page's slug as a body class (`je-slug-<slug>`): the listing variants of the source (category styles, tagged items)
 * differ per page by type size and card layout, and the seeded pages carry no class of their own.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function wp_ja_essence_slug_class( array $classes ): array {
	if ( is_page() ) {
		$classes[] = 'je-slug-' . sanitize_html_class( (string) get_post_field( 'post_name', get_queried_object_id() ) );
	}
	return $classes;
}
add_filter( 'body_class', 'wp_ja_essence_slug_class' );

/**
 * The article footer's share links carry a token for the page address (the seeded content cannot know its own URL).
 *
 * @param string $content Rendered post content.
 * @return string
 */
function wp_ja_essence_share_urls( string $content ): string {
	if ( false === strpos( $content, '__JE_PERMALINK__' ) ) {
		return $content;
	}
	return str_replace( '__JE_PERMALINK__', rawurlencode( (string) get_permalink() ), $content );
}
add_filter( 'the_content', 'wp_ja_essence_share_urls', 20 );

/**
 * Account pages: a signed-out visitor who opens a profile page is sent to the login form, as the source does
 * (Joomla answers 303 to the login menu item). Priority 1 runs before redirect_canonical, so the answer is the
 * 303 at the source path itself and not a 301 to its trailing-slash form first. A signed-in visitor sees the page.
 */
add_action(
	'template_redirect',
	static function (): void {
		if ( is_user_logged_in() ) {
			return;
		}
		$request = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' );
		if ( ! in_array( $request, array( 'pages/user/user-profile', 'pages/user/edit-user-profile' ), true ) ) {
			return;
		}
		$login = get_page_by_path( 'pages/user/login-form' );
		if ( $login ) {
			wp_safe_redirect( get_permalink( $login ), 303 );
			exit;
		}
	},
	1
);

/**
 * `?theme=dark|light` decides by its FIRST value (assets/js/wp-ja-essence-dark.js). WordPress's canonical
 * redirect rebuilds the query from a parsed copy in which a repeated key keeps its LAST value, so
 * `?theme=dark&theme=light` would be sent to `?theme=light` before any script runs. Cancelled only when normalising the repeated key changes the
 * first value; any other redirect (host, scheme, port, path, fragment, other parameters) is left alone.
 *
 * @param string|false $redirect_url  The address WordPress wants to send the visitor to.
 * @param string       $requested_url The address that was asked for.
 * @return string|false
 */
function wp_ja_essence_keep_first_theme_value( $redirect_url, $requested_url ) {
	if ( ! is_string( $redirect_url ) || ! is_string( $requested_url ) ) {
		return $redirect_url;
	}
	$asked  = wp_parse_url( $requested_url );
	$wanted = wp_parse_url( $redirect_url );
	if ( ! is_array( $asked ) || ! is_array( $wanted ) ) {
		return $redirect_url;
	}
	$asked_query  = isset( $asked['query'] ) ? (string) $asked['query'] : '';
	$wanted_query = isset( $wanted['query'] ) ? (string) $wanted['query'] : '';
	unset( $asked['query'], $wanted['query'] );
	// Scheme, host, port, path or fragment differ: that redirect is not about `theme`.
	if ( $asked !== $wanted ) {
		return $redirect_url;
	}
	// Split like URLSearchParams: pairs in order, cut at the first "=", key and value decoded.
	$split = static function ( $query ) {
		$theme = array();
		$other = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$parts = explode( '=', $pair, 2 );
			if ( 'theme' === urldecode( $parts[0] ) ) {
				$theme[] = urldecode( isset( $parts[1] ) ? $parts[1] : '' );
			} else {
				$other[] = $pair;
			}
		}
		return array( $theme, $other );
	};
	list( $asked_theme, $asked_other )   = $split( $asked_query );
	list( $wanted_theme, $wanted_other ) = $split( $wanted_query );
	if ( count( $asked_theme ) < 2 || $asked_other !== $wanted_other ) {
		return $redirect_url;
	}
	$override = static function ( $values ) {
		return ( isset( $values[0] ) && in_array( $values[0], array( 'dark', 'light' ), true ) ) ? $values[0] : '';
	};
	if ( $override( $asked_theme ) === $override( $wanted_theme ) ) {
		return $redirect_url;
	}
	return false;
}
add_filter( 'redirect_canonical', 'wp_ja_essence_keep_first_theme_value', 20, 2 );

/**
 * Two listing views the source draws with its own components: the people of the site (Author listing) and the filterable
 * list of article titles (Tagged items). Core has no block for either, so the theme ships two dynamic blocks; the
 * patterns map names them as the body of those two views (`views.author`, `views.tag`), and an owner can move or reuse
 * them in the editor like any block.
 */
function wp_ja_essence_register_listing_blocks(): void {
	$common = array(
		'api_version' => 3,
		'category'    => 'widgets',
		'supports'    => array(
			'autoRegister' => true,
			'html'         => false,
		),
	);
	register_block_type(
		'wp-ja-essence/authors',
		array_merge(
			$common,
			array(
				'title'           => __( 'Author listing', 'wp-ja-essence' ),
				'description'     => __( 'The authors of the site: photo, name, job title and a short bio, six to a page.', 'wp-ja-essence' ),
				'icon'            => 'groups',
				'render_callback' => 'wp_ja_essence_render_authors',
			)
		)
	);
	register_block_type(
		'wp-ja-essence/tagged-list',
		array_merge(
			$common,
			array(
				'title'           => __( 'Title list', 'wp-ja-essence' ),
				'description'     => __( 'Every article by title, with a field that keeps the titles holding the typed part.', 'wp-ja-essence' ),
				'icon'            => 'list-view',
				'render_callback' => 'wp_ja_essence_render_tagged_list',
			)
		)
	);
}
add_action( 'init', 'wp_ja_essence_register_listing_blocks' );

/**
 * The pager of the two listing blocks: previous / numbers / next as plain links on a query argument, so it works
 * without script and in the page cache.
 *
 * @param string $arg     The query argument that carries the page number.
 * @param int    $current The page shown.
 * @param int    $pages   How many pages there are.
 * @return string
 */
function wp_ja_essence_listing_pager( string $arg, int $current, int $pages ): string {
	if ( $pages < 2 ) {
		return '';
	}
	$url  = static fn( int $n ): string => esc_url( add_query_arg( $arg, $n > 1 ? $n : false ) );
	$html = '<nav class="je-listpager" aria-label="' . esc_attr__( 'Pages', 'wp-ja-essence' ) . '"><ul>';
	if ( $current > 1 ) {
		$html .= '<li><a class="je-listpager__prev" rel="prev" href="' . $url( $current - 1 ) . '" aria-label="' . esc_attr__( 'Previous page', 'wp-ja-essence' ) . '">&lsaquo;</a></li>';
	}
	for ( $n = 1; $n <= $pages; $n++ ) {
		$html .= $n === $current
			? '<li><span class="je-listpager__current" aria-current="page">' . $n . '</span></li>'
			: '<li><a href="' . $url( $n ) . '">' . $n . '</a></li>';
	}
	if ( $current < $pages ) {
		$html .= '<li><a class="je-listpager__next" rel="next" href="' . $url( $current + 1 ) . '" aria-label="' . esc_attr__( 'Next page', 'wp-ja-essence' ) . '">&rsaquo;</a></li>';
	}
	/* translators: 1: the page shown, 2: how many pages there are */
	$html .= '</ul><p class="je-listpager__of">' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'wp-ja-essence' ), $current, $pages ) ) . '</p></nav>';
	return $html;
}

/**
 * The Author listing: the people the content came from (users the seeder made from the source's authors, in the
 * source's order), six to a page. A person without a photo or a job title simply has no photo or title line.
 *
 * @return string
 */
function wp_ja_essence_render_authors(): string {
	$users = get_users(
		array(
			'meta_key'     => 'tracy_source_author', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_compare' => 'EXISTS',
		)
	);
	// The source's own order: its numeric user id (`joomla-user-43`).
	usort(
		$users,
		static function ( WP_User $a, WP_User $b ): int {
			$order = static fn( WP_User $u ): int => (int) preg_replace( '/\D+/', '', (string) get_user_meta( $u->ID, 'tracy_source_author', true ) );
			return $order( $a ) <=> $order( $b );
		}
	);
	if ( ! $users ) {
		return '<p class="je-authors__empty">' . esc_html__( 'No authors yet.', 'wp-ja-essence' ) . '</p>';
	}
	$per    = 6;
	$pages  = (int) ceil( count( $users ) / $per );
	$page   = isset( $_GET['apage'] ) ? min( $pages, max( 1, absint( wp_unslash( $_GET['apage'] ) ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only page number.
	$html   = '<div class="je-authors"><ul class="je-authors__items">';
	foreach ( array_slice( $users, ( $page - 1 ) * $per, $per ) as $user ) {
		$name  = (string) $user->display_name;
		$photo = (int) get_user_meta( $user->ID, 'tracy_avatar', true );
		$title = trim( (string) get_user_meta( $user->ID, 'job_title', true ) );
		$bio   = wp_trim_words( (string) $user->description, 18, '..' );
		$link  = esc_url( get_author_posts_url( $user->ID ) );
		$html .= '<li class="je-author">';
		if ( $photo ) {
			$img = wp_get_attachment_image( $photo, 'medium', false, array( 'class' => 'je-author__photo', 'alt' => $name, 'loading' => 'lazy' ) );
			if ( $img ) {
				$html .= '<a class="je-author__picture" href="' . $link . '" tabindex="-1" aria-hidden="true">' . $img . '</a>';
			}
		}
		$html .= '<h3 class="je-author__name"><a href="' . $link . '">' . esc_html( $name ) . '</a></h3>';
		if ( '' !== $title ) {
			$html .= '<p class="je-author__job">' . esc_html( $title ) . '</p>';
		}
		if ( '' !== $bio ) {
			$html .= '<p class="je-author__bio">' . esc_html( $bio ) . '</p>';
		}
		$html .= '</li>';
	}
	return $html . '</ul>' . wp_ja_essence_listing_pager( 'apage', $page, $pages ) . '</div>';
}

/**
 * The Tagged items view: article titles A to Z, twenty to a page, and a field that keeps the titles holding what was typed
 * (`?tf=`). Copies of an article and unlisted articles stay out (wp_ja_essence_listing_args); an article page filed under a
 * category is listed under its heading. Sorting and filtering run on the title the visitor reads (an article page's heading,
 * not the menu word it is titled with), over a list of some forty rows.
 *
 * @return string
 */
function wp_ja_essence_render_tagged_list(): string {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter and page number.
	$filter = isset( $_GET['tf'] ) ? sanitize_text_field( wp_unslash( $_GET['tf'] ) ) : '';
	$paged  = isset( $_GET['tpage'] ) ? max( 1, absint( wp_unslash( $_GET['tpage'] ) ) ) : 1;
	// phpcs:enable
	$args = wp_ja_essence_listing_args(
		array(
			'post_type'           => array( 'post', 'page' ),
			'post_status'         => 'publish',
			'posts_per_page'      => -1,
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- only filed articles.
				array(
					'taxonomy' => 'category',
					'operator' => 'EXISTS',
				),
			),
		),
		array(),
		false
	);
	$rows = array();
	foreach ( ( new WP_Query( $args ) )->posts as $id ) {
		$rows[] = array(
			'id'    => (int) $id,
			'title' => html_entity_decode( wp_ja_essence_display_title( (int) $id ), ENT_QUOTES, 'UTF-8' ),
		);
	}
	if ( '' !== $filter ) {
		$rows = array_values( array_filter( $rows, static fn( array $row ): bool => false !== mb_stripos( $row['title'], $filter ) ) );
	}
	usort( $rows, static fn( array $a, array $b ): int => strnatcasecmp( $a['title'], $b['title'] ) );
	$pages = max( 1, (int) ceil( count( $rows ) / 20 ) );
	$paged = min( $paged, $pages );
	$clear = esc_url( remove_query_arg( array( 'tf', 'tpage' ) ) );
	$html  = '<div class="je-taglist"><form class="je-taglist__filter" role="search" method="get" action="' . $clear . '">';
	$html .= '<label class="screen-reader-text" for="je-taglist-filter">' . esc_html__( 'Filter by part of a title', 'wp-ja-essence' ) . '</label>';
	$html .= '<input id="je-taglist-filter" type="search" name="tf" value="' . esc_attr( $filter ) . '" placeholder="' . esc_attr__( 'Enter Part of Title', 'wp-ja-essence' ) . '" autocomplete="off">';
	$html .= '<button type="submit">' . esc_html__( 'Filter', 'wp-ja-essence' ) . '</button>';
	if ( '' !== $filter ) {
		$html .= '<a class="je-taglist__clear" href="' . $clear . '">' . esc_html__( 'Clear', 'wp-ja-essence' ) . '</a>';
	}
	$html .= '</form>';
	if ( ! $rows ) {
		return $html . '<p class="je-taglist__empty">' . esc_html__( 'No title holds that text.', 'wp-ja-essence' ) . '</p></div>';
	}
	$html .= '<ul class="je-taglist__items">';
	foreach ( array_slice( $rows, ( $paged - 1 ) * 20, 20 ) as $row ) {
		$html .= '<li><a href="' . esc_url( get_permalink( $row['id'] ) ) . '">' . esc_html( $row['title'] ) . '</a></li>';
	}
	return $html . '</ul>' . wp_ja_essence_listing_pager( 'tpage', $paged, $pages ) . '</div>';
}
