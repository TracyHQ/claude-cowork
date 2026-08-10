<?php
/**
 * TarStream — sinh byte tar (USTAR) theo dong chay, KHONG ghi file tam.
 *
 * Vi sao tu viet thay vi PharData: PharData chi dong goi tu file co san tren dia va
 * `phar.readonly` bat mac dinh tren nhieu shared hosting -> tat ngay tu dau. Dinh dang
 * USTAR lai rat don gian (header 512 byte + noi dung padding boi 512), nen tu viet vua
 * ngan vua chay duoc o moi noi, va quan trong nhat: **stream duoc**, khong can cho du
 * ca archive roi moi gui.
 *
 * Vi sao khong can file tam: Akeeba phai ghi nguyen archive ra dia khach truoc khi tai
 * ve (161 MB voi wisdeaf.org) — tren hosting co quota do la mot kieu chet that. O day
 * byte sinh ra toi dau day len R2 toi do, buffer bao nhieu thi giu bay nhieu.
 *
 * KHONG phu thuoc Joomla — unit test duoc doc lap (xem tests/run.php).
 */

final class TarStream
{
    private const BLOCK = 512;

    /** Header cua mot file thuong trong USTAR. */
    public static function fileHeader(string $path, int $size, int $mode = 0644, int $mtime = 0): string
    {
        return self::header($path, $size, $mode, $mtime, '0');
    }

    /** Header cua mot thu muc. */
    public static function dirHeader(string $path, int $mode = 0755, int $mtime = 0): string
    {
        $path = rtrim($path, '/') . '/';
        return self::header($path, 0, $mode, $mtime, '5');
    }

    /**
     * @param string $typeflag '0' = file thuong, '5' = thu muc
     */
    private static function header(string $path, int $size, int $mode, int $mtime, string $typeflag): string
    {
        // USTAR: name 100 byte, con duong dai hon thi tach sang truong `prefix` 155 byte.
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
            . str_repeat(' ', 8)                       // checksum: tinh sau, tam thoi 8 dau cach
            . $typeflag
            . pack('a100', '')                         // linkname
            . pack('a6', 'ustar') . pack('a2', '00')
            . pack('a32', '') . pack('a32', '')        // uname / gname
            . pack('a8', '') . pack('a8', '')          // devmajor / devminor
            . pack('a155', $prefix)
            . pack('a12', '');

        // Checksum = tong byte cua header voi truong checksum coi nhu 8 dau cach (dung chuan).
        $sum = 0;
        for ($i = 0, $n = strlen($header); $i < $n; $i++) {
            $sum += ord($header[$i]);
        }

        return substr_replace($header, pack('a8', sprintf("%06o\0", $sum)), 148, 8);
    }

    /** Padding de noi dung tron khoi 512. */
    public static function pad(int $size): string
    {
        $rem = $size % self::BLOCK;
        return $rem === 0 ? '' : str_repeat("\0", self::BLOCK - $rem);
    }

    /** Hai khoi rong ket thuc archive — chi ghi o chunk CUOI CUNG. */
    public static function endOfArchive(): string
    {
        return str_repeat("\0", self::BLOCK * 2);
    }
}
