<?php
/**
 * Language / Localization System
 */

// Default language
define('DEFAULT_LANG', 'ru');

// Available languages
$GLOBALS['available_languages'] = [
    'ru' => 'Русский',
    'en' => 'English'
];

// Current language (from cookie, session, or default)
function getCurrentLang() {
    if (isset($_GET['lang']) && isset($GLOBALS['available_languages'][$_GET['lang']])) {
        setcookie('lang', $_GET['lang'], time() + 86400 * 365, '/');
        return $_GET['lang'];
    }
    if (isset($_COOKIE['lang']) && isset($GLOBALS['available_languages'][$_COOKIE['lang']])) {
        return $_COOKIE['lang'];
    }
    return DEFAULT_LANG;
}

// Alias for getCurrentLang
function getCurrentLanguage() {
    return getCurrentLang();
}

// Load translations
function loadTranslations($lang) {
    $file = __DIR__ . "/lang/{$lang}.php";
    if (file_exists($file)) {
        return include $file;
    }
    // Fallback to Russian
    return include __DIR__ . '/lang/ru.php';
}

// Global translations array
$GLOBALS['current_lang'] = getCurrentLang();
$GLOBALS['translations'] = loadTranslations($GLOBALS['current_lang']);

/**
 * Translate function
 * @param string $key Translation key
 * @param array $params Replacement parameters
 * @return string Translated text
 */
function __($key, $params = []) {
    $text = $GLOBALS['translations'][$key] ?? $key;
    
    // Replace parameters like {name}, {count}
    foreach ($params as $k => $v) {
        $text = str_replace('{' . $k . '}', $v, $text);
    }
    
    return $text;
}

/**
 * Echo translated text
 */
function _e($key, $params = []) {
    echo __($key, $params);
}

/**
 * Get language switcher HTML
 */
function getLanguageSwitcher() {
    $current = $GLOBALS['current_lang'];
    $html = '<div class="dropdown">';
    $html .= '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">';
    $html .= '<i class="bi bi-globe me-1"></i>' . $GLOBALS['available_languages'][$current];
    $html .= '</button><ul class="dropdown-menu dropdown-menu-end">';
    
    foreach ($GLOBALS['available_languages'] as $code => $name) {
        $active = $code === $current ? ' active' : '';
        $html .= "<li><a class=\"dropdown-item{$active}\" href=\"?lang={$code}\">{$name}</a></li>";
    }
    
    $html .= '</ul></div>';
    return $html;
}
