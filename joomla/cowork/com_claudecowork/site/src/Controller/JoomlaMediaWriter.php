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
        $resolvedRoot = realpath($root);
        $this->root = rtrim($resolvedRoot === false ? $root : $resolvedRoot, '/');
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

    public function snapshotForDelete(string $path): array
    {
        if (strpos($path, 'images/') !== 0) throw new \RuntimeException('media.delete accepts images/ paths only');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'ico'], true)) throw new \RuntimeException('media.delete accepts image files only');
        $abs = $this->confine($path);
        $images = realpath($this->root . '/images');
        $real = realpath($abs);
        if ($images === false || $real === false || !is_file($real) || strncmp($real, $images . DIRECTORY_SEPARATOR, strlen($images) + 1) !== 0) throw new \RuntimeException('path must be an existing file whose realpath stays under images/');
        $bytes = file_get_contents($real);
        if ($bytes === false) throw new \RuntimeException('could not read image file');
        $size = filesize($real);
        if ($size === false || strlen($bytes) !== $size) throw new \RuntimeException('image changed while its undo copy was read');
        return ['path' => $path, 'bytes' => $bytes, 'size' => $size, 'sha256' => hash('sha256', $bytes)];
    }

    public function restoreDeletedFile(array $snapshot, bool $rollback = false): void
    {
        $path = (string) ($snapshot['path'] ?? '');
        $bytes = (string) ($snapshot['bytes'] ?? '');
        if (strlen($bytes) !== (int) ($snapshot['size'] ?? -1) || !hash_equals((string) ($snapshot['sha256'] ?? ''), hash('sha256', $bytes))) throw new \RuntimeException('undo bytes failed verification');
        if ($this->deletedFilePathExists($path)) {
            if (!$rollback) throw new \RuntimeException('file path already exists: ' . $path);
            $existing = $this->snapshotForDelete($path);
            if (!hash_equals((string) $snapshot['sha256'], (string) ($existing['sha256'] ?? ''))) throw new \RuntimeException('file path now contains different bytes: ' . $path);
            return;
        }
        try {
            $this->write($path, $bytes);
            $restored = $this->snapshotForDelete($path);
            if (!hash_equals((string) $snapshot['sha256'], (string) ($restored['sha256'] ?? ''))) throw new \RuntimeException('restored image failed verification');
        } catch (\Throwable $e) {
            $abs = $this->root . '/' . $path;
            if (is_file($abs) && !is_link($abs)) unlink($abs);
            throw $e;
        }
    }

    public function deletedFilePathExists(string $path): bool
    {
        if (strpos($path, 'images/') !== 0 || strpos($path, '..') !== false || strpos($path, "\0") !== false) return false;
        $abs = $this->root . '/' . $path;
        return file_exists($abs) || is_link($abs);
    }

    /**
     * Turn a validated relative media path into an absolute one, and prove it stays under the
     * webroot. The parent directory is resolved with realpath (the file itself may not exist yet);
     * a path whose parent escapes the root — a symlink, most likely — is refused here even though
     * the engine's string check already passed.
     */
    private function confine(string $path): string
    {
        if ($path === '' || strlen($path) > 1024 || strpos($path, "\0") !== false || $path[0] === '/' || $path[0] === '\\' || strpos($path, '..') !== false || strpos($path, '\\') !== false || preg_match('#^[A-Za-z]:#', $path) === 1 || (strncmp($path, 'images/', 7) !== 0 && strncmp($path, 'media/', 6) !== 0)) throw new \RuntimeException('media path is not a safe relative path');
        $abs = $this->root . '/' . $path;
        $root = realpath($this->root);
        $parent = realpath(\dirname($abs));
        // A parent that does not exist yet cannot have escaped anything; it will be created under
        // the root by write(). One that DOES resolve must resolve inside the root.
        if ($root === false || ($parent !== false && $parent !== $root && strncmp($parent, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0)) {
            throw new \RuntimeException('media path escapes the webroot');
        }
        return $abs;
    }
}
