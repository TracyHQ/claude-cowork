<?php
/**
 * wp-ja-kinetic/post-topic-eyebrow — see block.json. Same "first tag by assignment order, not
 * alphabetical" rule already used by `related-posts` (`posts.contract.json` §tags.assignmentOrder):
 * `wp_get_object_terms(..., 'orderby' => 'term_order')`, index 0.
 *
 * @package wp-ja-kinetic
 * @var array<string, mixed> $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$wp_ja_kinetic_id = get_the_ID();
if ( ! $wp_ja_kinetic_id ) {
	return;
}
$wp_ja_kinetic_class = isset( $attributes['className'] ) && is_string( $attributes['className'] ) ? $attributes['className'] : 'hx-blogcat';

// `categoryOnly`: the author archive row's eyebrow is the category title alone, never a tag
// (`author_posts.php:27`: `$cat = $item->category_title`).
$wp_ja_kinetic_tags = empty( $attributes['categoryOnly'] ) ? wp_get_object_terms( $wp_ja_kinetic_id, 'post_tag', array( 'orderby' => 'term_order' ) ) : array();
if ( $wp_ja_kinetic_tags && ! is_wp_error( $wp_ja_kinetic_tags ) && isset( $wp_ja_kinetic_tags[0] ) ) {
	$wp_ja_kinetic_label = $wp_ja_kinetic_tags[0]->name;
} else {
	$wp_ja_kinetic_cats  = get_the_category( $wp_ja_kinetic_id );
	$wp_ja_kinetic_label = $wp_ja_kinetic_cats ? $wp_ja_kinetic_cats[0]->name : '';
}
if ( '' === $wp_ja_kinetic_label ) {
	return;
}
?>
<span class="<?php echo esc_attr( $wp_ja_kinetic_class ); ?>"><?php echo esc_html( $wp_ja_kinetic_label ); ?></span>
