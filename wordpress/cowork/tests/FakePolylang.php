<?php
/**
 * The rest of Polylang that `content.language` calls, on top of the fake in FakeWordPress.php.
 *
 * FakeWordPress.php owns `PLL()`, `pll_languages_list()`, `pll_get_post_language()` and the site
 * they read (`WP_Fake::$languages`, `$postLanguage`, `$translations`). This adds what only
 * `content.language` needs: filing a post under a language, saving a translation group, and
 * `PLL_Settings::get_predefined_languages()` keyed by WordPress locale. The predefined rows are a
 * slice of Polylang's own list — enough to hold one of every case the engine decides on: a code
 * with one row, a code with several, a locale without a region (`vi`, `ja`).
 *
 * Loaded late by tests/run.php: once `pll_set_post_language` exists, the "site has no translation
 * plugin" case can no longer be asked.
 */
declare(strict_types=1);

function pll_set_post_language(int $id, string $slug): void
{
    WP_Fake::$postLanguage[$id] = $slug;
}

/** Every post of the group points at the whole group, the way `pll_get_post()` reads it back. */
function pll_save_post_translations(array $group): array
{
    foreach ($group as $id) {
        WP_Fake::$translations[(int) $id] = $group;
    }
    return $group;
}

final class PLL_Settings
{
    public static function get_predefined_languages(): array
    {
        $row = static function (string $code, string $locale, string $name, string $flag, string $dir = 'ltr'): array {
            return ['code' => $code, 'locale' => $locale, 'name' => $name, 'dir' => $dir, 'flag' => $flag];
        };
        return [
            'ar' => $row('ar', 'ar', 'العربية', 'arab', 'rtl'),
            'ary' => $row('ar', 'ary', 'العربية المغربية', 'ma', 'rtl'),
            'de_AT' => $row('de', 'de_AT', 'Deutsch', 'at'),
            'de_CH' => $row('de', 'de_CH', 'Deutsch (Schweiz)', 'ch'),
            'de_DE' => $row('de', 'de_DE', 'Deutsch', 'de'),
            'en_AU' => $row('en', 'en_AU', 'English', 'au'),
            'en_GB' => $row('en', 'en_GB', 'English', 'gb'),
            'en_US' => $row('en', 'en_US', 'English', 'us'),
            'es_ES' => $row('es', 'es_ES', 'Español', 'es'),
            'es_MX' => $row('es', 'es_MX', 'Español de México', 'mx'),
            'fr_CA' => $row('fr', 'fr_CA', 'Français', 'quebec'),
            'fr_FR' => $row('fr', 'fr_FR', 'Français', 'fr'),
            'it_IT' => $row('it', 'it_IT', 'Italiano', 'it'),
            'ja' => $row('ja', 'ja', '日本語', 'jp'),
            'ko_KR' => $row('ko', 'ko_KR', '한국어', 'kr'),
            'nl_BE' => $row('nl', 'nl_BE', 'Nederlands (België)', 'be'),
            'nl_NL' => $row('nl', 'nl_NL', 'Nederlands', 'nl'),
            'pl_PL' => $row('pl', 'pl_PL', 'Polski', 'pl'),
            'pt_BR' => $row('pt', 'pt_BR', 'Português', 'br'),
            'pt_PT' => $row('pt', 'pt_PT', 'Português', 'pt'),
            'ru_RU' => $row('ru', 'ru_RU', 'Русский', 'ru'),
            'vi' => $row('vi', 'vi', 'Tiếng Việt', 'vn'),
            'zh_CN' => $row('zh', 'zh_CN', '中文 (中国)', 'cn'),
            'zh_TW' => $row('zh', 'zh_TW', '中文 (台灣)', 'tw'),
        ];
    }
}
