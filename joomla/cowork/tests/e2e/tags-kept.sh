#!/usr/bin/env bash
# End-to-end: a content write that does not name an article's tags keeps them, on a throwaway Joomla.
#
#   joomla/cowork/tests/e2e/tags-kept.sh                 # Joomla 6 (JOOMLA_IMAGE, KEEP: joomla-stand.sh)
#
# Needs docker and node. Nothing leaves this machine: every container and network is made here and
# removed at the end.
#
# ## Why a real Joomla
#
# The bug lives in Joomla, not in lib/: Joomla's Taggable behaviour reads a Table\Content store with
# no `newTags` as "every tag removed" and deletes the article's #__contentitem_tag_map rows. A write
# through the door (content.update, a contract apply, an apply.revert) that names only the words
# took a JA Podcast site's tag map from 190 rows to 0 (measured 08/10/2026, fixed in 0.24.1 by
# JoomlaSiteWriter handing the behaviour the tags the article already has). tests/run.php never
# reaches JoomlaSiteWriter, so this is the test that would have caught it (TCH #1013, D3).
#
# ## What it proves
#
# Tags and a category made through the door, an article in that category with two tags, then:
# 1. a content.update of the article's title alone keeps both tags and its category;
# 2. a content.batch writing the article's words keeps them;
# 3. apply.revert of both (which writes the old title back, naming no tags) keeps them;
# 4. a content.update naming `tags` still replaces them: the fix keeps tags, it does not freeze them;
# 5. a write to the category keeps the article's category and tags.
set -euo pipefail
E2E_NAME=tags
# shellcheck source=joomla-stand.sh
. "$(dirname "$0")/joomla-stand.sh"

# The article's tag ids, sorted, comma-separated; and its category.
tags_of() { sql "SELECT IFNULL(GROUP_CONCAT(tag_id ORDER BY tag_id),'') FROM jos_contentitem_tag_map WHERE type_alias='com_content.article' AND content_item_id=$1"; }
category_of() { sql "SELECT catid FROM jos_content WHERE id=$1"; }

say "tags, a category and a tagged article, made through the door"
call content.update '{"apply_id":"e2e-setup","kind":"tag","fields":{"title":"Alpha"}}'; ALPHA=$(answered_id)
call content.update '{"apply_id":"e2e-setup","kind":"tag","fields":{"title":"Beta"}}'; BETA=$(answered_id)
call content.update '{"apply_id":"e2e-setup","kind":"tag","fields":{"title":"Gamma"}}'; GAMMA=$(answered_id)
call content.update '{"apply_id":"e2e-setup","kind":"category","fields":{"title":"Kept news"}}'; CAT=$(answered_id)
call content.update "$(printf '{"apply_id":"e2e-setup","kind":"article","fields":{"title":"Demo episode","catid":%s,"state":1,"introtext":"<p>Demo words.</p>","tags":["Alpha","Beta"]}}' "$CAT")"
ART=$(answered_id)
TAGGED=$(printf '%s\n' "$ALPHA" "$BETA" | sort -n | paste -sd, -)
expect "the article starts with two tags" "$(tags_of "$ART")" "$TAGGED"
expect "and in its category" "$(category_of "$ART")" "$CAT"

say "1. content.update of the title alone"
call content.update "$(printf '{"apply_id":"e2e-words","kind":"article","id":%s,"fields":{"title":"Our first episode"}}' "$ART")"
expect "the title changed" "$(sql "SELECT title FROM jos_content WHERE id=$ART")" "Our first episode"
expect "the article keeps both tags" "$(tags_of "$ART")" "$TAGGED"
expect "and its category" "$(category_of "$ART")" "$CAT"

say "2. content.batch writing the article's words"
call content.batch "$(printf '{"apply_id":"e2e-words","request_id":"e2e-batch-1","operations":[{"kind":"article","id":%s,"fields":{"introtext":"<p>Our own words.</p>"}}]}' "$ART")"
expect "the article keeps both tags after a batch" "$(tags_of "$ART")" "$TAGGED"

say "3. apply.revert of both writes"
call apply.revert '{"apply_id":"e2e-words"}'
expect "the title is back" "$(sql "SELECT title FROM jos_content WHERE id=$ART")" "Demo episode"
expect "the article keeps both tags after the revert" "$(tags_of "$ART")" "$TAGGED"
expect "and its category" "$(category_of "$ART")" "$CAT"

say "4. a write naming tags still replaces them"
call content.update "$(printf '{"apply_id":"e2e-tags","kind":"article","id":%s,"fields":{"title":"Retagged episode","tags":["Gamma"]}}' "$ART")"
expect "the article's tags are the ones named" "$(tags_of "$ART")" "$GAMMA"

say "5. a write to the category"
BEFORE_CATEGORY_WRITE=$(tags_of "$ART")
call content.update "$(printf '{"apply_id":"e2e-category","kind":"category","id":%s,"fields":{"description":"<p>Our news.</p>"}}' "$CAT")"
expect "the article keeps its category" "$(category_of "$ART")" "$CAT"
expect "and its tags" "$(tags_of "$ART")" "$BEFORE_CATEGORY_WRITE"
expect "the category is still published" "$(sql "SELECT published FROM jos_categories WHERE id=$CAT")" "1"

finish
