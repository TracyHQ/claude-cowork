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

    /** The header for an ordinary file. */
    public static function fileHeader(string $path, int $size, int $mode = 0644, int $mtime = 0): string
    {
        return self::header($path, $size, $mode, $mtime, '0');
    }

    /** The header for a directory. */
    public static function dirHeader(string $path, int $mode = 0755, int $mtime = 0): string
    {
        $path = rtrim($path, '/') . '/';
        return self::header($path, 0, $mode, $mtime, '5');
    }

    /**
     * @param string $typeflag '0' for an ordinary file, '5' for a directory
     */
    private static function header(string $path, int $size, int $mode, int $mtime, string $typeflag): string
    {
        // USTAR gives the name 100 bytes; anything longer is split, with the leading
        // directories moved into the 155-byte `prefix` field.
        $prefix = '';
        $name   = $path;
        if (strlen($path) > 100) {
            $cut = strrpos(substr($path, 0, 155), '/');
            if ($cut === false) {
                throw new InvalidArgumentException("path too long for ustar: {$path}");
            }
            $prefix = substr($path, 0, $cut);
            $name   = substr($path, $cut + 1);
            if (strlen($name) > 100) {
                throw new InvalidArgumentException("path segment too long for ustar: {$path}");
            }
        }

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
