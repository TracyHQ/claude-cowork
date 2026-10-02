<?php
/**
 * Title: Newsletter
 * Slug: wp-ja-essence/section-newsletter
 * Description: A blue sidebar card: a title, a line about the subscribers and a sign-up button. The source draws an AcyMailing form; WordPress has no mailing list here, so the button is a link to edit (DECISIONS D-10).
 * Categories: wp-ja-essence
 * Post Types: page
 * Viewport Width: 1440
 * Inserter: yes
 * Keywords: newsletter, subscribe
 *
 * @package wp-ja-essence
 */
?>
<!-- wp:group {"className":"je-sec je-side je-side--news","layout":{"type":"default"}} -->
<div class="wp-block-group je-sec je-side je-side--news">
<!-- wp:heading {"level":2,"className":"je-sec__title","metadata":{"role":"content","name":"newsletter.title"}} -->
<h2 class="wp-block-heading je-sec__title">Newsletter</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"je-side__intro","metadata":{"role":"content","name":"newsletter.introtext"}} -->
<p class="je-side__intro">Join 70,000 subscribers!</p>
<!-- /wp:paragraph -->
<!-- wp:buttons {"className":"je-side__action"} -->
<div class="wp-block-buttons je-side__action">
<!-- wp:button {"className":"je-btn-dark","metadata":{"role":"content","name":"newsletter.subtext"}} -->
<div class="wp-block-button je-btn-dark"><a class="wp-block-button__link wp-element-button" href="/contact/">Sign up</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
