<?php
/**
 * Token — so sanh token thoi-gian-hang-so, va coi endpoint la TAT MAC DINH.
 *
 * Token rong (chua cau hinh) => endpoint chua bat => tu choi het. Day la lan can:
 * cai plugin len xong ma chua dan token thi khong ai goi vao duoc. Token dung mot lan,
 * go plugin sau khi migrate xong.
 */
final class Token
{
    public static function isConfigured(?string $expected): bool
    {
        return is_string($expected) && strlen($expected) >= 16;
    }

    /** true chi khi expected da cau hinh VA khop (hash_equals chong timing attack). */
    public static function check(?string $expected, ?string $provided): bool
    {
        if (!self::isConfigured($expected)) {
            return false;
        }
        if (!is_string($provided) || $provided === '') {
            return false;
        }
        return hash_equals($expected, $provided);
    }
}
