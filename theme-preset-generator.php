<?php
/**
 * Generate color schemes from squadron descriptions and keywords.
 */

function generatePresetFromSquadron($squadron) {
    $description = strtolower($squadron['description'] ?? '');

    // Default colors
    $preset = [
        'name' => $squadron['name'] . ' Theme',
        'squadron_id' => $squadron['id'],
        'primary_color' => '#002147',
        'secondary_color' => '#003366',
        'accent_color' => '#667eea',
        'background_color' => '#f4f4f4',
        'text_color' => '#222222',
    ];

    // Squadron 1: Mighty Mach One (griffin, Maltese Cross, strength, vigilance)
    if (strpos($description, 'griffin') !== false || strpos($description, 'maltese') !== false) {
        $preset['primary_color'] = '#1a1a1a';
        $preset['secondary_color'] = '#d4af37';
        $preset['accent_color'] = '#8b0000';
    }
    // Squadron 2: Deuce (red, white, blue contrails, speed, patriotism)
    elseif (strpos($description, 'contrails') !== false || strpos($description, 'patriotism') !== false) {
        $preset['primary_color'] = '#b22234';
        $preset['secondary_color'] = '#002868';
        $preset['accent_color'] = '#ffffff';
    }
    // Squadron 3: Dogs of War (Cerberus, flames, ferocity, fighting)
    elseif (strpos($description, 'cerberus') !== false || strpos($description, 'flames') !== false) {
        $preset['primary_color'] = '#8b0000';
        $preset['secondary_color'] = '#ff4500';
        $preset['accent_color'] = '#ffd700';
    }
    // Squadron 4: Fightin' Fourth (prop, wings, aviation)
    elseif (strpos($description, 'prop') !== false || strpos($description, 'aviation') !== false) {
        $preset['primary_color'] = '#003d7a';
        $preset['secondary_color'] = '#0099ff';
        $preset['accent_color'] = '#ffffff';
    }
    // Squadron 5: Wolfpack (snarling wolf, pack mentality, ferocity)
    elseif (strpos($description, 'wolf') !== false || strpos($description, 'pack') !== false) {
        $preset['primary_color'] = '#2c3e50';
        $preset['secondary_color'] = '#e74c3c';
        $preset['accent_color'] = '#95a5a6';
    }
    // Squadron 6: Bull Six (black bull, red background, power, aggression)
    elseif (strpos($description, 'bull') !== false || strpos($description, 'aggression') !== false) {
        $preset['primary_color'] = '#1a1a1a';
        $preset['secondary_color'] = '#c41e3a';
        $preset['accent_color'] = '#ffcc00';
    }
    // Squadron 7: Shadow Seven (unicorn, lightning, mystique, rarity)
    elseif (strpos($description, 'unicorn') !== false || strpos($description, 'lightning') !== false) {
        $preset['primary_color'] = '#4a148c';
        $preset['secondary_color'] = '#7b1fa2';
        $preset['accent_color'] = '#ffd700';
    }
    // Squadron 8: Eagle Eight (F-15 Eagle, superiority, achievement)
    elseif (strpos($description, 'eagle') !== false || strpos($description, 'superiority') !== false) {
        $preset['primary_color'] = '#1a1a1a';
        $preset['secondary_color'] = '#c0c0c0';
        $preset['accent_color'] = '#0066cc';
    }
    // Squadron 9: Viking Nine (dragon ships, boldness, exploration)
    elseif (strpos($description, 'viking') !== false || strpos($description, 'dragon') !== false) {
        $preset['primary_color'] = '#8b0000';
        $preset['secondary_color'] = '#d4af37';
        $preset['accent_color'] = '#000000';
    }
    // Squadron 10: Tiger Ten (Flying Tigers, lightning, combat)
    elseif (strpos($description, 'tiger') !== false || strpos($description, 'flying tigers') !== false) {
        $preset['primary_color'] = '#ff6600';
        $preset['secondary_color'] = '#003399';
        $preset['accent_color'] = '#ffcc00';
    }

    return $preset;
}

function getAllPresetNames($squadrons) {
    $presets = [];
    foreach ($squadrons as $squadron) {
        $presets[] = generatePresetFromSquadron($squadron);
    }
    return $presets;
}

?>
