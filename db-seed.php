<?php
/**
 * Utility script for seeding default database configuration.
 *
 * This is NOT exposed to the web and is not linked from anywhere in
 * the application. It exists purely as a manual/CLI helper for
 * (re)seeding the event_type_config and admin_config tables, e.g.
 * after a fresh migration or when troubleshooting a deployment.
 *
 * Usage: php db-seed.php
 */

require __DIR__ . '/config.php';

function seedEventTypeConfig(PDO $db) {
    $defaults = [
        ['samis', 'SAMIS Scores', 'Weekly SAMIs', '📊'],
        ['pft', 'Physical Fitness Test', 'PFT scores', '💪'],
        ['other', 'Other Event', 'Miscellaneous points', '📌'],
        ['bracket', 'Bracket Tournament', 'Tournament bracket event', '🏆'],
        ['intramural', 'Intramural', 'Intramural game results', '🏀'],
    ];

    $stmt = $db->prepare('
        INSERT OR IGNORE INTO event_type_config (event_type, display_name, description, emoji)
        VALUES (?, ?, ?, ?)
    ');

    foreach ($defaults as $config) {
        $stmt->execute($config);
    }
}

function seedAdminConfig(PDO $db) {
    $defaults = [
        'intramural_win_points' => '5',
        'intramural_loss_points' => '-1',
        'intramural_bonus_0_6_points' => '10',
    ];

    $stmt = $db->prepare('INSERT OR IGNORE INTO admin_config (key, value) VALUES (?, ?)');

    foreach ($defaults as $key => $value) {
        $stmt->execute([$key, $value]);
    }
}

if (php_sapi_name() === 'cli') {
    $db = getDb();
    seedEventTypeConfig($db);
    seedAdminConfig($db);
    echo "Database seed complete.\n";
}
