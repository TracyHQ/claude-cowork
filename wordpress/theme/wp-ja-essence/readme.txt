=== WP Essence ===
Contributors: joomlart
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.6
License: GNU General Public License v3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

JA Essence: the JoomlArt Essence editorial blog design as a WordPress block theme, carried from its Joomla 6 (T4) quickstart.

== Description ==

JA Essence is the Essence editorial blog packaged as a block theme, ported from the JoomlArt JA Essence Joomla 6 quickstart (T4 framework) so that it renders the same pages. Every section of the site is a block pattern the Site Editor can edit, the look is measured on the running source and carried by theme.json and the theme stylesheet, and the header carries the main menu, a search, a light / dark / automatic switch that follows the operating system until the visitor chooses, and the button that opens the off-canvas menu.

The contact page calls a Contact Form 7 form; WordPress core sends no mail, so the form needs that plugin and a working mail route on the host.

This theme is not intended for the wordpress.org directory: it bundles patterns for a specific site. It does not update itself; a newer version is installed by uploading its zip.

== Copyright ==

Copyright (C), J.O.O.M Solutions Co., Ltd. All Rights Reserved.
JA Essence is distributed under the terms of the GNU General Public License v3,
http://www.gnu.org/licenses/gpl-3.0.html — the copyright and licence of the JoomlArt JA Essence
Joomla template it is ported from (templateDetails.xml), which the design comes from. No
stylesheet, script or template file of the source is bundled: the theme's CSS was written from
measurements of the running source, and the site's pictures live in the WordPress media library,
not in this theme.

The theme generator and the token schema derive from the OpenDesign design-system library,
(C) the OpenDesign contributors, Apache License 2.0,
https://github.com/nexu-io/open-design
Token values the source did not provide are taken from its "apple" design system (listed at the end
of tokens.css in the theme's build sources).

Jost, Copyright 2020 The Jost Project Authors (https://github.com/indestructible-type/Jost), and
Noto Serif, Copyright 2022 The Noto Project Authors (https://github.com/notofonts/latin-greek-cyrillic),
are licensed under the SIL Open Font License 1.1 (https://openfontlicense.org/). The woff2 files in
assets/fonts are the latin builds of the Google Fonts families the source template loads,
self-hosted.

== Changelog ==

= 1.0.10 =
* The footer Site Logo blocks carry shouldSyncIcon:false, so saving the footer in the editor never overwrites the
  site icon with the logo. Not released on its own: ships with the next quickstart/ja-essence/wp7 cut.
* Interface words go through the theme's text domain: the error page (its card is the pattern page-404 now, and its
  browser tab), the no-results lines of the post list, the Newsletter, Follow me and Trending cards, and the final
  call to action of the section library.
* The identity tokens the theme fills are listed in inc/identity.php (one: {site.title}, the site name).
* The footer tagline, its "Become a subscriber" button and the note under it carry no block name, so a site build
  rewrites them with the page's other words.
* The Follow me buttons and the gallery's Instagram button lead to the networks (RSS to the site's feed) instead of #.
* "Author's latest articles" on a page is a Query Loop (author and count in the block's query) that prints the same
  cards, so the page says in data which articles it lists.

= 1.0.9 =
* The footer logo is a light/dark pair of Site Logo blocks instead of an inverted picture; the copyright line and
  every browser tab name the site.
