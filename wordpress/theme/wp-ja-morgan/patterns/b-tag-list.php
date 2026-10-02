<?php
/**
 * Title: Tag list
 * Slug: wp-ja-morgan/b-tag-list
 * Description: The List of all tags page of JA Morgan: a filter bar, then every tag as a card with its picture, name, first words and hit count, three to a row.
 * Categories: wp-ja-morgan, tracy
 * Post Types: page
 * Inserter: no
 *
 * @package wp-ja-morgan
 */

$cards = function_exists( 'wp_ja_morgan_b_tag_cards' ) ? wp_ja_morgan_b_tag_cards() : array();
$rows  = array_chunk( $cards, 3, true );
?>
<!-- wp:html -->
<div class="tag-category">
<form class="tag-list-form" method="get" action="">
<fieldset class="filters btn-toolbar">
<div class="btn-group">
<label class="filter-search-lbl screen-reader-text" for="filter-search"><?php esc_html_e( 'Enter Part of Title', 'wp-ja-morgan' ); ?></label>
<input type="text" name="filter-search" id="filter-search" value="" class="inputbox" placeholder="<?php esc_attr_e( 'Enter Part of Title', 'wp-ja-morgan' ); ?>" />
<button type="submit" class="btn" title="<?php esc_attr_e( 'Search', 'wp-ja-morgan' ); ?>"><span class="fa fa-search"></span></button>
<button type="reset" class="btn" title="<?php esc_attr_e( 'Clear', 'wp-ja-morgan' ); ?>"><span class="fa fa-remove"></span></button>
</div>
<div class="btn-group pull-right">
<label for="limit" class="screen-reader-text"><?php esc_html_e( 'Display #', 'wp-ja-morgan' ); ?></label>
<select id="limit" name="limit" class="form-select">
<?php foreach ( array( 5, 10, 15, 20, 25, 30, 50, 100, 200, 500 ) as $n ) : ?>
<option value="<?php echo (int) $n; ?>"<?php selected( 20, $n ); ?>><?php echo (int) $n; ?></option>
<?php endforeach; ?>
<option value="0"><?php esc_html_e( 'All', 'wp-ja-morgan' ); ?></option>
</select>
</div>
</fieldset>
<?php foreach ( $rows as $row ) : ?>
<div class="cats-list row">
<?php
foreach ( $row as $slug => $card ) :
	$term = get_term_by( 'slug', $slug, 'post_tag' );
	// A tag no post carries has no term here (WordPress keeps none), so it points at the tagged-items listing like `morgan`.
	$link = ( 'morgan' === $slug || ! $term ) ? home_url( '/joomlart-content/tagged-items/' ) : get_term_link( $term );
	$name = $term ? $term->name : ucfirst( $slug );
	if ( 'joomlart' === $slug ) {
		$name = 'JoomlArt';
	}
	$img = wp_ja_morgan_b_media( $card[0] );
	?>
<div class="col-sm-4">
<div class="item">
<div class="tag-body"><div class="item-image"><?php echo $img ? '<img src="' . esc_url( $img ) . '" alt=""/>' : ''; ?></div></div>
<h3><a href="<?php echo esc_url( is_wp_error( $link ) ? '#' : $link ); ?>"><?php echo esc_html( $name ); ?></a></h3>
<div class="caption">
<div class="tag-body"><p><?php echo esc_html( $card[1] ); ?></p>...</div>
<span class="list-hits badge badge-info">Hits: <?php echo (int) $card[2]; ?></span>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
</form>
</div>
<!-- /wp:html -->
