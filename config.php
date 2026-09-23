<?php

define('DATA_DIR', getenv('DATA_DIR') ?: __DIR__ . '/data');
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

if (!defined('DB_PATH')) {
    define('DB_PATH', DATA_DIR . '/squadron-tracker.db');
}

/**
 * Return a shared PDO connection to the SQLite database.
 *
 * The connection is created once per request and reused for all
 * subsequent calls, avoiding the overhead of repeatedly opening the
 * SQLite file.
 */
function getDb() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
    return $pdo;
}

/**
 * Create the SQLite schema if it does not already exist, migrate any
 * legacy JSON data into it on first run, and seed default lookup
 * tables (event type configuration and admin settings).
 */
function initDatabase() {
    $db = getDb();

    $db->exec('
        CREATE TABLE IF NOT EXISTS squadrons (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            description TEXT,
            icon_filename TEXT,
            created_at DATETIME
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            squadron_id INTEGER,
            event_type TEXT,
            event_name TEXT,
            value REAL,
            points_awarded REAL,
            timestamp DATETIME,
            created_at DATETIME,
            FOREIGN KEY (squadron_id) REFERENCES squadrons(id)
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS brackets (
            id TEXT PRIMARY KEY,
            name TEXT,
            created_date DATETIME,
            updated_at DATETIME,
            champion_id INTEGER,
            rounds JSON,
            FOREIGN KEY (champion_id) REFERENCES squadrons(id)
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS intramural_games (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sport TEXT,
            team1_id INTEGER,
            team2_id INTEGER,
            team1_score INTEGER,
            team2_score INTEGER,
            winner_id INTEGER,
            game_date DATE,
            points_team1 REAL,
            points_team2 REAL,
            timestamp DATETIME,
            created_at DATETIME,
            FOREIGN KEY (team1_id) REFERENCES squadrons(id),
            FOREIGN KEY (team2_id) REFERENCES squadrons(id),
            FOREIGN KEY (winner_id) REFERENCES squadrons(id)
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS intramural_wl_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            squadron_id INTEGER,
            sport TEXT,
            wins INTEGER DEFAULT 0,
            losses INTEGER DEFAULT 0,
            points_awarded REAL DEFAULT 0,
            updated_at DATETIME,
            FOREIGN KEY (squadron_id) REFERENCES squadrons(id),
            UNIQUE(squadron_id, sport)
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS event_type_config (
            event_type TEXT PRIMARY KEY,
            display_name TEXT,
            description TEXT,
            emoji TEXT
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS admin_config (
            key TEXT PRIMARY KEY,
            value TEXT
        )
    ');

    migrateFromJson();
    seedDefaultConfig();
}

/**
 * Migrate legacy JSON "database" files into the SQLite database.
 *
 * This only runs once: after a successful migration the JSON files
 * are renamed to *.bak so subsequent requests skip this step. It is
 * safe to call on every boot since it no-ops once the .bak markers
 * exist.
 */
function migrateFromJson() {
    $squadronsPath = DATA_DIR . '/squadrons.json';
    $scoresPath = DATA_DIR . '/scores.json';
    $bracketsPath = DATA_DIR . '/brackets.json';

    $squadronsBak = $squadronsPath . '.bak';
    $scoresBak = $scoresPath . '.bak';
    $bracketsBak = $bracketsPath . '.bak';

    // If migration already happened (any .bak marker present), skip.
    if (file_exists($squadronsBak) || file_exists($scoresBak) || file_exists($bracketsBak)) {
        return;
    }

    // Nothing to migrate if none of the legacy files exist.
    if (!file_exists($squadronsPath) && !file_exists($scoresPath) && !file_exists($bracketsPath)) {
        return;
    }

    $db = getDb();

    try {
        $db->beginTransaction();

        if (file_exists($squadronsPath)) {
            $squadrons = readJson($squadronsPath);
            $stmt = $db->prepare('
                INSERT OR IGNORE INTO squadrons (id, name, description, icon_filename, created_at)
                VALUES (?, ?, ?, ?, ?)
            ');
            foreach ($squadrons as $s) {
                $stmt->execute([
                    $s['id'] ?? null,
                    $s['name'] ?? null,
                    $s['description'] ?? null,
                    $s['icon'] ?? null,
                    $s['created_at'] ?? date('c'),
                ]);
            }
        }

        if (file_exists($scoresPath)) {
            $scores = readJson($scoresPath);
            $stmt = $db->prepare('
                INSERT INTO events (squadron_id, event_type, event_name, value, points_awarded, timestamp, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            foreach ($scores as $score) {
                $value = isset($score['value']) ? (float) $score['value'] : 0;
                $pointsAwarded = isset($score['points_awarded']) ? (float) $score['points_awarded'] : $value;
                $stmt->execute([
                    $score['squadron_id'] ?? null,
                    $score['event_type'] ?? null,
                    $score['event_name'] ?? null,
                    $value,
                    $pointsAwarded,
                    $score['timestamp'] ?? date('c'),
                    date('c'),
                ]);
            }
        }

        if (file_exists($bracketsPath)) {
            $brackets = readJson($bracketsPath);
            $stmt = $db->prepare('
                INSERT OR IGNORE INTO brackets (id, name, created_date, updated_at, champion_id, rounds)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            foreach ($brackets as $bracket) {
                $stmt->execute([
                    $bracket['id'] ?? null,
                    $bracket['name'] ?? null,
                    $bracket['created_date'] ?? $bracket['created_at'] ?? date('c'),
                    $bracket['updated_at'] ?? $bracket['created_date'] ?? date('c'),
                    $bracket['champion_id'] ?? null,
                    json_encode($bracket['rounds'] ?? []),
                ]);
            }
        }

        $db->commit();

        // Mark migration complete by renaming legacy JSON files.
        if (file_exists($squadronsPath)) {
            @rename($squadronsPath, $squadronsBak);
        }
        if (file_exists($scoresPath)) {
            @rename($scoresPath, $scoresBak);
        }
        if (file_exists($bracketsPath)) {
            @rename($bracketsPath, $bracketsBak);
        }

        error_log('Migration from JSON to SQLite completed successfully.');
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Migration from JSON to SQLite failed: ' . $e->getMessage());
    }
}

/**
 * Seed default event type configuration and admin settings if they
 * are not already present in the database.
 */
function seedDefaultConfig() {
    $db = getDb();

    $eventTypeDefaults = [
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
    foreach ($eventTypeDefaults as $config) {
        $stmt->execute($config);
    }

    $adminConfigDefaults = [
        'intramural_win_points' => '5',
        'intramural_loss_points' => '-1',
        'intramural_bonus_0_6_points' => '10',
    ];

    $stmt = $db->prepare('INSERT OR IGNORE INTO admin_config (key, value) VALUES (?, ?)');
    foreach ($adminConfigDefaults as $key => $value) {
        $stmt->execute([$key, $value]);
    }
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
            ['id' => 1, 'name' => 'Mighty Mach One', 'description' => 'Symbolized by the griffin and the Maltese Cross, representing strength, vigilance, and a long tradition of honor.', 'icon' => 'cadet-squadron-01.jpg'],
            ['id' => 2, 'name' => 'Deuce', 'description' => 'Represented by red, white, and blue contrails streaking toward space, symbolizing speed, patriotism, and the reach beyond the atmosphere.', 'icon' => 'cadet-squadron-02.jpg'],
            ['id' => 3, 'name' => 'Dogs of War', 'description' => 'Embodied by Cerberus and flames, symbolizing ferocity, guardianship, and relentless fighting spirit.', 'icon' => 'cadet-squadron-03.jpg'],
            ['id' => 4, 'name' => "Fightin' Fourth", 'description' => 'Represented by a prop and wings alongside four classes united, symbolizing aviation heritage and squadron unity across all four years.', 'icon' => 'cadet-squadron-04.jpg'],
            ['id' => 5, 'name' => 'Wolfpack', 'description' => "Symbolized by a snarling wolf and the rallying cry 'Feed 'em to the wolves!', representing pack mentality and fierce competitiveness.", 'icon' => 'cadet-squadron-05.jpg'],
            ['id' => 6, 'name' => 'Bull Six', 'description' => 'Represented by a black bull set against a red background, symbolizing raw power, aggression, and intimidation.', 'icon' => 'cadet-squadron-06.jpg'],
            ['id' => 7, 'name' => 'Shadow Seven', 'description' => 'Symbolized by a unicorn and a lightning bolt, representing mystique, rarity, and swift, unstoppable striking power.', 'icon' => 'cadet-squadron-07.jpg'],
            ['id' => 8, 'name' => 'Eagle Eight', 'description' => 'Represented by the F-15 Eagle and four class stars, symbolizing air superiority and the collective achievement of every class.', 'icon' => 'cadet-squadron-08.jpg'],
            ['id' => 9, 'name' => 'Viking Nine', 'description' => 'Symbolized by dragon ships, representing boldness, exploration, and a fearless warrior spirit.', 'icon' => 'cadet-squadron-09.jpg'],
            ['id' => 10, 'name' => 'Tiger Ten', 'description' => 'Represented by the Flying Tigers and lightning bolts, symbolizing aggression, speed, and a storied legacy of combat excellence.', 'icon' => 'cadet-squadron-10.jpg'],
        ],
        'scores.json' => [],
        'competitions.json' => [],
        'brackets.json' => [],
        'theme-config.json' => [
            'selected_squadron_id' => null,
            'active_preset' => null,
            'primary_color' => '#002147',
            'secondary_color' => '#003366',
            'accent_color' => '#667eea',
            'background_color' => '#f4f4f4',
            'text_color' => '#222222',
        ],
        // theme-presets.json persists in /data/ (mounted volume)
        // Stores user presets (custom names) and squadron preset customizations (squadron_X keys)
        // Survives Railway redeploys and container restarts
        'theme-presets.json' => [],
        'notification-subscriptions.json' => ['subscriptions' => []],
        'vapid-keys.json' => [],
        'notification-queue.json' => [],
    ];

    foreach ($defaults as $filename => $defaultData) {
        $path = DATA_DIR . '/' . $filename;
        if (!file_exists($path)) {
            @file_put_contents($path, json_encode($defaultData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }
}

initDataStore();

/**
 * Restore data from backup if volume was reset or data lost.
 *
 * This acts as a safeguard against volume remounting issues during
 * deployments. If the persistent /data volume comes up empty (e.g.
 * because a new deployment could not attach to the previous
 * deployment's volume contents), initDataStore() above will only ever
 * seed empty defaults. This function detects that condition for the
 * critical data files (scores and brackets) and restores the last
 * known good snapshot instead of silently leaving the app with no data.
 */
function restoreDataFromBackup() {
    $scoresPath = DATA_DIR . '/scores.json';
    $bracketsPath = DATA_DIR . '/brackets.json';

    // Check if scores.json exists and has data
    if (!file_exists($scoresPath) || filesize($scoresPath) < 10) {
        $backupScores = [
            [
                'squadron_id' => 2,
                'event_type' => 'bracket',
                'tournament_name' => 'Culex Bracket',
                'value' => 1,
                'opponent_id' => 1,
                'team1_score' => 2,
                'team2_score' => 2,
                'winner_id' => 2,
                'timestamp' => '2026-08-07T00:31:02+00:00',
            ],
            [
                'squadron_id' => 3,
                'event_type' => 'bracket',
                'tournament_name' => 'Culex Bracket',
                'value' => 1,
                'opponent_id' => 4,
                'team1_score' => 4,
                'team2_score' => 4,
                'winner_id' => 3,
                'timestamp' => '2026-08-07T01:06:58+00:00',
            ],
            [
                'squadron_id' => 1,
                'event_name' => 'Sami Round 1',
                'event_type' => 'samis',
                'value' => 8,
                'timestamp' => '2026-08-08T20:04:46+00:00',
            ],
            [
                'squadron_id' => 2,
                'event_name' => 'Sami Round 1',
                'event_type' => 'samis',
                'value' => 9,
                'timestamp' => '2026-08-08T20:04:46+00:00',
            ],
            [
                'squadron_id' => 3,
                'event_name' => 'Sami Round 1',
                'event_type' => 'samis',
                'value' => 10,
                'timestamp' => '2026-08-08T20:04:46+00:00',
            ],
            [
                'squadron_id' => 4,
                'event_name' => 'Sami Round 1',
                'event_type' => 'samis',
                'value' => 6,
                'timestamp' => '2026-08-08T20:04:46+00:00',
            ],
            [
                'squadron_id' => 5,
                'event_type' => 'bracket',
                'tournament_name' => 'Culex Bracket',
                'value' => 2,
                'opponent_id' => 6,
                'team1_score' => 205,
                'team2_score' => 205,
                'winner_id' => 5,
                'timestamp' => '2026-08-08T20:05:37+00:00',
            ],
            [
                'squadron_id' => 3,
                'event_type' => 'other',
                'value' => 1,
                'timestamp' => '2026-08-08T21:08:06+00:00',
            ],
        ];
        @file_put_contents($scoresPath, json_encode($backupScores, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        error_log('Data restored from backup: scores.json');
    }

    // Check if brackets.json exists and has data
    if (!file_exists($bracketsPath) || filesize($bracketsPath) < 10) {
        $backupBrackets = [
            [
                'id' => 'a4a7bab8e79184eb',
                'name' => 'Culex Bracket',
                'created_date' => '2026-08-07T00:30:26+00:00',
                'rounds' => [
                    [
                        'round_num' => 1,
                        'matchups' => [
                            [
                                'id' => '3a47e6ec7d331c86',
                                'team1_id' => 1,
                                'team2_id' => 2,
                                'team1_score' => 1,
                                'team2_score' => 2,
                                'winner_id' => 2,
                                'points' => 1,
                            ],
                            [
                                'id' => 'ec5e39140c719a80',
                                'team1_id' => 3,
                                'team2_id' => 4,
                                'team1_score' => 4,
                                'team2_score' => 2,
                                'winner_id' => 3,
                                'points' => 1,
                            ],
                            [
                                'id' => '3de17c7f69c4a360',
                                'team1_id' => 5,
                                'team2_id' => 6,
                                'team1_score' => 205,
                                'team2_score' => 195,
                                'winner_id' => 5,
                                'points' => 2,
                            ],
                            [
                                'id' => '1a22e1fbfa72d792',
                                'team1_id' => 7,
                                'team2_id' => 8,
                                'team1_score' => null,
                                'team2_score' => null,
                                'winner_id' => null,
                                'points' => null,
                            ],
                            [
                                'id' => '58162011f7375cba',
                                'team1_id' => 9,
                                'team2_id' => 10,
                                'team1_score' => null,
                                'team2_score' => null,
                                'winner_id' => null,
                                'points' => null,
                            ],
                        ],
                    ],
                ],
                'champion_id' => null,
            ],
        ];
        @file_put_contents($bracketsPath, json_encode($backupBrackets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        error_log('Data restored from backup: brackets.json');
    }
}

restoreDataFromBackup();

initDatabase();

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

/**
 * SQLite Database Connection
 * Automatically initializes /data/squadron-tracker.db on first access
 */
define('DB_PATH', DATA_DIR . '/squadron-tracker.db');

function getDB() {
    static $db = null;
    if ($db === null) {
        try {
            $db = new PDO('sqlite:' . DB_PATH);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->exec('PRAGMA foreign_keys = ON');
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            die('Database error. Check logs.');
        }
    }
    return $db;
}

/**
 * Safe database query helper
 * Usage: dbQuery('SELECT * FROM event_types WHERE event_type = ?', ['pft'])
 */
function dbQuery($sql, $params = []) {
    try {
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log('Database query failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Insert or update in database (returns last insert ID)
 */
function dbExecute($sql, $params = []) {
    try {
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        return getDB()->lastInsertId();
    } catch (PDOException $e) {
        error_log('Database execute failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get single row as associative array
 */
function dbFetchOne($sql, $params = []) {
    $stmt = dbQuery($sql, $params);
    return $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
}

/**
 * Get all rows as associative array
 */
function dbFetchAll($sql, $params = []) {
    $stmt = dbQuery($sql, $params);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}
