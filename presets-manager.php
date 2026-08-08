<?php

if (!defined('DATA_DIR')) {
    define('DATA_DIR', getenv('DATA_DIR') ?: '/data');
}

function loadPresets() {
    $path = DATA_DIR . '/theme-presets.json';
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function savePresets($presets) {
    $path = DATA_DIR . '/theme-presets.json';
    file_put_contents($path, json_encode($presets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function savePreset($name, $theme) {
    $presets = loadPresets();
    $presets[$name] = [
        'name' => $name,
        'primary_color' => $theme['primary_color'] ?? '#002147',
        'secondary_color' => $theme['secondary_color'] ?? '#003366',
        'accent_color' => $theme['accent_color'] ?? '#667eea',
        'background_color' => $theme['background_color'] ?? '#f4f4f4',
        'text_color' => $theme['text_color'] ?? '#222222',
        'created_at' => $presets[$name]['created_at'] ?? date('c'),
        'updated_at' => date('c'),
    ];
    savePresets($presets);
    return true;
}

function loadPreset($name) {
    $presets = loadPresets();
    return $presets[$name] ?? null;
}

function deletePreset($name) {
    $presets = loadPresets();
    unset($presets[$name]);
    savePresets($presets);
}

?>
