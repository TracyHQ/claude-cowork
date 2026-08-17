<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Component\ClaudeCowork\Site\Controller;

\defined('_JEXEC') or die;

/**
 * The Joomla half of {@see \MediaWriter} — a file put into, read from or removed under the site's
 * media trees.
 *
 * The engine has already refused any path that is not a clean relative path under `images/` or
 * `media/`; this confirms the same thing against the resolved filesystem path before writing, so a
 * symlink under a media folder cannot carry a write out of the webroot. Defence in depth: the
 * cheap string check and the real filesystem check both have to pass.
 */
final class JoomlaMediaWriter implements \MediaWriter
{
    private string $root;

    /** @param string $root The webroot the media trees sit under (JPATH_ROOT on a live site). */
    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    public function read(string $path): ?string
    {
        $abs = $this->confine($path);
        if (!is_file($abs)) {
            return null;
        }
        $bytes = file_get_contents($abs);
        return $bytes === false ? null : $bytes;
    }

    public function write(string $path, string $bytes): void
    {
        $abs = $this->confine($path);
        $dir = \dirname($abs);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("could not create {$dir}");
        }
        if (file_put_contents($abs, $bytes) === false) {
            throw new \RuntimeException("could not write {$path}");
        }
    }

    public function delete(string $path): void
    {
        $abs = $this->confine($path);
        if (is_file($abs)) {
            unlink($abs);
        }
    }

    /**
     * Turn a validated relative media path into an absolute one, and prove it stays under the
     * webroot. The parent directory is resolved with realpath (the file itself may not exist yet);
     * a path whose parent escapes the root — a symlink, most likely — is refused here even though
     * the engine's string check already passed.
     */
    private function confine(string $path): string
    {
        $abs = $this->root . '/' . $path;
        $parent = realpath(\dirname($abs));
        // A parent that does not exist yet cannot have escaped anything; it will be created under
        // the root by write(). One that DOES resolve must resolve inside the root.
        if ($parent !== false && strncmp($parent . '/', $this->root . '/', \strlen($this->root) + 1) !== 0) {
            throw new \RuntimeException('media path escapes the webroot');
        }
        return $abs;
    }
}
