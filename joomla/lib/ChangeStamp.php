<?php

/**
 * A file at the webroot saying when the site last changed, so a preview can notice.
 *
 * Tracy Desk shows a customer their site while an agent works on it. It already watches the
 * commit the fleet deployed, which covers everything arriving through git — but a CMS change
 * produces no commit. Switching a template style, rewriting an article, installing an extension:
 * the site looks different and nothing outside it can tell. 🔒 Observed 2026-08-15 on the
 * WordPress side, where this file was written first: a theme installed through git appeared on
 * the clone in five seconds, then activating it changed nothing the customer could see until
 * they reloaded by hand.
 *
 * ## Why a file, and not a call out
 *
 * The obvious shape is for the site to notify Tracy. It cannot: Desk runs on the customer's
 * machine and has no address to be called at. The site would have to push to a relay that
 * forwards to a desk that may not be running — a lot of moving parts to carry one timestamp.
 *
 * A file inverts it. The site writes; whoever is watching reads. No endpoint, no token on this
 * path, no request reaching PHP on every poll (the web server answers a static file), and it
 * works the same for a site on Tracy's fleet as for one on the customer's own host.
 *
 * ## What this Joomla copy does NOT cover, and why that is not a bug
 *
 * The WordPress plugin hooks the CMS itself, so it notices any change by anyone. This component
 * is only loaded when its own endpoint is hit, so it can only stamp what came THROUGH it — an
 * Apply, a media upload, an extension install, a revert. An administrator editing an article in
 * the Joomla backend writes no stamp, and the preview will not notice that until somebody
 * reloads or the next deploy lands. Closing that gap needs a system plugin, which the package
 * manifest was deliberately shaped to allow adding later.
 *
 * What it does cover is the case the file exists for: work an agent just did, on a site the
 * customer is watching right now.
 *
 * ## What is deliberately NOT in it
 *
 * Only a timestamp and a coarse reason. Not what changed, not who changed it, not any id — this
 * file is world-readable by design, and "the site changed 3 seconds ago" is not worth protecting
 * while "article 41 was edited under apply_id task-88" would be.
 */
final class ChangeStamp
{
    /** Sits beside `tracy-deployed.json`, which the fleet writes for the git half of the story. */
    public const FILENAME = 'tracy-changed.json';

    /**
     * Coalescing window, in seconds.
     *
     * One Apply is several actions — a content update, then a media upload, then another. Writing
     * the file each time would have a preview reloading three times for one deliverable, and
     * reloading a page mid-Apply is worse than reloading it once at the end. A second is short
     * enough that nobody perceives the delay and long enough to collapse a burst into one write.
     */
    private const COALESCE_SECONDS = 1;

    private string $path;

    public function __construct(string $webroot)
    {
        $this->path = rtrim($webroot, '/\\') . DIRECTORY_SEPARATOR . self::FILENAME;
    }

    /**
     * Record that the site changed.
     *
     * Never throws. This runs at the end of a request that already succeeded — the change is on
     * the site whether or not this file gets written — and a preview that fails to notice is a
     * small disappointment, while an Apply reported as failed because a hint file could not be
     * written is a lie about the customer's site. A read-only webroot (some hardened hosts)
     * simply means no auto-reload, silently.
     */
    public function touch(string $reason = 'change'): void
    {
        try {
            $current = $this->read();
            if ($current !== null && (time() - (int) ($current['at'] ?? 0)) < self::COALESCE_SECONDS) {
                return;
            }

            $payload = json_encode(
                ['at' => time(), 'reason' => $reason],
                JSON_UNESCAPED_SLASHES
            );

            // Write-then-rename: a reader polling every few seconds must never catch a
            // half-written file and parse nothing out of it.
            $temp = $this->path . '.tmp';
            if (@file_put_contents($temp, $payload) === false) {
                return;
            }
            if (!@rename($temp, $this->path)) {
                @unlink($temp);
            }
        } catch (Throwable $e) {
            // Deliberately swallowed — see the docblock.
        }
    }

    /** @return array<string,mixed>|null */
    public function read(): ?array
    {
        if (!is_readable($this->path)) {
            return null;
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            return null;
        }
        $parsed = json_decode($raw, true);
        return is_array($parsed) ? $parsed : null;
    }
}
