<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Component\ClaudeCowork\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;

/**
 * The Joomla half of {@see \FilesRestorer}: fetch a files.pack tar and lay it back over the web
 * root.
 *
 * The tar is downloaded to the site's tmp dir and extracted with PharData, which reads the same
 * 512-block tar {@see \TarStream} writes. Extraction is confined to JPATH_ROOT: this is the file
 * half of a restore, and it never touches the database.
 */
final class JoomlaFilesRestorer implements \FilesRestorer
{
    public function restore(string $getUrl): array
    {
        $tmp     = \rtrim((string) Factory::getApplication()->getConfig()->get('tmp_path'), '/');
        $tarPath = $tmp . '/cowork-restore-' . \bin2hex(\random_bytes(6)) . '.tar';

        try {
            $response = HttpFactory::getHttp()->get($getUrl, [], 600);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'could not fetch the snapshot: ' . $e->getMessage()];
        }
        if ((int) $response->code >= 400) {
            return ['ok' => false, 'error' => 'snapshot fetch returned HTTP ' . $response->code];
        }
        if (\file_put_contents($tarPath, $response->body) === false) {
            return ['ok' => false, 'error' => 'could not write the snapshot to tmp'];
        }

        try {
            $phar  = new \PharData($tarPath);
            $count = $phar->count();
            $phar->extractTo(\JPATH_ROOT, null, true);
        } catch (\Throwable $e) {
            @\unlink($tarPath);
            return ['ok' => false, 'error' => 'extracting the snapshot failed: ' . $e->getMessage()];
        }
        @\unlink($tarPath);

        return ['ok' => true, 'files' => $count];
    }
}
