<?php
/**
 * Title: Category chips
 * Slug: wp-ja-essence/section-articles-categories-slide
 * Description: A row of the blog's sub-categories with their post counts (the Categories block as a sideways row). The source also paints a picture on each; WordPress categories carry none (DECISIONS D-11).
 * Categories: wp-ja-essence
 * Post Types: page
 * Viewport Width: 1440
 * Inserter: yes
 * Keywords: categories, slider
 *
 * @package wp-ja-essence
 */
?>
<!-- wp:group {"className":"je-sec je-sec--cats","layout":{"type":"default"}} -->
<div class="wp-block-group je-sec je-sec--cats">
<!-- wp:heading {"level":2,"className":"je-sec__title screen-reader-text","metadata":{"role":"content","name":"articles-categories.main-section"}} -->
<h2 class="wp-block-heading je-sec__title screen-reader-text">Categories</h2>
<!-- /wp:heading -->
<!-- wp:categories {"showPostCounts":true,"className":"je-catrow"} /-->
</div>
<!-- /wp:group -->
