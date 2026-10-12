<?php
/**
 * Title: News index head
 * Slug: wp-tracy-business/news-index-head
 * Description: The heading and lead of the news index (templates/home.html, blocks home.title and home.lead, which inc/extra.php fills from the posts page). Its words go through the theme's text domain, so a site in another language shows them in it.
 * Categories: wp-tracy-business
 * Inserter: no
 *
 * @package wp-tracy-business
 */
?>
<!-- wp:heading {"level":1,"className":"tracy-display","metadata":{"name":"home.title"}} -->
<h1 class="wp-block-heading tracy-display"><?php echo esc_html__( 'News', 'wp-tracy-business' ); ?></h1>
<!-- /wp:heading -->
<!-- wp:group {"className":"tracy-listing__intro","layout":{"type":"flex","justifyContent":"space-between","verticalAlignment":"bottom","flexWrap":"wrap"}} -->
<div class="wp-block-group tracy-listing__intro">
<!-- wp:paragraph {"className":"tracy-lead","metadata":{"name":"home.lead"}} -->
<p class="tracy-lead"><?php echo esc_html__( 'Updates and notes from the team.', 'wp-tracy-business' ); ?></p>
<!-- /wp:paragraph -->
