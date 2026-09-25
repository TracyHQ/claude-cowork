<?php
/**
 * ContentIdentity — the one write the content reader depends on, and when it happens.
 *
 * `/content.json` hands out ids that must outlive a new title, slug or order and must not come
 * back when a row is deleted and made again. WordPress's own post id is neither private nor
 * guaranteed never to be reused, so each row gets a random uid in `_tracy_content_uid` once, and
 * the site a random content key in `claude_cowork_content_site`. The reader turns both into
 * opaque ids; it never writes either.
 *
 * Two writers, both outside a read: WordPress inserting a row (this hook, loaded on every request
 * because content is made on ordinary admin requests), and the explicit `content.identity`
 * action for everything older (`Claude_Cowork_Content_Source::ensureIdentity`). A site that never
 * ran the action is not opted in, and this hook does nothing there.
 */
final class ContentIdentity
{
    public const UID_META = '_tracy_content_uid';
    public const SITE_OPTION = 'claude_cowork_content_site';
    /** Rows that get a uid: what the reader lists, and attachments for image ids. */
    public const TYPES = ['page', 'post', 'wp_template_part', 'wp_block', 'wp_navigation', 'attachment'];

    /** `wp_insert_post` for a NEW row. Never throws into somebody else's save. */
    public static function mintOnInsert($postId, $post, $update): void
    {
        try {
            if ($update || !is_object($post) || !in_array((string) ($post->post_type ?? ''), self::TYPES, true)) {
                return;
            }
            if (trim((string) get_option(self::SITE_OPTION, '')) === '') {
                return;
            }
            add_post_meta((int) $postId, self::UID_META, bin2hex(random_bytes(16)), true);
        } catch (Throwable $e) {
            // A missing uid is reported by the reader (WP_IDENTITY_MISSING) and repaired by content.identity.
        }
    }
}
