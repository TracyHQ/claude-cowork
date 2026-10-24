<?php
/**
 * TemplateFiles — `template.files.status`, `template.files.write` and `template.files.undo`: the
 * one door that writes a file of the site's own look (a template's CSS, SCSS, JS, overrides; a
 * theme's or child theme's CSS, JS and PHP), so a change made and reviewed on a copy of the site
 * can be put on the live one, and taken off again.
 *
 * What it may touch is decided here, never by the caller:
 *  - only under a ROOT the host names (Joomla: each installed site template's `templates/<name>/`
 *    and `media/templates/site/<name>/`; WordPress: the active theme and its parent), each checked
 *    on every call: a real directory under the site root, reached through no symbolic link;
 *  - a relative path of plain segments: no `..`, no `.`, no leading slash, no backslash, no
 *    dotfile or dot-folder, no symbolic link anywhere on the way, the leaf a regular file or absent;
 *  - one of {@see EXTENSIONS}; PHP only under a root that holds PHP (never a web-served media
 *    folder); never a name in {@see DENIED_NAMES}, never a double extension that a web server
 *    could run (`x.php.css`);
 *  - at most {@see MAX_FILES} files and {@see MAX_BYTES} bytes a call, {@see MAX_FILE_BYTES} a file.
 *
 * A write is compare-and-set, all or nothing: every file names what the live site must hold now
 * (`expected_sha1` or `expected_blob`, null for "absent"), every expectation is checked before
 * anything moves (the engine holds its write lock around the call), a PHP file is linted with the
 * site's own PHP first, each file goes to a temporary file in its own folder and is renamed into
 * place, and a rename that fails puts back what this call already moved. What each file held
 * before is recorded in the Apply log under the caller's `apply_id` with an `undo_id` of this call:
 * `template.files.undo` (and `apply.revert`) put it back only where the live file still holds what
 * the write left there — a reverse compare-and-set — and name the rest.
 *
 * Plain PHP 7.4, no CMS dependency: the host hands in the roots, what to empty after a change, and
 * where to tell the site's administrator; the tests hand in a folder on disk.
 */

/** A batch that could not land; `left` names the files its own roll-back could not put back. */
final class TemplateFilesFailed extends RuntimeException
{
    /** @var list<string> */
    public array $left;

    /** @param list<string> $left */
    public function __construct(string $message, array $left = [])
    {
        parent::__construct($message);
        $this->left = $left;
    }
}

