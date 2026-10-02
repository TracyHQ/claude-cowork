# Content model

Read ../AGENTS.md and architecture.md. The facts below come from the theme's files and from the
spec-pack the site was built from (`tasks/ja-essence-wp7/spec/` in the Tracy repository). Counts of
what the seed created are **at seed time** (build stand, seed report 18): 46 posts, 37 pages, 11
categories, 9 authors, 2 navigation posts, 2 template parts, 1 form; the media library held 128
attachments (64 imported picture files and their sizes). Check the live site before relying on any
of them (`wp post list --post_type=page --fields=ID,post_name,post_status`).

## Pages and routes

The spec-pack maps 31 pages, two of them synthetic (the article route and `/404`;
`spec/page-map.json`). Paths keep the Joomla menu paths. Seven grouping pages exist only to hold the
path (`/home`, `/detail`, `/category`, `/pages`, `/pages/user`, `/pages/j-pages`,
`/pages/j-content`); they are published and have no template assigned. The template named below is the one
assigned in `_wp_page_template` on the build stand; "none" means the page falls back to `page.html`.

| Path | Template | Notes |
| --- | --- | --- |
| `/` (page `home-3`, the front page) | `front-page` | two category sections (`acm-articles-category-133`, `-134`), the `front-list` pattern and the gallery strip. `/home/home-3` is a 301 to `/` |
| `/home/home-1`, `/home/home-2`, `/home/home-4`, `/home/home-5` | `page-landing` | the four other home layouts: list pattern (`front-list`, `front-list-warm` on home-2 and home-5, `front-list-grid` on home-4) plus the sections of the source's layout |
| `/category/category-style-1` to `-4`, `/resources`, `/lifestyle` | `page-no-sidebar` | category listings: a query block bound to categories by **term id** (below) |
| `/pages/j-content/category-blog`, `/pages/j-content/author-listting`, `/pages/j-content/tagged-items` | `page-no-sidebar` | blog list (term id of Fashion), a post list, a tag cloud plus post list |
| `/pages/j-content/featured-articles` | `page-landing` | newest posts across the blog category |
| `/detail/layout-1`, `/detail/layout-2`, `/detail/layout-3`, `/detail/video`, `/detail/gallery` | `page-article` | articles that are **pages**, not posts: static byline, Prev/Next, Share article (`__JE_PERMALINK__` token) and Tags in, from the source render; `video` embeds the YouTube player |
| `/contact` | `page-contact` | intro card plus `[contact-form-7 id="65" title="Contact"]` |
| `/pages/user/login-form`, `/pages/user/registration-form` | `page-login`, `page-register` | the seeded content is a bare form block |
| `/pages/user/user-profile`, `/pages/user/edit-user-profile` | `page-account` | a static profile preview, answered 200; the source answers a guest with 303 (D-66) |
| `/pages/j-pages/error-page`, `/offline-pages`, `/typography` | none | plain pages; `typography` is the specimen page |
| `/pages/j-pages/smart-search` | none | a **draft** page answered by the theme's search view (below) |
| `/category/category-style-3/vintage-inspired-martini-cocktail-glasses` | `single` | answered by the post `vintage-inspired-martini-and-cocktail-glasses` (spec: synthetic) |
| `/<post-slug>/` | `single` | the 46 posts |
| `/404` | `404` | synthetic, not a page |

## Templates, parts and what they read

- `templates/`: `404`, `archive`, `front-page`, `home`, `index`, `page`, `page-account`,
  `page-article`, `page-contact`, `page-landing`, `page-login`, `page-no-sidebar`, `page-register`,
  `search`, `single`. `archive`, `home`, `index` and `search` print the pattern `post-list` (inherited
  main query, six per page); `single` prints the post's picture, category badge, title, author
  name and date, content, previous/next links and the post's tags.
- Named blocks the PHP or the seeder looks for by `metadata.name`: `page.title` (heading
  override, in `page.html` and `single.html`), `nav.mainmenu` (header, drawer), `nav.category`
  (footer), plus the editable words `header.subscribe`, `header.social`, `footer.tagline`,
  `footer.cta`, `footer.note`, `footer.copyright`. Do not rename them in a template or a part.
