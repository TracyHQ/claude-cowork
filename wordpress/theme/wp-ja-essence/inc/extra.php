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
 * The queries the section patterns name, resolved at render time. A pattern cannot know a category's id (the
 * seeder creates it), so a section names its categories by slug (`wpJaEssenceCategory`, comma separated, with their
 * sub-categories as the source's "show child category articles"); "more reading" lists the other posts
 * (`wpJaEssenceOthers`). A tax query the owner sets in the editor wins over the slugs.
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
	return $query;
}
add_filter( 'query_loop_block_query_vars', 'wp_ja_essence_query_vars', 10, 2 );

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
 * The page heading: the seeder keeps a page's source heading (an article's title) in `tracy_page_heading` when it
 * differs from the menu word the page is titled with; the title block named `page.title` prints it.
 *
 * @param string $content The rendered post title block.
 * @param array  $block   The parsed block.
 * @return string
 */
function wp_ja_essence_page_heading( string $content, array $block ): string {
	if ( 'page.title' !== ( $block['attrs']['metadata']['name'] ?? '' ) || ! is_page() ) {
		return $content;
	}
	$heading = trim( (string) get_post_meta( get_queried_object_id(), 'tracy_page_heading', true ) );
	if ( '' === $heading ) {
		return $content;
	}
	$safe = esc_html( $heading );
	return (string) preg_replace_callback(
		'/^(\s*<h1\b[^>]*>).*(<\/h1>\s*)$/s',
		static fn( array $m ): string => $m[1] . $safe . $m[2],
		$content
	);
}
add_filter( 'render_block_core/post-title', 'wp_ja_essence_page_heading', 10, 2 );

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
