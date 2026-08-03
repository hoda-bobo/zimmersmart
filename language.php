<?php
/*
|--------------------------------------------------------------------------
| ZimmerSmart language system
|--------------------------------------------------------------------------
| Include this file once on every page that needs translations.
| The navbar already includes it automatically.
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$allowed_languages = ['en', 'he', 'ar'];

if (
    isset($_GET['lang']) &&
    in_array($_GET['lang'], $allowed_languages, true)
) {
    $_SESSION['lang'] = $_GET['lang'];
}

$current_language = $_SESSION['lang'] ?? 'en';

if (!in_array($current_language, $allowed_languages, true)) {
    $current_language = 'en';
    $_SESSION['lang'] = 'en';
}

$language_file = __DIR__ . '/languages/' . $current_language . '.php';

if (!file_exists($language_file)) {
    $language_file = __DIR__ . '/languages/en.php';
    $current_language = 'en';
    $_SESSION['lang'] = 'en';
}

$translations = require $language_file;

if (!is_array($translations)) {
    $translations = [];
}

function t(string $key, ?string $fallback = null): string
{
    global $translations;

    if (
        isset($translations[$key]) &&
        is_string($translations[$key])
    ) {
        return $translations[$key];
    }

    return $fallback ?? $key;
}

function current_language(): string
{
    global $current_language;

    return $current_language;
}

function is_rtl(): bool
{
    return in_array(current_language(), ['he', 'ar'], true);
}

function page_direction(): string
{
    return is_rtl() ? 'rtl' : 'ltr';
}

function language_name(?string $language = null): string
{
    $language = $language ?? current_language();

    $language_names = [
        'en' => 'English',
        'he' => 'עברית',
        'ar' => 'العربية'
    ];

    return $language_names[$language] ?? 'English';
}

function language_switch_url(string $language): string
{
    $allowed_languages = ['en', 'he', 'ar'];

    if (!in_array($language, $allowed_languages, true)) {
        $language = 'en';
    }

    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $parts = parse_url($request_uri);

    $path = $parts['path'] ?? ($_SERVER['PHP_SELF'] ?? 'home.php');
    $query = [];

    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    $query['lang'] = $language;

    return $path . '?' . http_build_query($query);
}
?>