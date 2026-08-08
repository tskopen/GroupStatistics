<?php
/**
 * Theme loading/saving utilities.
 *
 * Themes are persisted to /data/theme-config.json so squadron-based
 * branding survives redeploys/restarts, just like the rest of the
 * application's data.
 */

if (!defined('DATA_DIR')) {
    define('DATA_DIR', getenv('DATA_DIR') ?: '/data');
}

/**
 * Safe default colors used when no theme has been saved yet, or when
 * the saved theme file is missing/corrupt.
 */
function getDefaultTheme() {
    return [
        'selected_squadron_id' => null,
        'primary_color' => '#002147',
        'secondary_color' => '#003366',
        'accent_color' => '#667eea',
        'background_color' => '#f4f4f4',
        'text_color' => '#222222',
    ];
}

/**
 * Load the current theme configuration, falling back to defaults for
 * any missing keys so callers can always rely on a complete array.
 */
function loadTheme() {
    $path = DATA_DIR . '/theme-config.json';
    $defaults = getDefaultTheme();

    if (!file_exists($path)) {
        return $defaults;
    }

    $content = file_get_contents($path);
    $data = json_decode($content, true);

    if (!is_array($data)) {
        return $defaults;
    }

    return array_merge($defaults, $data);
}

/**
 * Persist the given theme array to theme-config.json.
 */
function saveTheme($theme) {
    $path = DATA_DIR . '/theme-config.json';
    $defaults = getDefaultTheme();
    $theme = array_merge($defaults, is_array($theme) ? $theme : []);
    return file_put_contents($path, json_encode($theme, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
