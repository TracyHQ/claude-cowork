<?php
/**
 * Content pages (group B): the two listing routes whose page holds no listing of its own.
 *
 * The seeder writes the Featured Articles page empty and the Tagged Items page with every post, but the
 * source lists the featured (sticky) posts three to a row, and the posts tagged Morgan two to a row. The
 * theme answers those two routes with its own patterns (patterns/b-*.php), so the list stays in blocks.
 *
 * @package wp-ja-morgan
 */

/**
 * The pattern that stands in for the content of a listing route, or '' for any other page.
 *
 * @return string The registered pattern slug.
 */
function wp_ja_morgan_b_route_pattern(): string {
	if ( ! is_page() ) {
		return '';
	}
	$map = array(
		'featured-articles' => 'wp-ja-morgan/b-featured-articles',
		'tagged-items'      => 'wp-ja-morgan/b-tagged-items',
		'list-of-all-tags'  => 'wp-ja-morgan/b-tag-list',
		'list-all-categories' => 'wp-ja-morgan/b-category-list',
	);
	$slug = (string) get_post_field( 'post_name', get_queried_object_id() );
	$path = trim( (string) wp_parse_url( get_permalink( get_queried_object_id() ), PHP_URL_PATH ), '/' );
	return ( isset( $map[ $slug ] ) && 0 === strpos( $path, 'joomlart-content/' ) ) ? $map[ $slug ] : '';
}

/**
 * Replaces the content of a listing route with its pattern.
 *
 * @param string $content The rendered post content block.
 * @return string
 */
function wp_ja_morgan_b_route_content( string $content ): string {
	$slug = wp_ja_morgan_b_route_pattern();
	if ( '' === $slug ) {
		return $content;
	}
	$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $slug );
	if ( ! $pattern ) {
		return $content;
	}
	return '<div class="wp-block-post-content t3-content item-page">' . do_blocks( $pattern['content'] ) . '</div>';
}
add_filter( 'render_block_core/post-content', 'wp_ja_morgan_b_route_content', 20 );

/**
 * The hits line of a featured card: the count the source shows ("Hits: 29"), from the post meta.
 *
 * @param string   $content The rendered paragraph.
 * @param array    $block   The parsed block.
 * @return string
 */
function wp_ja_morgan_b_hits( string $content, array $block ): string {
	if ( false === strpos( $content, '{jm-hits}' ) ) {
		return $content;
	}
	return str_replace( '{jm-hits}', (string) (int) get_post_meta( get_the_ID(), 'hits', true ), $content );
}
add_filter( 'render_block_core/paragraph', 'wp_ja_morgan_b_hits', 10, 2 );

/**
 * Straight quotes and dashes, as the source prints them. Joomla stores and prints article text
 * verbatim; WordPress would turn every quote into a curly one and every " - " into an en dash, so the
 * same sentence would read differently on the two sites.
 */
add_filter( 'run_wptexturize', '__return_false' );

/**
 * The Login module is assigned to every sidebar of the source except the one of the tag list.
 *
 * @param string $content The rendered group block.
 * @param array  $block   The parsed block.
 * @return string
 */
function wp_ja_morgan_b_login_module( string $content, array $block ): string {
	$class = (string) ( $block['attrs']['className'] ?? '' );
	if ( false === strpos( $class, 'jm-login' ) || ! is_page() ) {
		return $content;
	}
	return 'list-of-all-tags' === get_post_field( 'post_name', get_queried_object_id() ) ? '' : $content;
}
add_filter( 'render_block_core/group', 'wp_ja_morgan_b_login_module', 10, 2 );

/**
 * A text cut the way the source cuts a listing text: at most 150 characters, ended on a whole word,
 * with "..." after the paragraph when something was left out.
 *
 * @param string $text   Plain text.
 * @param int    $length The character limit.
 * @return array{0:string,1:bool} The text and whether it was cut.
 */
function wp_ja_morgan_b_truncate( string $text, int $length = 150 ): array {
	$text = trim( wp_strip_all_tags( $text ) );
	if ( mb_strlen( $text ) <= $length ) {
		return array( $text, false );
	}
	$cut   = mb_substr( $text, 0, $length - 3 );
	$space = mb_strrpos( $cut, ' ' );
	return array( rtrim( false === $space ? $cut : mb_substr( $cut, 0, $space ) ), true );
}

/**
 * The text of a Tagged Items card: the article's intro as the editor wrote it, cut like the source.
 *
 * @param string $content The rendered excerpt block.
 * @param array  $block   The parsed block.
 * @return string
 */
function wp_ja_morgan_b_tag_body( string $content, array $block ): string {
	if ( false === strpos( (string) ( $block['attrs']['className'] ?? '' ), 'tag-body' ) ) {
		return $content;
	}
	$excerpt = (string) get_post_field( 'post_excerpt', get_the_ID() );
	if ( '' === $excerpt ) {
		return $content;
	}
	list( $text, $cut ) = wp_ja_morgan_b_truncate( $excerpt );
	return '<div class="tag-body wp-block-post-excerpt"><p class="wp-block-post-excerpt__excerpt">' . esc_html( $text ) . '</p>' . ( $cut ? '...' : '' ) . '</div>';
}
add_filter( 'render_block_core/post-excerpt', 'wp_ja_morgan_b_tag_body', 10, 2 );

/**
 * The URL of a picture in the media library by file name, or '' when it was never imported.
 *
 * @param string $file File name, e.g. img-7-thumb.jpg.
 * @return string
 */
function wp_ja_morgan_b_media( string $file ): string {
	global $wpdb;
	$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND (meta_value = %s OR meta_value LIKE %s) LIMIT 1", $file, '%/' . $wpdb->esc_like( $file ) ) );
	return $id ? (string) wp_get_attachment_url( $id ) : '';
}

/**
 * What the source's tag list prints for each tag (Joomla tag picture, description cut to its first
 * words, hit counter), by tag slug and in the source's order.
 *
 * @return array<string,array{0:string,1:string,2:int}> Slug => picture file, caption, hits.
 */
function wp_ja_morgan_b_tag_cards(): array {
	return array(
		'business' => array( 'img-7-thumb.jpg', 'Doing business', 3 ),
		'finance'  => array( 'img-11-thumb.jpg', 'Gregor then', 2 ),
		'joomla'   => array( 'img-17-thumb.jpg', 'He felt a', 8 ),
		'joomlart' => array( 'img-8-thumb.jpg', 'I am so happy,', 8 ),
		'morgan'   => array( 'img-19-thumb.jpg', 'A wonderful', 218 ),
	);
}

/**
 * The picture the source prints for each category of the category list, by category slug.
 *
 * @return array<string,string> Slug => picture file.
 */
function wp_ja_morgan_b_category_pictures(): array {
	return array(
		'investment-managment'          => 'img-2.jpg',
		'business-growth-advice'        => 'img-10.jpg',
		'tax-advice-and-management'     => 'img-13.jpg',
		'business-insights'             => 'img-19.jpg',
		'investment-and-superannuation' => 'img-11.jpg',
		'retirement-planning'           => 'img-5.jpg',
		'tax-planning'                  => 'img-8.jpg',
	);
}