- Page heading: post meta `tracy_page_heading` replaces the page title in the `page.title` block
  when it is not empty (the detail pages carry the article title there, since their menu title is
  "Layout 1" and so on).
- The footer holds words as the source prints them: tagline "A super modern theme following the
  latest trends with premium membership", button "Become a subscriber", the copyright line
  (docs/content-rules.md, D-04 and the Joomla line). They are rows in the `footer` template part.
- The 404 template carries its own words ("404", "Page not found", "An error has occurred while
  processing your request.", a "Home Page" button) and has no header or footer. The draft page
  `page-not-found` (content "Not found") and option `wp_ja_essence_404_page` (its id) exist but
  nothing in the theme reads them: edit the template in the Site Editor to change the 404 words.

## Sections (patterns)

A section is a pattern `wp-ja-essence/<name>` inserted into a page as a **copy**. The seeder fills
each from the spec by `patterns.map.json` (15 Joomla ACM `type:style` entries; the field map is in
that file; a block is located by `metadata.name` = `<type>.<field>[.<n>]`, a repeated row's wrapper
is `<type>.item.<n>`, the section's anchor is `acm-<type>-<module id>`). No synced patterns
(`wp_block`) were created at seed time.

| Pattern | What it draws |
| --- | --- |
| `lead-slider`, `editors-choice` | the row of three picture cards; the "Editor's choice" block |
| `front-list`, `front-list-warm`, `front-list-grid` | the blog list of the home pages: white cards, warm-tinted cards (home-2, home-5), a grid (home-4) |
| `post-list`, `tracy/cards` | the blog list and its card (the overlay's own `tracy/cards` replaces the source theme's); `post-list` is what `index`, `archive`, `home` and `search` print |
| `section-articles-category-slide-feat`, `-slide-normal` | lead sliders by category (ACM `articles-category` styles) |
| `section-articles-category-list-2-feat`, `-list-2-normal`, `-list-blog`, `-default-feat`, `-horizontal-feat` | post rows and lists by category |
| `section-articles-latest-latest-feat`, `-latest-nsub` | newest posts (ACM `articles-latest`) |
| `section-related-items-related-default`, `-related-type-1` | "More reading": other posts, excluding the one being read (`wpJaEssenceOthers`) |
| `section-articles-categories-slide` | category chips: the Categories block, name and count |
| `section-tags-popular` | tag cloud, six tags |
| `section-newsletter` | a card with a title, an intro line and a "Sign up" button linking to `/contact/` (no mailing list, D-10) |
| `section-social` | follow-me card: four service buttons (Facebook, X, Instagram, RSS), glyph chosen by position |
| `section-gallery` | the picture strip (six pictures, `gallery.image.<n>`) |

`section-articles-*` patterns name their categories by slug in the query (`wpJaEssenceCategory`),
resolved at render time with their sub-categories; the slugs on the seeded pages are `blog-health`,
`blog-design`, `blog-fashion`, `blog`, `blog-normal` and `blog-normal-health`/`-fashion`. Posts are
newest first: the source orders some lists by hits, and WordPress has no hit counter (D-09, D-14).
The seeded queries set `"sticky":"ignore"` because 20 posts are sticky (the source's featured
articles) and WordPress would otherwise prepend them to every nested query on the static front page
(D-08).

## Listing pages and term ids

- The category pages' query blocks are bound by **term id**, not by slug: `category-style-1` to
  term 5 (`blog-health`), `category-style-2` to 6 (`blog-design`), `category-style-3` and
  `category-blog` to 7 (`blog-fashion`), `category-style-4` to 4, 10, 11, 12 (`blog-normal` and its
  children), `resources` to 8, `lifestyle` to 9 (ids on the build stand). A site restored from a
  quickstart keeps the ids; a re-seed on another database writes the ids that database assigned.
  Deleting and recreating a category breaks its listing silently.
- Duplicate category titles are disambiguated by slug: Health, Design, Fashion exist twice
  (`blog-health` and `blog-normal-health`, and so on) because the source has two blog trees; a
  Joomla alias that repeats across categories got a `-2` slug.
- Category views differ in the source (hero card, horizontal cards, sidebar); here one `tracy/cards`
  card serves all, and type sizes vary per page through the body class `je-slug-<page slug>`; do
  not rename those pages (D-53). Category Blog also shows the card's intro and tags
  (`.je-slug-category-blog`).
- Pagers: a pager block with the class `tracy-joomla-pager` is drawn by the must-use plugin
  `tracy-joomla-pagination`; a listing of one page prints no pager. Without the plugin core's pager
  prints.

## Search

`/pages/j-pages/smart-search` is the source's `com_finder` page. The theme maps it to WordPress
search: the page must exist as a **draft** (a published copy would take the path away from the
filter). The seeder links it from the menu as a custom link with a trailing slash, since a menu
link by id to a draft is dropped (S-10). The header search is a core search block; `/?s=` also
works.

## Posts, categories, tags

- 46 posts under `blog-*`, `blog-normal-*`, `resources` or `resources-2` (Lifestyle); 20 are
  sticky. Their authors are nine imported users (role `author`); a post's byline
  is the post-author block, the author pictures come from user meta `tracy_avatar`
  (must-use plugin). Posts keep their tags (6 tags on the stand).
- Categories on the stand: `blog`, `blog-normal` (both empty parents), `blog-health` 9,
  `blog-design` 9, `blog-fashion` 7, `blog-normal-health` 4, `blog-normal-design` 3,
  `blog-normal-fashion` 3, `resources` 7, `resources-2` 5, `uncategorised` and WordPress's own
  `uncategorized`, 0.
- 19 articles share an alias across categories; WordPress renumbered the later ones with `-2`
  (S-03). Articles answer at `/<slug>/`; the one Joomla article address kept is the article route
  (docs/architecture.md). The spec's `redirects.json` carries one rule (`/home/home-3` to `/`).

## Menus

Two `wp_navigation` posts at seed time (ids 149 and 150 on the stand), one per Joomla menu type of
`spec/menus.json`:

| Menu type | Items (spec) | Used in |
| --- | --- | --- |
| `mainmenu` (post `mainmenu`) | Home (Home 1 to 5), #Category (Category Style 1 to 4), Detail (Layout 1 to 3, Video, Gallery), Pages (User: Login Form, Registration Form, User Profile, Edit User Profile; J!Pages: Error Page, Offline Pages, Typography, Smart Search; J! Content: Category Blog, Featured Articles, Author listting, Tagged Items), Contact | header (`nav.mainmenu`, the bar) and the header's off-canvas drawer (`nav.mainmenu`) |
| `category` (post `category`) | Health, Design, Fashion (the first three category-style pages), Resources, Lifestyle | footer (`nav.category`) |

The source's unpublished items (a second "Home 1" alias, "Blog Detail", "Demo", a second Fashion)
were not carried. The two parts' navigation blocks hold `"ref"` ids pointing at these posts (149,
150 on the stand); a database where the posts got other ids needs the parts' rows re-pointed.

## Options, meta and redirects

| Key | Meaning |
| --- | --- |
| `wp_ja_essence_redirects` | rules `{from, to, status}`; exact path, applied only when WordPress would answer 404, by `tracy-redirects`. Two rules at seed time: `/home/home-3` to `/`, and the source address of the article route to `/vintage-inspired-martini-and-cocktail-glasses/` (the theme also answers that address with 200, so the rule is a fallback) |
| `tracy_redirects_option` | the name of the option above, read by the must-use plugin |
| `wp_ja_essence_404_page` | id of the draft page `page-not-found`; **unused by the theme** |
| `category_base` | `topic` (docs/install.md) |
| `tracy_motion` | motion settings; absent, so no motion effect loads |
| post meta `tracy_page_heading` | heading override for pages |
| post meta `_tracy_seed` | the seeder's fingerprint of a row; do not edit |
| user meta `tracy_avatar` | attachment id of an author's picture |