final class TemplateFiles
{
    /** The shape of the three actions; a caller reads it from `info.template_files.version`. */
    public const VERSION = 1;
    /**
     * One file. Its previous bytes go into one Apply log row (base64 of a serialized entry, about
     * 1.4 times the bytes), which must fit the database's max_allowed_packet: 4 MiB on older MySQL.
     */
    public const MAX_FILE_BYTES = 2097152; // 2 MiB
    /** Every file of one call, decoded. One request body carries them all, base64 (a third more). */
    public const MAX_BYTES = 8388608; // 8 MiB
    public const MAX_FILES = 100;
    public const MAX_STATUS_PATHS = 500;
    public const MAX_PATH = 512;
    public const MAX_DEPTH = 16;
    /** What a look is made of. No XML (a manifest is the installer's), no INI, nothing a server runs but PHP. */
    public const EXTENSIONS = ['css', 'scss', 'sass', 'less', 'js', 'mjs', 'json', 'map', 'php', 'html', 'htm', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot'];
    /** Never written, wherever they stand: a site's own configuration and the server's. */
    public const DENIED_NAMES = ['configuration.php', 'wp-config.php', 'wp-config-sample.php', '.htaccess', '.htpasswd', '.user.ini', 'php.ini', 'web.config'];
    /** An inner extension a misconfigured server may still run (`AddHandler` matches anywhere in the name). */
    private const RUNNABLE_INNER = '~\.(php\d*|phtml|phar|pht|phps|cgi)\.~i';
    /** One path segment: plain characters, never starting with a dot. */
    private const SEGMENT = '~^[A-Za-z0-9_@+-][A-Za-z0-9._@+-]*$~D';
    private const HASH = '~^[a-f0-9]{40}$~D';
    private const UNDO_ID = '~^[a-f0-9]{24}$~D';
    /** Temporary and parked files: a dotfile, so no rule of this door ever names one. */
    private const TEMP_PREFIX = '.tracy-tf-';
    /** Exactly the names this door gives its temporaries and parked files, and nothing else. */
    private const TEMP_NAME = '~^\.tracy-tf-[a-f0-9]{16}\.(tmp|del)$~D';
    /** A temporary or parked file older than this was left by a request that died: it is swept. */
    private const STALE_SECONDS = 3600;
    /** The Apply log step this door records, one per file. */
    public const LOG_OP = 'templateFiles';

    private string $root;
    /** @var callable(): list<array{path:string,kind?:string,name?:string,php?:bool}> */
    private $roots;
    /** @var callable(list<string>): void|null what to empty after files changed (a page cache, T4's combined CSS) */
    private $onChange;
    /** @var callable(array<string,mixed>): void|null where the site's administrator reads what was done */
    private $audit;
    /** Forced lint mode for tests: 'cli', 'tokens' or 'none'. */
    private ?string $lintMode;
    /** @var callable(string,string): bool */
    private $rename;
    /** @var array<string,array{path:string,kind:string,name:string,php:bool}>|null */
    private ?array $resolved = null;
    /** null: not looked for yet; '': none usable. */
    private ?string $cli = null;
    /** The owner's main switch: off, every action answers `disabled` and `info` says `enabled: false`. */
    private bool $enabled = true;
    /** The owner's PHP switch, off unless set: every `.php` path is refused `php_disabled`. */
    private bool $phpAllowed = false;

    /**
     * @param string $root the site root (JPATH_ROOT, ABSPATH)
     * @param callable(): list<array{path:string,kind?:string,name?:string,php?:bool}> $roots the
     *        folders this door may write under, site-relative; `php` says whether PHP may be written there
     */
    public function __construct(string $root, callable $roots, ?callable $onChange = null, ?callable $audit = null, ?string $lintMode = null)
    {
        $this->root = rtrim($root, '/');
        $this->roots = $roots;
        $this->onChange = $onChange;
        $this->audit = $audit;
        $this->lintMode = $lintMode;
        $this->rename = static function (string $from, string $to): bool { return @rename($from, $to); };
    }

    /** The owner's main switch (Joomla Options › Template files, WordPress Settings › Claude Cowork). */
    public function enabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /** The owner's PHP switch (Allow PHP in template files); off by default. */
    public function phpAllowed(bool $allowed): self
    {
        $this->phpAllowed = $allowed;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** Tests only: the rename every move goes through, so a failure halfway through a batch can be staged. */
    public function renameWith(callable $rename): self
    {
        $this->rename = $rename;
        return $this;
    }

    /**
     * What `info` advertises: `{version, enabled: false}` when the owner switched the door off, so a
     * caller can tell them where to switch it on; else its limits and whether PHP may be written.
     */
    public function info(): array
    {
        if (!$this->enabled) return ['version' => self::VERSION, 'enabled' => false];
        return [
            'version' => self::VERSION,
            'enabled' => true,
            'php' => $this->phpAllowed,
            'actions' => ['template.files.status', 'template.files.write', 'template.files.undo'],
            'max_file_bytes' => self::MAX_FILE_BYTES,
            'max_bytes' => self::MAX_BYTES,
            'max_files' => self::MAX_FILES,
            'max_status_paths' => self::MAX_STATUS_PATHS,
            'extensions' => self::EXTENSIONS,
            'php_lint' => $this->lintModeNow(false),
        ];
    }

    // ── the three actions ─────────────────────────────────────────────────────────────────────

    /**
     * `template.files.status {paths?}`: the roots, and for each path asked about whether this door
     * would take it and what the site holds there (`sha1`, git's `blob`, `sha256`, `bytes`). Reads only.
     */
    public function status(array $p): array
    {
        if (!$this->enabled) return self::disabled();
        $swept = $this->sweep();
        $paths = $p['paths'] ?? [];
        if (!is_array($paths) || ($paths !== [] && array_values($paths) !== $paths)) return self::err('bad_params', 'paths must be a list of site-relative paths');
        if (count($paths) > self::MAX_STATUS_PATHS) return self::err('too_large', 'at most ' . self::MAX_STATUS_PATHS . ' paths a call');
        $files = [];
        foreach ($paths as $path) {
            if (!is_string($path)) return self::err('bad_params', 'paths must be a list of site-relative paths');
            $where = $this->check($path);
            if (isset($where['reason'])) { $files[] = ['path' => $path, 'refused' => $where['reason']]; continue; }
            try {
                $live = $this->state($where['abs']);
            } catch (Throwable $e) {
                $files[] = ['path' => $path, 'refused' => 'unreadable'];
                continue;
            }
            $files[] = ['path' => $path, 'exists' => $live !== null] + ($live === null ? [] : self::shown($live)) + ['root' => $where['root']['path']];
        }
        return ['ok' => true, 'roots' => array_values($this->roots()), 'files' => $files, 'php' => $this->phpAllowed, 'php_lint' => $this->lintModeNow(false), 'swept' => $swept];
    }

    /**
     * `template.files.write {apply_id, files: [{path, expected_sha1|expected_blob, content_b64, sha256} | {path, delete: true, expected_sha1|expected_blob}], dry_run?}`.
     * Run under the engine's write lock. Answers each file's `op` (created, updated, deleted, unchanged)
     * and the `undo_id` of what changed; or names every path that stopped it, with nothing written.
     */
    public function write(array $p, string $applyId, ApplyLog $log): array
    {
        if (!$this->enabled) return self::disabled();
        $files = $p['files'] ?? null;
        if (!is_array($files) || $files === [] || array_values($files) !== $files) return self::err('bad_params', 'files must be a non-empty list');
        if (count($files) > self::MAX_FILES) return self::err('too_large', 'at most ' . self::MAX_FILES . ' files a call; send the rest in another call', ['max_files' => self::MAX_FILES]);
        $dryRun = ($p['dry_run'] ?? false) === true;
        // A dry run changes nothing on disk, not even the leftovers of a request that died.
        $swept = $dryRun ? 0 : $this->sweep();

        // ── the request itself: shapes, sizes, checksums ──
        $items = [];
        $seen = [];
        $total = 0;
        foreach ($files as $i => $file) {
            if (!is_array($file) || !is_string($file['path'] ?? null)) return self::err('bad_params', "files[$i] needs a path");
            $path = $file['path'];
            if (isset($seen[strtolower($path)])) return self::err('bad_params', "$path is named twice in one call");
            $seen[strtolower($path)] = true;
            $delete = ($file['delete'] ?? false) === true;
            $hasSha1 = array_key_exists('expected_sha1', $file);
            $hasBlob = array_key_exists('expected_blob', $file);
            if ($hasSha1 === $hasBlob) return self::err('bad_params', "$path: name what the site holds there now, as exactly one of expected_sha1 or expected_blob (null: no file)");
            $expected = $hasSha1 ? $file['expected_sha1'] : $file['expected_blob'];
            if ($expected !== null && (!is_string($expected) || !preg_match(self::HASH, strtolower($expected)))) return self::err('bad_params', "$path: an expected hash is 40 hex characters, or null");
            $item = ['path' => $path, 'delete' => $delete, 'expectKind' => $hasSha1 ? 'sha1' : 'blob', 'expected' => $expected === null ? null : strtolower($expected), 'bytes' => null];
            if ($delete) {
                if ($expected === null) return self::err('bad_params', "$path: a delete names the hash of the file it removes");
                if (array_key_exists('content_b64', $file)) return self::err('bad_params', "$path: a delete carries no content");
            } else {
                $bytes = is_string($file['content_b64'] ?? null) ? base64_decode($file['content_b64'], true) : false;
                if ($bytes === false) return self::err('bad_params', "$path: content_b64 is missing or not valid base64");
                $sha256 = is_string($file['sha256'] ?? null) ? strtolower(trim($file['sha256'])) : '';
                if (!preg_match('~^[a-f0-9]{64}$~D', $sha256)) return self::err('bad_params', "$path: sha256 of the content is required");
                if (!hash_equals($sha256, hash('sha256', $bytes)))
                    return self::err('corrupt_upload', "$path: the bytes that arrived are not the bytes that were sent (sha256). Nothing was written", ['path' => $path]);
                if (strlen($bytes) > self::MAX_FILE_BYTES) return self::err('too_large', "$path is larger than " . self::MAX_FILE_BYTES . ' bytes. Nothing was written', ['path' => $path, 'max_file_bytes' => self::MAX_FILE_BYTES]);
                $total += strlen($bytes);
                $item['bytes'] = $bytes;
            }
            $items[] = $item;
        }
        if ($total > self::MAX_BYTES) return self::err('too_large', 'these files come to more than ' . self::MAX_BYTES . ' bytes; send them in several calls. Nothing was written', ['max_bytes' => self::MAX_BYTES]);

        // ── where they go ──
        $refused = [];
        foreach ($items as $k => $item) {
            $where = $this->check($item['path']);
            if (isset($where['reason'])) { $refused[] = ['path' => $item['path'], 'reason' => $where['reason']]; continue; }
            $items[$k]['abs'] = $where['abs'];
            $items[$k]['php'] = self::extension($item['path']) === 'php';
        }
        if (array_filter($refused, static fn($r) => $r['reason'] === 'php_disabled') !== [])
            $refusedNext = ['next' => 'PHP files are switched off on this site: its owner can allow them (Allow PHP in template files) and the write be sent again.'];
        if ($refused !== [])
            return self::err('refused', 'This door does not write ' . implode(', ', array_map(static fn($r) => $r['path'] . ' (' . $r['reason'] . ')', $refused)) . '. Nothing was written', ['refused' => $refused] + ($refusedNext ?? []));

        // ── what the site holds now (compare-and-set) ──
        $conflicts = [];
        foreach ($items as $k => $item) {
            try {
                $live = $this->state($item['abs']);
            } catch (Throwable $e) {
                return self::err('read_failed', $item['path'] . ': ' . $e->getMessage() . '. Nothing was written');
            }
            $items[$k]['live'] = $live;
            if ($live !== null && $live['bytes'] === null)
                return self::err('too_large', $item['path'] . ' on the site is larger than ' . self::MAX_FILE_BYTES . ' bytes: its undo copy cannot be kept. Nothing was written', ['path' => $item['path']]);
            if ($item['delete'] ? $live === null : ($live !== null && $live['bytes'] === $item['bytes'])) { $items[$k]['op'] = 'unchanged'; continue; }
            $actual = $live === null ? null : $live[$item['expectKind']];
            if ($actual !== $item['expected']) {
                $conflicts[] = ['path' => $item['path'], 'expected' => $item['expected'] === null ? null : [$item['expectKind'] => $item['expected']],
                    'actual' => $live === null ? null : ['sha1' => $live['sha1'], 'blob' => $live['blob']]];
                continue;
            }
            $items[$k]['op'] = $item['delete'] ? 'deleted' : ($live === null ? 'created' : 'updated');
        }
        if ($conflicts !== [])
            return self::err('conflict', count($conflicts) . ' file(s) are not what the caller expected on the site: ' . implode(', ', array_column($conflicts, 'path')) . '. Nothing was written',
                ['code' => 'CONFLICT', 'conflicts' => $conflicts]);

        // ── PHP: the site's own PHP must parse it ──
        $lint = null;
        $lintFailed = [];
        foreach ($items as $item) {
            if (!$item['php'] || $item['delete'] || $item['op'] === 'unchanged') continue;
            $result = $this->lint($item['bytes'], $item['path']);
            $lint = $result['mode'];
            if ($result['mode'] === null) return self::err('php_lint_unavailable', 'This site cannot check PHP before it is written (no PHP command line and no tokenizer): ' . $item['path'] . '. Nothing was written');
            if (!$result['ok']) $lintFailed[] = ['path' => $item['path'], 'message' => $result['message'], 'line' => $result['line']];
        }
        if ($lintFailed !== [])
            return self::err('php_lint_failed', 'PHP that does not parse on this site: ' . implode('; ', array_map(static fn($f) => $f['path'] . ': ' . $f['message'], $lintFailed)) . '. Nothing was written',
                ['lint' => $lintFailed, 'lint_mode' => $lint]);

        $answerFiles = array_map(static function (array $item): array {
            return ['path' => $item['path'], 'op' => $item['op'],
                'before' => $item['live'] === null ? null : self::shown($item['live']),
                'after' => $item['delete'] ? null : self::shown(self::hashes($item['bytes']))];
        }, $items);
        $changing = array_values(array_filter($items, static fn($item) => $item['op'] !== 'unchanged'));
        if ($dryRun) return ['ok' => true, 'dry_run' => true, 'apply_id' => $applyId, 'undo_id' => null, 'changed' => count($changing), 'files' => $answerFiles, 'lint' => $lint];
        if ($changing === []) return ['ok' => true, 'apply_id' => $applyId, 'undo_id' => null, 'changed' => 0, 'files' => $answerFiles, 'lint' => $lint, 'swept' => $swept];

        // ── the batch: every file to a temporary beside it, then each renamed into place ──
        $ops = array_map(static fn($item) => ['path' => $item['path'], 'abs' => $item['abs'], 'bytes' => $item['delete'] ? null : $item['bytes'],
            'old' => $item['live'] === null ? null : $item['live']['bytes'], 'php' => $item['php']], $changing);
        try {
            $done = $this->commit($ops);
        } catch (TemplateFilesFailed $e) {
            return self::err('write_failed', $e->getMessage() . ($e->left === [] ? '. Every file of this call was put back' : '. Could not put back: ' . implode(', ', $e->left)),
                ['rolled_back' => $e->left === [], 'not_restored' => $e->left]);
        }

        // ── the undo, recorded before the batch is final; a log that refuses takes the batch back ──
        $undoId = bin2hex(random_bytes(12));
        try {
            foreach ($done as $op)
                $log->record($applyId, ['op' => self::LOG_OP, 'undo_id' => $undoId, 'path' => $op['path'], 'before' => $op['old'],
                    'after' => $op['bytes'] === null ? null : sha1($op['bytes']), 'made' => $op['made']]);
        } catch (Throwable $e) {
            $left = $this->rollback($done);
            return self::err('write_failed', 'Could not record the undo of these files' . ($left === [] ? ', so every file of this call was put back' : '; could not put back: ' . implode(', ', $left)),
                ['rolled_back' => $left === [], 'not_restored' => $left]);
        }
        $this->finish($done);
        $this->tell(['action' => 'template.files.write', 'apply_id' => $applyId, 'undo_id' => $undoId,
            'files' => array_map(static fn($item) => ['path' => $item['path'], 'op' => $item['op']], $changing)]);
        return ['ok' => true, 'apply_id' => $applyId, 'undo_id' => $undoId, 'changed' => count($changing), 'files' => $answerFiles, 'lint' => $lint, 'swept' => $swept,
            'undo' => ['action' => 'template.files.undo', 'params' => ['apply_id' => $applyId, 'undo_id' => $undoId]]];
    }

    /**
     * `template.files.undo {apply_id, undo_id?}`: each file of that write (or of every write of the
     * Apply, newest first) back to what it was — only where the live file still holds what the
     * write left (`restored`), or already holds what was there before (`already`). Every other file
     * is `kept` and named with why: the site changed it since.
     */
    public function undo(array $p, string $applyId, ApplyLog $log): array
    {
        if (!$this->enabled) return self::disabled();
        $undoId = $p['undo_id'] ?? null;
        if ($undoId !== null && (!is_string($undoId) || !preg_match(self::UNDO_ID, $undoId))) return self::err('bad_params', 'undo_id is the 24 hex characters template.files.write answered');
        try {
            $entries = $log->entries($applyId);
        } catch (Throwable $e) {
            return self::err('revert_failed', $e->getMessage());
        }
        $groups = [];
        foreach ($entries as $entry) {
            if (($entry['op'] ?? '') !== self::LOG_OP || !is_string($entry['undo_id'] ?? null)) continue;
            if ($undoId !== null && $entry['undo_id'] !== $undoId) continue;
            $groups[$entry['undo_id']][] = $entry;
        }
        if ($groups === []) return self::err('not_found', 'No template file write is recorded under ' . ($undoId === null ? 'this apply_id' : 'this apply_id and undo_id'));

        $restored = []; $already = []; $kept = [];
        foreach (array_reverse($groups, true) as $id => $records) {
            $ops = [];
            foreach ($records as $record) {
                $plan = $this->revertPlan($record, null);
                if (isset($plan['kept'])) { $kept[] = ['path' => (string) ($record['path'] ?? ''), 'undo_id' => $id] + $plan['kept']; continue; }
                if ($plan['op'] === null) { $already[] = ['path' => $record['path'], 'undo_id' => $id]; continue; }
                $ops[] = $plan['op'] + ['undo_id' => $id];
            }
            if ($ops === []) continue;
            try {
                $done = $this->commit($ops);
            } catch (TemplateFilesFailed $e) {
                return self::err('write_failed', 'Undo ' . $id . ' could not land: ' . $e->getMessage() . ($e->left === [] ? '; its files were left as they were' : '; could not put back: ' . implode(', ', $e->left)),
                    ['restored' => $restored, 'already' => $already, 'kept' => $kept, 'not_restored' => $e->left]);
            }
            $this->finish($done);
            foreach ($done as $op) $restored[] = ['path' => $op['path'], 'op' => $op['bytes'] === null ? 'removed' : 'restored', 'undo_id' => $op['undo_id']];
        }
        if ($restored !== [])
            $this->tell(['action' => 'template.files.undo', 'apply_id' => $applyId, 'undo_id' => $undoId, 'files' => array_map(static fn($r) => ['path' => $r['path'], 'op' => $r['op']], $restored), 'kept' => count($kept)]);
        $complete = $kept === [];
        // Nothing of this Apply is left to take back: forget it, so its old bytes do not stay in the log.
        if ($complete && $undoId === null && array_filter($entries, static fn($e) => ($e['op'] ?? '') !== self::LOG_OP) === []) {
            try { $log->clear($applyId); } catch (Throwable $ignored) {}
        }
        return ['ok' => true, 'apply_id' => $applyId, 'undo_ids' => array_keys(array_reverse($groups, true)), 'complete' => $complete,
            'restored' => $restored, 'already' => $already, 'kept' => $kept];
    }

    // ── `apply.revert`: the same reverse compare-and-set, one step at a time ─────────────────

    /**
     * The template-file steps of an Apply that `apply.revert` could not put back, walked newest
     * first as the revert will run (a later step's restore is what an earlier one finds): each
     * `{path, reason}`, or [] when every one can go back.
     *
     * @param list<array<string,mixed>> $entries
     */
    public function revertBlocked(array $entries): array
    {
        $now = [];
        $blocked = [];
        foreach (array_reverse($entries) as $entry) {
            if (($entry['op'] ?? '') !== self::LOG_OP) continue;
            $plan = $this->revertPlan($entry, $now);
            $path = (string) ($entry['path'] ?? '');
            if (isset($plan['kept'])) { $blocked[] = ['path' => $path] + $plan['kept']; continue; }
            $before = $entry['before'] ?? null;
            $now[$path] = is_string($before) ? sha1($before) : null;
        }
        return $blocked;
    }

    /** Put back one recorded step for `apply.revert`; throws when the live file is no longer what the step left. */
    public function revertEntry(array $entry): void
    {
        $plan = $this->revertPlan($entry, null);
        if (isset($plan['kept'])) throw new RuntimeException(((string) ($entry['path'] ?? '')) . ' was not put back: ' . $plan['kept']['reason']);
        if ($plan['op'] === null) return;
        try {
            $this->finish($this->commit([$plan['op']]));
        } catch (TemplateFilesFailed $e) {
            throw new RuntimeException($e->getMessage());
        }
    }

    /**
     * What taking one step back means now: `['op' => null]` when the file already holds what it held
     * before, `['op' => op]` to put it back, or `['kept' => {reason, sha1?}]`. `$simulated` maps a path to
     * the sha1 it will hold (null: absent) after the newer steps of the same walk.
     *
     * @param array<string,?string>|null $simulated
     */
    private function revertPlan(array $record, ?array $simulated): array
    {
        $path = is_string($record['path'] ?? null) ? $record['path'] : '';
        $before = $record['before'] ?? null;
        $after = $record['after'] ?? null;
        if (($before !== null && !is_string($before)) || ($after !== null && (!is_string($after) || !preg_match(self::HASH, $after))))
            return ['kept' => ['reason' => 'unreadable-undo-step']];
        if (!$this->enabled) return ['kept' => ['reason' => 'disabled']];
        // An undo row is data: the path is checked again, as if it were asked for now (PHP switched off included).
        $where = $this->check($path);
        if (isset($where['reason'])) return ['kept' => ['reason' => 'out-of-scope', 'detail' => $where['reason']]];
        if ($simulated !== null && array_key_exists($path, $simulated)) {
            $liveSha = $simulated[$path];
            $live = null;
        } else {
            try {
                $live = $this->state($where['abs']);
            } catch (Throwable $e) {
                return ['kept' => ['reason' => 'unreadable']];
            }
            $liveSha = $live === null ? null : $live['sha1'];
        }
        $beforeSha = $before === null ? null : sha1($before);
        if ($liveSha === $beforeSha) return ['op' => null];
        if ($liveSha !== $after)
            return ['kept' => ['reason' => $liveSha === null ? 'gone-on-live' : ($after === null ? 'exists-on-live' : 'changed-on-live'), 'sha1' => $liveSha]];
        $made = is_array($record['made'] ?? null) ? array_values(array_filter($record['made'], 'is_string')) : [];
        return ['op' => ['path' => $path, 'abs' => $where['abs'], 'bytes' => $before, 'old' => $live === null ? null : $live['bytes'],
            'php' => self::extension($path) === 'php', 'remove_dirs' => $before === null ? $made : []]];
    }

    // ── where a path may go ───────────────────────────────────────────────────────────────────

    /**
     * The roots as they stand now: each a real directory under the site root, reached through no
     * link, by its site-relative path.
     *
     * @return array<string,array{path:string,kind:string,name:string,php:bool}>
     */
    public function roots(): array
    {
        if ($this->resolved !== null) return $this->resolved;
        $this->resolved = [];
        $site = realpath($this->root);
        if ($site === false) return $this->resolved;
        foreach ((array) ($this->roots)() as $root) {
            if (!is_array($root) || !is_string($root['path'] ?? null)) continue;
            $path = trim($root['path'], '/');
            if ($path === '' || self::segmentsOf($path) === null || $this->linkOnWay($path)) continue;
            $abs = $this->root . '/' . $path;
            if (!is_dir($abs) || realpath($abs) !== $site . '/' . $path) continue;
            $this->resolved[$path] = ['path' => $path, 'kind' => (string) ($root['kind'] ?? 'template'), 'name' => (string) ($root['name'] ?? basename($path)), 'php' => (bool) ($root['php'] ?? false)];
        }
        return $this->resolved;
    }

    /**
     * Where one path lands, or why it may not: `['abs', 'root']`, or `['reason']`, one of
     * bad_path, absolute, traversal, dotfile, denied, executable, extension, not_in_scope,
     * php_not_allowed, php_disabled, symlink, not_a_file.
     */
    public function check($path): array
    {
        if (!is_string($path) || $path === '' || strlen($path) > self::MAX_PATH) return ['reason' => 'bad_path'];
        if ($path[0] === '/' || preg_match('~^[A-Za-z]:~', $path)) return ['reason' => 'absolute'];
        if (strpos($path, '\\') !== false || preg_match('/[\x00-\x1F\x7F]/', $path) || preg_match('//u', $path) !== 1) return ['reason' => 'bad_path'];
        $segments = explode('/', $path);
        foreach ($segments as $segment) if ($segment === '.' || $segment === '..') return ['reason' => 'traversal'];
        foreach ($segments as $segment) if ($segment === '') return ['reason' => 'bad_path'];
        foreach ($segments as $segment) if ($segment[0] === '.') return ['reason' => 'dotfile'];
        if (self::segmentsOf($path) === null || count($segments) > self::MAX_DEPTH) return ['reason' => 'bad_path'];
        $name = strtolower((string) end($segments));
        if (in_array($name, self::DENIED_NAMES, true)) return ['reason' => 'denied'];
        if (preg_match(self::RUNNABLE_INNER, $name)) return ['reason' => 'executable'];
        $extension = self::extension($path);
        if ($extension === '' || !in_array($extension, self::EXTENSIONS, true)) return ['reason' => 'extension'];
        $root = null;
        foreach ($this->roots() as $candidate)
            if (strpos($path, $candidate['path'] . '/') === 0 && ($root === null || strlen($candidate['path']) > strlen($root['path']))) $root = $candidate;
        if ($root === null) return ['reason' => 'not_in_scope'];
        if ($extension === 'php' && !$root['php']) return ['reason' => 'php_not_allowed'];
        if ($extension === 'php' && !$this->phpAllowed) return ['reason' => 'php_disabled'];
        if ($this->linkOnWay($path)) return ['reason' => 'symlink'];
        $abs = $this->root . '/' . $path;
        if (file_exists($abs) && !is_file($abs)) return ['reason' => 'not_a_file'];
        // The nearest folder that exists must be where the path says, under the root's real folder.
        $site = (string) realpath($this->root);
        $folder = dirname($path);
        while ($folder !== $root['path'] && !is_dir($this->root . '/' . $folder)) $folder = dirname($folder);
        if (realpath($this->root . '/' . $folder) !== $site . '/' . $folder) return ['reason' => 'symlink'];
        return ['abs' => $abs, 'root' => $root];
    }

    /** Whether any part of a site-relative path, from its first segment to its leaf, is a symbolic link. */
    private function linkOnWay(string $path): bool
    {
        $at = $this->root;
        foreach (explode('/', $path) as $segment) {
            $at .= '/' . $segment;
            clearstatcache(true, $at);
            if (is_link($at)) return true;
            if (!file_exists($at)) return false;
        }
        return false;
    }

    /** @return list<string>|null the segments of a clean relative path, or null */
    private static function segmentsOf(string $path): ?array
    {
        $segments = explode('/', $path);
        foreach ($segments as $segment) if (!preg_match(self::SEGMENT, $segment)) return null;
        return $segments;
    }

    private static function extension(string $path): string
    {
        $name = basename($path);
        $dot = strrpos($name, '.');
        return $dot === false ? '' : strtolower(substr($name, $dot + 1));
    }

    // ── what a file holds ─────────────────────────────────────────────────────────────────────

    /**
     * The file at an absolute path: null when absent; its bytes (null past the size cap) and hashes.
     *
     * @return array{bytes:?string,size:int,sha1:string,blob:string,sha256:string}|null
     */
    private function state(string $abs): ?array
    {
        clearstatcache(true, $abs);
        if (!file_exists($abs) && !is_link($abs)) return null;
        if (is_link($abs) || !is_file($abs)) throw new RuntimeException('not a regular file');
        $size = (int) filesize($abs);
        if ($size > self::MAX_FILE_BYTES) {
            $blob = hash_init('sha1');
            hash_update($blob, 'blob ' . $size . "\0");
            hash_update_file($blob, $abs);
            return ['bytes' => null, 'size' => $size, 'sha1' => (string) sha1_file($abs), 'blob' => hash_final($blob), 'sha256' => (string) hash_file('sha256', $abs)];
        }
        $bytes = @file_get_contents($abs);
        if ($bytes === false) throw new RuntimeException('cannot be read');
        return self::hashes($bytes);
    }

    /** @return array{bytes:string,size:int,sha1:string,blob:string,sha256:string} */
    private static function hashes(string $bytes): array
    {
        return ['bytes' => $bytes, 'size' => strlen($bytes), 'sha1' => sha1($bytes), 'blob' => sha1('blob ' . strlen($bytes) . "\0" . $bytes), 'sha256' => hash('sha256', $bytes)];
    }

    /** A state as answered: hashes and size, never the bytes. */
    private static function shown(array $state): array
    {
        return ['sha1' => $state['sha1'], 'blob' => $state['blob'], 'sha256' => $state['sha256'], 'bytes' => $state['size']];
    }

    // ── the batch ─────────────────────────────────────────────────────────────────────────────

    /**
     * Land a batch: each new content to a temporary file in its own folder (folders made where
     * missing), then every move in order — a temporary renamed over its file, or a file to remove
     * renamed aside (parked). A failure anywhere puts back every move already made, removes the
     * temporaries and the folders made, and throws. Answers the moves, for {@see finish()} or
     * {@see rollback()}.
     *
     * @param list<array<string,mixed>> $ops each {path, abs, bytes: ?string (null: remove), old: ?string, php}
     * @return list<array<string,mixed>>
     */
    private function commit(array $ops): array
    {
        $staged = [];
        $done = [];
        try {
            foreach ($ops as $i => $op) {
                $ops[$i]['made'] = [];
                if ($op['bytes'] === null) continue;
                $ops[$i]['made'] = $this->makeFolders((string) $op['path']);
                $staged[$i] = $this->stage(dirname((string) $op['abs']), (string) $op['bytes'], is_file((string) $op['abs']) ? (int) (fileperms((string) $op['abs']) & 0777) : 0644);
            }
            foreach ($ops as $i => $op) {
                if ($op['bytes'] !== null) {
                    $this->move($staged[$i], (string) $op['abs']);
                    unset($staged[$i]);
                } else {
                    $op['park'] = dirname((string) $op['abs']) . '/' . self::TEMP_PREFIX . bin2hex(random_bytes(8)) . '.del';
                    $this->move((string) $op['abs'], $op['park']);
                }
                $done[] = $op;
            }
        } catch (Throwable $e) {
            $left = $this->rollback($done);
            foreach ($staged as $temp) @unlink($temp);
            foreach (array_reverse($ops) as $op) foreach (array_reverse($op['made'] ?? []) as $folder) @rmdir($this->root . '/' . $folder);
            throw new TemplateFilesFailed($e->getMessage(), $left);
        }
        return $done;
    }

    /** After a batch is recorded: the parked files go, emptied folders go, PHP's cache forgets the old code, the host empties its caches. */
    private function finish(array $done): void
    {
        foreach ($done as $op) {
            if (isset($op['park'])) @unlink($op['park']);
            foreach (array_reverse($op['remove_dirs'] ?? []) as $folder)
                if (is_string($folder) && self::segmentsOf($folder) !== null && strpos((string) $op['path'], $folder . '/') === 0
                    && !$this->linkOnWay($folder) && is_dir($this->root . '/' . $folder)) @rmdir($this->root . '/' . $folder);
            if (!empty($op['php']) && function_exists('opcache_invalidate')) @opcache_invalidate((string) $op['abs'], true);
        }
        if ($this->onChange !== null && $done !== []) {
            try { ($this->onChange)(array_map(static fn($op) => (string) $op['path'], $done)); } catch (Throwable $ignored) {}
        }
    }

    /**
     * Take back moves already made, newest first: the old bytes over a replaced file, a new file
     * removed, a parked file renamed back. Answers the paths it could not put back.
     *
     * @param list<array<string,mixed>> $done
     * @return list<string>
     */
    private function rollback(array $done): array
    {
        $left = [];
        foreach (array_reverse($done) as $op) {
            $abs = (string) $op['abs'];
            try {
                if (isset($op['park'])) {
                    $this->move($op['park'], $abs);
                } elseif ($op['old'] === null) {
                    if (file_exists($abs) && !@unlink($abs)) throw new RuntimeException('cannot remove');
                    foreach (array_reverse($op['made'] ?? []) as $folder) @rmdir($this->root . '/' . $folder);
                } else {
                    $this->move($this->stage(dirname($abs), (string) $op['old'], (int) (fileperms($abs) & 0777)), $abs);
                }
                if (!empty($op['php']) && function_exists('opcache_invalidate')) @opcache_invalidate($abs, true);
            } catch (Throwable $e) {
                $left[] = (string) $op['path'];
            }
        }
        return $left;
    }

    /** The folders of a path that are missing, made outermost first; answers them, site-relative. */
    private function makeFolders(string $path): array
    {
        $missing = [];
        $folder = dirname($path);
        while ($folder !== '.' && !is_dir($this->root . '/' . $folder)) { $missing[] = $folder; $folder = dirname($folder); }
        $made = [];
        foreach (array_reverse($missing) as $folder) {
            if (!@mkdir($this->root . '/' . $folder, 0755)) {
                foreach (array_reverse($made) as $undo) @rmdir($this->root . '/' . $undo);
                throw new RuntimeException('Could not create ' . $folder . ': the web server cannot write there');
            }
            $made[] = $folder;
        }
        return $made;
    }

    /** Bytes to a new temporary file in a folder (created exclusively, so never through a link); answers its path. */
    private function stage(string $folder, string $bytes, int $mode): string
    {
        $temp = $folder . '/' . self::TEMP_PREFIX . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($temp, 'xb');
        if ($handle === false) throw new RuntimeException('Could not write in ' . $this->relative($folder) . ': the web server cannot write there');
        $written = @fwrite($handle, $bytes);
        @fflush($handle);
        @fclose($handle);
        clearstatcache(true, $temp);
        if ($written !== strlen($bytes) || filesize($temp) !== strlen($bytes)) {
            @unlink($temp);
            throw new RuntimeException('Could not write in ' . $this->relative($folder) . ': the disk took ' . (int) $written . ' of ' . strlen($bytes) . ' bytes');
        }
        @chmod($temp, $mode ?: 0644);
        return $temp;
    }

    private function move(string $from, string $to): void
    {
        if (!($this->rename)($from, $to)) throw new RuntimeException('Could not move a file into place at ' . $this->relative($to));
    }

    private function relative(string $abs): string
    {
        return strpos($abs, $this->root . '/') === 0 ? substr($abs, strlen($this->root) + 1) : basename($abs);
    }

    // ── PHP lint ──────────────────────────────────────────────────────────────────────────────

    /**
     * How PHP is checked here: 'cli' (`php -l` of this PHP's own version), 'tokens' (the parser in
     * this process), or null. With `$run` false (what `info` and `status` answer) nothing is started:
     * 'cli' then means a PHP command line is there to be asked, and a write that finds it of another
     * version checks with the parser instead (its answer's `lint` says which ran).
     */
    private function lintModeNow(bool $run = true): ?string
    {
        if ($this->lintMode !== null) return $this->lintMode === 'none' ? null : $this->lintMode;
        if ($run ? $this->cliBinary() !== null : $this->cliCandidates() !== []) return 'cli';
        return function_exists('token_get_all') && defined('TOKEN_PARSE') ? 'tokens' : null;
    }

    /**
     * Whether PHP source parses on this site's PHP: `php -l` run by the PHP command line of the same
     * major.minor version as the PHP serving the site when the host lets PHP start a process; else
     * this PHP's own parser (`token_get_all` with TOKEN_PARSE, which throws ParseError).
     *
     * @return array{ok:bool,mode:?string,message:string,line:?int}
     */
    public function lint(string $source, string $path): array
    {
        $mode = $this->lintModeNow();
        if ($mode === 'cli') {
            $binary = $this->cliBinary();
            $temp = $binary === null ? false : @tempnam(sys_get_temp_dir(), 'tracy-lint-');
            if ($temp !== false && @file_put_contents($temp, $source) === strlen($source)) {
                $run = self::run([$binary, '-n', '-d', 'short_open_tag=' . (ini_get('short_open_tag') ? '1' : '0'), '-d', 'display_errors=1', '-d', 'log_errors=0', '-l', $temp]);
                @unlink($temp);
                if ($run !== null) {
                    if ($run[0] === 0) return ['ok' => true, 'mode' => 'cli', 'message' => '', 'line' => null];
                    $text = trim(str_replace($temp, $path, $run[1]));
                    $text = trim(preg_replace('~\s*Errors parsing .*$~s', '', $text) ?? $text);
                    return ['ok' => false, 'mode' => 'cli', 'message' => substr($text, 0, 500), 'line' => preg_match('~on line (\d+)~', $text, $m) ? (int) $m[1] : null];
                }
            }
            if ($temp !== false) @unlink($temp);
            $mode = function_exists('token_get_all') && defined('TOKEN_PARSE') ? 'tokens' : null;
        }
        if ($mode === 'tokens') {
            try {
                token_get_all($source, TOKEN_PARSE);
                return ['ok' => true, 'mode' => 'tokens', 'message' => '', 'line' => null];
            } catch (Throwable $e) {
                return ['ok' => false, 'mode' => 'tokens', 'message' => $e->getMessage() . ' on line ' . $e->getLine(), 'line' => $e->getLine()];
            }
        }
        return ['ok' => false, 'mode' => null, 'message' => 'no way to check PHP on this site', 'line' => null];
    }

    /** The PHP command line of this PHP's major.minor, when one can be run; null otherwise. */
    private function cliBinary(): ?string
    {
        if ($this->cli !== null) return $this->cli === '' ? null : $this->cli;
        $this->cli = '';
        $want = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        foreach ($this->cliCandidates() as $binary) {
            $run = self::run([$binary, '-n', '-r', 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;']);
            if ($run !== null && $run[0] === 0 && trim($run[1]) === $want) return $this->cli = $binary;
        }
        return null;
    }

    /** The PHP command lines that may be this PHP's, when PHP may start a process here; none is run. */
    private function cliCandidates(): array
    {
        if ($this->lintMode === 'tokens' || $this->lintMode === 'none') return [];
        if (!function_exists('proc_open') || in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) return [];
        $candidates = [];
        // PHP_BINARY names php-fpm or the web server under FPM or mod_php; only a `php` command line lints.
        if (PHP_BINARY !== '' && preg_match('~(^|/)php(\d+(\.\d+)?)?$~', PHP_BINARY)) $candidates[] = PHP_BINARY;
        $candidates[] = PHP_BINDIR . '/php';
        $candidates[] = PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        return array_values(array_filter(array_unique($candidates), static fn($binary) => @is_file($binary) && @is_executable($binary)));
    }

    /** @param list<string> $command @return array{0:int,1:string}|null exit code and output, or null when it could not start */
    private static function run(array $command): ?array
    {
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($process)) return null;
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($process), $output];
    }

    // ── leftovers ─────────────────────────────────────────────────────────────────────────────

    /**
     * Remove what a request that died left behind: files named exactly as this door names its
     * temporaries and parked files ({@see TEMP_NAME}), older than an hour, inside the roots. The walk
     * never follows a link and never enters a dot-folder; a link with such a name is left alone.
     * Answers how many went.
     */
    private function sweep(): int
    {
        $swept = 0;
        $before = time() - self::STALE_SECONDS;
        foreach ($this->roots() as $root) {
            try {
                $walk = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($this->root . '/' . $root['path'], FilesystemIterator::SKIP_DOTS),
                    static function (SplFileInfo $item): bool {
                        if ($item->isLink()) return false;
                        return $item->isDir() ? $item->getFilename()[0] !== '.' : (bool) preg_match(self::TEMP_NAME, $item->getFilename());
                    }));
                foreach ($walk as $item) {
                    if ($item->isLink() || !$item->isFile() || $item->getMTime() >= $before) continue;
                    if (@unlink($item->getPathname())) $swept++;
                }
            } catch (Throwable $ignored) {
                // A folder that cannot be read keeps its leftovers; the write itself is not refused for it.
            }
        }
        return $swept;
    }

    // ── telling ───────────────────────────────────────────────────────────────────────────────

    private function tell(array $event): void
    {
        if ($this->audit === null) return;
        try { ($this->audit)($event + ['at' => gmdate('Y-m-d\TH:i:s\Z')]); } catch (Throwable $ignored) {}
    }

    private static function disabled(): array
    {
        return self::err('disabled', 'Template files are switched off on this site: its owner can switch them on (Template files) in the Claude Cowork settings');
    }

    private static function err(string $code, string $message, array $extra = []): array
    {
        return array_merge(['ok' => false, 'error' => $code, 'message' => $message], $extra);
    }
}
