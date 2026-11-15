<?php
// Arabic / English translations.
//
//   t('checkin.title')                       → text in the current language
//   t('alert.explanation', ['value' => 88])  → "{value}" placeholders are filled in
//
// The current language comes from the orbitcare_lang cookie (set from Settings, the language
// switch on public pages, or the user's saved setting at login). Missing Arabic text falls back to
// English, and a missing key falls back to the key itself — the UI never breaks.
// Texts live in backend/lang/{en,ar}/<area>.php — one file per area of the app, the same files and
// keys in both languages (tests/run.php checks this).
declare(strict_types=1);

const SUPPORTED_LANGUAGES = ['en' => 'English', 'ar' => 'العربية'];
const LANGUAGE_COOKIE = 'orbitcare_lang';

// Holds the language for this request (can be changed mid-request, e.g. right after login).
function language_state(?string $set = null): string
{
    static $language = null;
    if ($set !== null) {
        $language = $set;
    }
    if ($language === null) {
        $cookie = $_COOKIE[LANGUAGE_COOKIE] ?? 'en';
        $language = isset(SUPPORTED_LANGUAGES[$cookie]) ? $cookie : 'en';
    }
    return $language;
}

function current_language(): string
{
    return language_state();
}

function text_direction(): string
{
    return current_language() === 'ar' ? 'rtl' : 'ltr';
}

// Switches the language for this request and remembers it in a cookie (one year).
function set_language(string $language): void
{
    if (!isset(SUPPORTED_LANGUAGES[$language])) {
        return;
    }
    language_state($language);
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        setcookie(LANGUAGE_COOKIE, $language, [
            'expires'  => time() + 31536000,
            'path'     => BASE_URL . '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
}

function translations(string $language): array
{
    static $loaded = [];
    if (!isset($loaded[$language])) {
        $loaded[$language] = [];
        foreach (glob(dirname(__DIR__) . "/lang/{$language}/*.php") ?: [] as $file) {
            $loaded[$language] += require $file;
        }
    }
    return $loaded[$language];
}

// Language to apply right after login: the device's choice (cookie) wins; otherwise the
// language saved in the user's settings.
function apply_saved_language(?string $saved): void
{
    if (!isset($_COOKIE[LANGUAGE_COOKIE]) && $saved !== null) {
        set_language($saved);
    }
}

function t(string $key, array $params = []): string
{
    return translate_in(current_language(), $key, $params);
}

function translate_in(string $language, string $key, array $params = []): string
{
    $text = translations($language)[$key] ?? translations('en')[$key] ?? $key;
    foreach ($params as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }
    return $text;
}

// Display label of a measurement unit ("bpm" → "نبضة/د" in Arabic).
function unit_label(string $unit): string
{
    return t('unit.' . $unit);
}

// lang="…" dir="…" for the <html> element.
function html_language_attributes(): string
{
    return 'lang="' . current_language() . '" dir="' . text_direction() . '"';
}

// Small link on public pages that switches to the other language (keeps the current page).
function language_switch_link(string $class = 'lang-switch'): string
{
    $other = current_language() === 'ar' ? 'en' : 'ar';
    $path = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: url('index.php');
    return '<a class="' . e($class) . '" href="' . e($path . '?lang=' . $other) . '" lang="' . $other . '" hreflang="' . $other . '">'
        . e(SUPPORTED_LANGUAGES[$other]) . '</a>';
}

// <script> tag with the current language's texts for the browser (escaped for HTML).
function i18n_script_tag(): string
{
    $data = [
        'lang' => current_language(),
        'dir' => text_direction(),
        'strings' => translations(current_language()) + translations('en'),
    ];
    return '<script type="application/json" id="i18n-data">'
        . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)
        . '</script>';
}
