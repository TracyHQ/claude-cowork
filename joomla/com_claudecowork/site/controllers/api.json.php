<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

/**
 * The endpoint is always called with `&format=json`, and Joomla 3 turns that into a
 * format-specific controller filename — it looks for `controllers/api.json.php`, not
 * `controllers/api.php`. This is that file: it just loads the one real controller, so the same
 * class serves a request whether the format is json or the default. (Joomla 4+ ignores both and
 * uses the namespaced `src/Controller/ApiController`.)
 */
require_once __DIR__ . '/api.php';
