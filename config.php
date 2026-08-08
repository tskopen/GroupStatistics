<?php
/**
 * Central configuration for persistent data storage.
 *
 * Railway mounts a persistent volume at /data. All application data
 * (JSON "database" files and uploaded squadron images) must live there
 * so that data survives redeploys/restarts, which otherwise recreate
 * the container filesystem from scratch.
 */

if (!defined('DATA_DIR')) {
    define('DATA_DIR', getenv('DATA_DIR') ?: '/data');
}

if (!defined('IMAGES_DIR')) {
    define('IMAGES_DIR', DATA_DIR . '/images');
}

/**
 * Ensure the persistent data directory (and images subdirectory) exist,
 * and seed default JSON files on first run so the app has something to
 * read before any admin action has taken place.
 */
function initDataStore() {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }

    if (!is_dir(IMAGES_DIR)) {
        @mkdir(IMAGES_DIR, 0775, true);
    }

    $defaults = [
        'squadrons.json' => [
            ['id' => 1, 'name' => 'Mighty Mach One', 'description' => 'Symbolized by the griffin and the Maltese Cross, representing strength, vigilance, and a long tradition of honor.', 'icon' => null],
            ['id' => 2, 'name' => 'Deuce', 'description' => 'Represented by red, white, and blue contrails streaking toward space, symbolizing speed, patriotism, and the reach beyond the atmosphere.', 'icon' => null],
            ['id' => 3, 'name' => 'Dogs of War', 'description' => 'Embodied by Cerberus and flames, symbolizing ferocity, guardianship, and relentless fighting spirit.', 'icon' => null],
            ['id' => 4, 'name' => "Fightin' Fourth", 'description' => 'Represented by a prop and wings alongside four classes united, symbolizing aviation heritage and squadron unity across all four years.', 'icon' => null],
            ['id' => 5, 'name' => 'Wolfpack', 'description' => "Symbolized by a snarling wolf and the rallying cry 'Feed 'em to the wolves!', representing pack mentality and fierce competitiveness.", 'icon' => null],
            ['id' => 6, 'name' => 'Bull Six', 'description' => 'Represented by a black bull set against a red background, symbolizing raw power, aggression, and intimidation.', 'icon' => null],
            ['id' => 7, 'name' => 'Shadow Seven', 'description' => 'Symbolized by a unicorn and a lightning bolt, representing mystique, rarity, and swift, unstoppable striking power.', 'icon' => null],
            ['id' => 8, 'name' => 'Eagle Eight', 'description' => 'Represented by the F-15 Eagle and four class stars, symbolizing air superiority and the collective achievement of every class.', 'icon' => null],
            ['id' => 9, 'name' => 'Viking Nine', 'description' => 'Symbolized by dragon ships, representing boldness, exploration, and a fearless warrior spirit.', 'icon' => null],
            ['id' => 10, 'name' => 'Tiger Ten', 'description' => 'Represented by the Flying Tigers and lightning bolts, symbolizing aggression, speed, and a storied legacy of combat excellence.', 'icon' => null],
        ],
        'scores.json' => [],
        'competitions.json' => [],
        'brackets.json' => [],
        'theme-config.json' => [
            'selected_squadron_id' => null,
            'primary_color' => '#002147',
            'secondary_color' => '#003366',
            'accent_color' => '#667eea',
            'background_color' => '#f4f4f4',
            'text_color' => '#222222',
        ],
    ];

    foreach ($defaults as $filename => $defaultData) {
        $path = DATA_DIR . '/' . $filename;
        if (!file_exists($path)) {
            @file_put_contents($path, json_encode($defaultData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }
}

initDataStore();

function readJson($file) {
    if (!file_exists($file)) {
        return [];
    }
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function writeJson($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Build a URL that serves an uploaded squadron icon out of the
 * persistent /data/images directory via image.php.
 */
function iconUrl($icon) {
    if (!$icon) {
        return null;
    }
    // Support any legacy values that may already contain a path
    // (e.g. "uploads/foo.png" or "images/foo.png") by taking just
    // the filename portion.
    $filename = basename($icon);
    return 'image.php?file=' . rawurlencode($filename);
}
