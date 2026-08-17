<?php
/**
 * TarStream — tar (USTAR) bytes produced as they flow, with no temporary file.
 *
 * Written by hand rather than using PharData for two reasons: PharData packs from files already
 * on disk, and `phar.readonly` is on by default across shared hosting, which rules it out before
 * anything else is considered. USTAR itself is small enough to be worth owning — a 512-byte
 * header, then the content padded to a multiple of 512 — and owning it buys the thing that
 * matters: the archive can be streamed, instead of having to exist in full before any of it moves.
 *
 * Why no temporary file: the usual approach writes the whole archive onto the customer's own
 * disk before sending it, which on a host with a quota is a way to take the site down. Here the
 * bytes go out as they are produced, and memory holds one buffer rather than one archive.
 *
 * No Joomla dependency, so it can be tested on its own (see tests/run.php).
 */

final class TarStream
{
    private const BLOCK = 512;

    /**
     * One tar block, which is also exactly the size of one header.
     *
     * Public because the caller has to reserve room for a header before deciding to write one —
     * see the boundary check in Engine::filesPack. Everything else about the format stays in
     * here.
     */
    public const BLOCK_BYTES = self::BLOCK;

    /**
     * The header for an ordinary file — one block, or three when the path will not fit USTAR.
     *
     * Callers must not assume 512 bytes. `Engine::filesPack` measures what comes back rather than
     * counting blocks, because a long path arrives as a PAX header, its record, then the ordinary
     * header (see `entry`).
     */
    public static function fileHeader(string $path, int $size, int $mode = 0644, int $mtime = 0): string
    {
        return self::entry($path, $size, $mode, $mtime, '0');
    }

    /** The header for a directory. */
    public static function dirHeader(string $path, int $mode = 0755, int $mtime = 0): string
    {
        $path = rtrim($path, '/') . '/';
        return self::entry($path, 0, $mode, $mtime, '5');
    }

    /**
     * One entry's header bytes, reaching for PAX only when USTAR cannot hold the path.
     *
     * USTAR splits a long path at a slash: up to 155 bytes of leading directories go in `prefix`,
     * up to 100 in `name`. That covers deep trees but not long FILENAMES, because the last segment
     * has nowhere to be split. Real sites hit this — a webpack chunk shipped by Elementor Pro is
     * 101 characters on its own, and the whole run died on it (juneflower.vn, 2026-08-14).
     *
     * PAX (POSIX.1-2001) answers exactly that: an extra entry carrying the true path as a record,
     * read by every tar that matters — bsdtar, GNU tar, Python, Node. The USTAR header that follows
     * keeps a truncated name for readers that ignore PAX, so the worst case is an ugly name rather
     * than a broken archive.
     *
     * @param string $typeflag '0' for an ordinary file, '5' for a directory
     */
    private static function entry(string $path, int $size, int $mode, int $mtime, string $typeflag): string
    {
        $split = self::ustarSplit($path);
        if ($split !== null) {
            return self::header($split[0], $split[1], $size, $mode, $mtime, $typeflag);
        }

        // "%d %s=%s\n", where the number counts itself — so it is solved for rather than measured.
        $record = self::paxRecord('path', $path);
        $paxName = 'PaxHeaders/' . substr(basename($path), 0, 80);
        $paxSplit = self::ustarSplit($paxName) ?? [substr($paxName, 0, 100), ''];

        $pax = self::header($paxSplit[0], $paxSplit[1], strlen($record), 0644, $mtime, 'x')
            . $record
            . self::pad(strlen($record));

        // The fallback name is only for readers that skip the PAX entry: keep the tail, which is
        // the part a person recognises, and let PAX carry the truth.
        $fallback = substr($path, -100);
        return $pax . self::header($fallback, '', $size, $mode, $mtime, $typeflag);
    }

    /**
     * `[name, prefix]` when the path fits USTAR's two fields, or null when it does not.
     */
    private static function ustarSplit(string $path): ?array
    {
        if (strlen($path) <= 100) {
            return [$path, ''];
        }
        $cut = strrpos(substr($path, 0, 156), '/');
        if ($cut === false || $cut > 155) {
            return null;
        }
        $name = substr($path, $cut + 1);
        return strlen($name) > 100 ? null : [$name, substr($path, 0, $cut)];
    }

    /** One PAX record: a length that counts itself, then `key=value`, then a newline. */
    private static function paxRecord(string $key, string $value): string
    {
        $withoutLength = ' ' . $key . '=' . $value . "\n";
        $length = strlen($withoutLength) + 1;
        // Adding the digit can push the total across a power of ten, which needs another digit.
        while (strlen((string) $length) + strlen($withoutLength) > $length) {
            $length++;
        }
        return $length . $withoutLength;
    }

    private static function header(string $name, string $prefix, int $size, int $mode, int $mtime, string $typeflag): string
    {
        $header = pack('a100', $name)
            . pack('a8', sprintf("%07o", $mode & 0777))
            . pack('a8', sprintf("%07o", 0))          // uid
            . pack('a8', sprintf("%07o", 0))          // gid
            . pack('a12', sprintf("%011o", $size))
            . pack('a12', sprintf("%011o", $mtime))
            . str_repeat(' ', 8)                       // checksum, filled in below
            . $typeflag
            . pack('a100', '')                         // linkname
            . pack('a6', 'ustar') . pack('a2', '00')
            . pack('a32', '') . pack('a32', '')        // uname / gname
            . pack('a8', '') . pack('a8', '')          // devmajor / devminor
            . pack('a155', $prefix)
            . pack('a12', '');

        // The checksum is the sum of every byte of the header, with the checksum field itself
        // counted as eight spaces. That is the standard's own definition, not a convention.
        $sum = 0;
        for ($i = 0, $n = strlen($header); $i < $n; $i++) {
            $sum += ord($header[$i]);
        }

        return substr_replace($header, pack('a8', sprintf("%06o\0", $sum)), 148, 8);
    }

    /** Padding that rounds the content up to a whole 512-byte block. */
    public static function pad(int $size): string
    {
        $rem = $size % self::BLOCK;
        return $rem === 0 ? '' : str_repeat("\0", self::BLOCK - $rem);
    }

    /** The two empty blocks that end an archive. Written only by the final chunk. */
    public static function endOfArchive(): string
    {
        return str_repeat("\0", self::BLOCK * 2);
    }
}
