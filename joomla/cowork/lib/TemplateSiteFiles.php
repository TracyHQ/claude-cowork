<?php
/**
 * TemplateSiteFiles — the disk side of `template.siteSettings`: which framework a template runs on,
 * which site profiles its styles use and where each is read from, the files a set would write, the
 * write itself with what it takes to undo it, the undo, and emptying T4's optimize cache.
 *
 * Every file it writes is one {@see TemplateSiteSettings::isDoorFile()} accepts, under a site root
 * it was given; every folder it creates on the way is recorded, so an undo leaves `local/` exactly
 * as absent as it was. The rules of keys and values are {@see TemplateSiteSettings}.
 */
final class TemplateSiteFiles
{
    private string $root;
    /** @var callable(string): list<?string> each site style's `typelist-site` for a template */
    private $styleProfiles;

    /**
     * @param string $root the site root (JPATH_ROOT)
     * @param callable(string): list<?string> $styleProfiles the `typelist-site` param of every site
     *        style of a template, one entry per style; null or empty is T4's own default
     */
    public function __construct(string $root, callable $styleProfiles)
    {
        $this->root = rtrim($root, '/');
        $this->styleProfiles = $styleProfiles;
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * 't4', 't3' or 'joomla' (any other template), from the template's own manifest; null when no
     * site template of that name is installed.
     */
    public function framework(string $template): ?string
    {
        $manifest = $this->manifest($template);
        if ($manifest === null) return null;
        if (preg_match('~<t4\b~', $manifest)) return 't4';
        return preg_match('~<t3\b~', $manifest) ? 't3' : 'joomla';
    }

    /**
     * What the template holds now. T4: each profile a style uses, where it is read from and its
     * whitelisted keys; `missing`, the profiles a style names that no file holds (T4 shows `default`
     * for those). Any other template: its favicon setting.
     */
    public function read(string $template, string $framework): array
    {
        if ($framework !== 't4') {
            $current = $this->bytes($this->faviconPath($template));
            $settings = $current === null ? [] : (TemplateSiteSettings::settingsOf($current, TemplateSiteSettings::OTHER_KEYS) ?? []);
            return ['settings' => ['other_faviconFile' => $settings['other_faviconFile'] ?? '']];
        }
        $profiles = [];
        $missing = [];
        foreach ($this->usedProfiles($template) as $profile) {
            $source = $this->source($template, $profile);
            if ($source === null) { $missing[] = $profile; continue; }
            $profiles[$profile] = ['source' => $source, 'settings' => TemplateSiteSettings::settingsOf((string) $this->bytes($source), TemplateSiteSettings::T4_KEYS) ?? []];
        }
        return ['profiles' => $profiles, 'missing' => $missing];
    }

    /**
     * The files a set would write: for a T4 template, every profile a style uses when `$fields` is
     * given, plus each profile `$byProfile` names (its values over `$fields`); for any other template,
     * the favicon setting. A file whose bytes would not change is left out. Values are clean already.
     *
     * @param array<string,string> $fields
     * @param array<string,array<string,string>> $byProfile
     * @return array{changes:list<array{path:string,before:?string,after:string}>,skipped:list<array{profile:string,reason:string}>}|array{error:string,message:string}
     */
    public function plan(string $template, string $framework, array $fields, array $byProfile): array
    {
        if ($framework !== 't4') {
            $path = $this->faviconPath($template);
            $before = $this->bytes($path);
            if ($before === null && ($fields['other_faviconFile'] ?? '') === '') return ['changes' => [], 'skipped' => []];
            $after = TemplateSiteSettings::withValues($before ?? '{}', $fields);
            if ($after === null) return ['error' => 'read_failed', 'message' => $path . ' is not a JSON object; nothing was written'];
            return ['changes' => $after === $before ? [] : [['path' => $path, 'before' => $before, 'after' => $after]], 'skipped' => []];
        }
        $used = $this->usedProfiles($template);
        $targets = $fields === [] ? [] : $used;
        foreach (array_keys($byProfile) as $profile) {
            $profile = (string) $profile;
            if (!preg_match(TemplateSiteSettings::PROFILE, $profile) || ($this->source($template, $profile) === null))
                return ['error' => 'bad_params', 'message' => 'Profile ' . substr($profile, 0, 60) . ' has no etc/site file in template ' . $template . '; nothing was written'];
            if (!in_array($profile, $targets, true)) $targets[] = $profile;
        }
        $changes = [];
        $skipped = [];
        foreach ($targets as $profile) {
            $source = $this->source($template, $profile);
            if ($source === null) { $skipped[] = ['profile' => $profile, 'reason' => 'no profile file: its styles show the default profile']; continue; }
            $path = $this->localPath($template, $profile);
            $before = $this->bytes($path);
            $from = $before ?? (string) $this->bytes($source);
            $after = TemplateSiteSettings::withValues($from, array_merge($fields, $byProfile[$profile] ?? []));
            if ($after === null) return ['error' => 'read_failed', 'message' => $source . ' is not a JSON object; nothing was written'];
            if ($after !== $from) $changes[] = ['path' => $path, 'before' => $before, 'after' => $after];
        }
        return ['changes' => $changes, 'skipped' => $skipped];
    }

    /**
     * Write the planned files, creating `local/etc/site/` where it is missing. Answers what undoes
     * them: per file its previous bytes (null: it did not exist) and the folders made for it. A
     * write that fails puts back what this call already wrote, then throws.
     *
     * @param list<array{path:string,before:?string,after:string}> $changes
     * @return list<array{path:string,before:?string,made:list<string>}>
     */
    public function write(array $changes): array
    {
        $done = [];
        try {
            foreach ($changes as $change) {
                $path = $change['path'];
                $this->assertDoorFile($path);
                $made = [];
                $folder = dirname($path);
                $missing = [];
                while (!is_dir($this->root . '/' . $folder) && preg_match('~^templates/[A-Za-z0-9_-]+/local~', $folder)) { $missing[] = $folder; $folder = dirname($folder); }
                foreach (array_reverse($missing) as $folder) {
                    if (!@mkdir($this->root . '/' . $folder, 0755)) throw new RuntimeException('Could not create ' . $folder . ': the web server cannot write the template folder');
                    array_unshift($made, $folder);
                }
                $file = $this->root . '/' . $path;
                $entry = ['path' => $path, 'before' => $change['before'], 'made' => $made];
                if (@file_put_contents($file, $change['after'], LOCK_EX) !== strlen($change['after'])) {
                    $done[] = $entry;
                    throw new RuntimeException('Could not write ' . $path . ': the web server cannot write the template folder');
                }
                $done[] = $entry;
            }
        } catch (Throwable $e) {
            try { $this->restore($done); } catch (Throwable $ignored) {}
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }
        return $done;
    }

    /**
     * Undo what {@see write()} recorded, newest first: previous bytes back, or the file deleted and
     * the folders made for it removed when empty. An undo row is data: a path that is not one of the
     * door's files, or a folder that is not on its way, is refused and nothing of that row written.
     *
     * @param list<array<string,mixed>> $files
     */
    public function restore(array $files): void
    {
        foreach (array_reverse($files) as $entry) {
            $path = is_array($entry) && is_string($entry['path'] ?? null) ? $entry['path'] : '';
            $this->assertDoorFile($path);
            $before = $entry['before'] ?? null;
            if ($before !== null && !is_string($before)) throw new RuntimeException('Unreadable undo step for ' . $path);
            $made = is_array($entry['made'] ?? null) ? $entry['made'] : [];
            foreach ($made as $folder)
                if (!is_string($folder) || !preg_match('~^templates/[A-Za-z0-9_-]+/local(/etc(/site)?)?$~D', $folder) || strpos($path, $folder . '/') !== 0)
                    throw new RuntimeException('Unreadable undo step for ' . $path);
            $file = $this->root . '/' . $path;
            if ($before === null) {
                if (is_file($file) && !@unlink($file)) throw new RuntimeException('Could not delete ' . $path);
                foreach ($made as $folder) if (is_dir($this->root . '/' . $folder) && !is_link($this->root . '/' . $folder)) @rmdir($this->root . '/' . $folder);
                continue;
            }
            if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0755, true)) throw new RuntimeException('Could not create the folder of ' . $path);
            if (@file_put_contents($file, $before, LOCK_EX) !== strlen($before)) throw new RuntimeException('Could not write ' . $path);
        }
    }

    /**
     * Empty T4's optimize cache (`media/t4/optimize`): combined CSS that may carry a logo
     * (`content:url(…)` in a dark-mode rule) is rebuilt on the next page. Only that folder, only a
     * real directory, never through a link; best-effort. Answers how many files went.
     */
    public function clearOptimize(): int
    {
        $cache = $this->root . '/media/t4/optimize';
        if (is_link($cache) || !is_dir($cache)) return 0;
        $real = realpath($cache);
        if ($real === false || $real !== (realpath($this->root) ?: '') . '/media/t4/optimize') return 0;
        $removed = 0;
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname());
            elseif (@unlink($item->getPathname())) $removed++;
        }
        return $removed;
    }

    /** The template's manifest text, or null when no site template of that name is installed. */
    private function manifest(string $template): ?string
    {
        if (!preg_match(TemplateSiteSettings::TEMPLATE, $template)) return null;
        $file = $this->root . '/templates/' . $template . '/templateDetails.xml';
        if (!is_file($file) || filesize($file) > 1048576) return null;
        $text = @file_get_contents($file);
        return $text === false ? null : $text;
    }

    /** Each distinct profile the template's site styles name, `default` first and always present. */
    private function usedProfiles(string $template): array
    {
        $out = ['default'];
        foreach (($this->styleProfiles)($template) as $profile) {
            $profile = is_string($profile) && trim($profile) !== '' ? trim($profile) : 'default';
            if (preg_match(TemplateSiteSettings::PROFILE, $profile) && !in_array($profile, $out, true)) $out[] = $profile;
        }
        return $out;
    }

    /** Where T4 reads a profile from, site-relative: the local copy, the template's, the base theme's. */
    private function source(string $template, string $profile): ?string
    {
        $base = preg_match('~<basetheme>\s*([A-Za-z0-9_-]+)\s*</basetheme>~', (string) $this->manifest($template), $m) ? $m[1] : 'base';
        foreach ([$this->localPath($template, $profile), 'templates/' . $template . '/etc/site/' . $profile . '.json',
            'plugins/system/t4/themes/' . $base . '/etc/site/' . $profile . '.json'] as $candidate)
            if (is_file($this->root . '/' . $candidate) && !is_link($this->root . '/' . $candidate)) return $candidate;
        return null;
    }

    private function localPath(string $template, string $profile): string
    {
        return 'templates/' . $template . '/local/etc/site/' . $profile . '.json';
    }

    private function faviconPath(string $template): string
    {
        return 'templates/' . $template . '/local/etc/site/' . TemplateSiteSettings::FAVICON_FILE;
    }

    /** A site-relative file's bytes, or null when it is not a plain file. */
    private function bytes(string $relative): ?string
    {
        $file = $this->root . '/' . $relative;
        if (!is_file($file) || is_link($file)) return null;
        $bytes = @file_get_contents($file);
        return $bytes === false ? null : $bytes;
    }

    /** Refuse any path that is not one of the door's files, or that reaches one through a link. */
    private function assertDoorFile(string $path): void
    {
        if (!TemplateSiteSettings::isDoorFile($path)) throw new RuntimeException('Not a template site settings file: ' . substr($path, 0, 120));
        $parts = explode('/', $path);
        for ($i = 2; $i <= count($parts); $i++)
            if (is_link($this->root . '/' . implode('/', array_slice($parts, 0, $i)))) throw new RuntimeException('Refusing to write through a link: ' . $path);
    }
}
