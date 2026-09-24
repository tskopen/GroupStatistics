<?php

// Always use the mounted persistent volume at /data
define('DATA_DIR', '/data');

/**
 * Central configuration for persistent data storage.
 *
 * Railway mounts a persistent volume at /data. All application data
 * (SQLite database, JSON files, and uploaded squadron images) must live there
 * so that data survives redeploys/restarts, which otherwise recreate
 * the container filesystem from scratch.
 */

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
 * CRITICAL: Detect and repair corrupted intramural schema.
 *
 * Some databases were created before the intramural_wl_records table
 * gained its sport_id column, resulting in
 * "SQLSTATE[HY000]: General error: 1 no such column: sport_id" errors
 * when recording games. This function detects that condition, backs
 * up any existing intramural data, rebuilds the tables with the
 * correct schema, and restores the backed-up data.
 */
function repairIntramural() {
    $db = getDb();

    try {
        // Check if intramural_wl_records has sport_id column
        $stmt = $db->prepare('PRAGMA table_info(intramural_wl_records)');
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);

        if (!in_array('sport_id', $columns)) {
            error_log('CRITICAL: intramural_wl_records missing sport_id column - rebuilding schema');

            try {
                $db->beginTransaction();

                // Back up existing games (in case any exist)
                $stmt = $db->prepare('SELECT * FROM intramural_games');
                $stmt->execute();
                $gamesBackup = $stmt->fetchAll();

                // Drop the corrupted tables
                $db->exec('DROP TABLE IF EXISTS intramural_wl_records');
                $db->exec('DROP TABLE IF EXISTS intramural_games');
                $db->exec('DROP TABLE IF EXISTS intramural_sports');

                error_log('Dropped corrupted intramural tables');

                // Recreate them in correct order
                $db->exec('
                    CREATE TABLE IF NOT EXISTS intramural_sports (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        sport_name TEXT NOT NULL,
                        emoji TEXT,
                        points_win REAL DEFAULT 0,
                        points_loss REAL DEFAULT 0,
                        points_bonus_perfect REAL DEFAULT 0,
                        created_at DATETIME
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
                        sport_id INTEGER,
                        wins INTEGER DEFAULT 0,
                        losses INTEGER DEFAULT 0,
                        points_awarded REAL DEFAULT 0,
                        updated_at DATETIME,
                        FOREIGN KEY (squadron_id) REFERENCES squadrons(id),
                        FOREIGN KEY (sport_id) REFERENCES intramural_sports(id),
                        UNIQUE(squadron_id, sport_id)
                    )
                ');

                // Restore backed-up games if they exist
                if (!empty($gamesBackup)) {
                    $stmt = $db->prepare('
                        INSERT INTO intramural_games (sport, team1_id, team2_id, team1_score, team2_score, winner_id, game_date, points_team1, points_team2, timestamp, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ');

                    foreach ($gamesBackup as $game) {
                        $stmt->execute([
                            $game['sport'],
                            $game['team1_id'],
                            $game['team2_id'],
                            $game['team1_score'],
                            $game['team2_score'],
                            $game['winner_id'],
                            $game['game_date'],
                            $game['points_team1'],
                            $game['points_team2'],
                            $game['timestamp'],
                            $game['created_at'],
                        ]);
                    }

                    error_log('Restored ' . count($gamesBackup) . ' intramural games');
                }

                $db->commit();
                error_log('Intramural schema rebuilt successfully');
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Failed to repair intramural schema: ' . $e->getMessage());
                throw $e;
            }
        }
    } catch (Exception $e) {
        error_log('Schema verification error: ' . $e->getMessage());
    }
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
        CREATE TABLE IF NOT EXISTS event_type_config (
            event_type TEXT PRIMARY KEY,
            display_name TEXT,
            description TEXT,
            emoji TEXT
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS intramural_sports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sport_name TEXT NOT NULL,
            emoji TEXT,
            points_win REAL DEFAULT 0,
            points_loss REAL DEFAULT 0,
            points_bonus_perfect REAL DEFAULT 0,
            created_at DATETIME
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
            sport_id INTEGER,
            wins INTEGER DEFAULT 0,
            losses INTEGER DEFAULT 0,
            points_awarded REAL DEFAULT 0,
            updated_at DATETIME,
            FOREIGN KEY (squadron_id) REFERENCES squadrons(id),
            FOREIGN KEY (sport_id) REFERENCES intramural_sports(id),
            UNIQUE(squadron_id, sport_id)
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS admin_config (
            key TEXT PRIMARY KEY,
            value TEXT
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS leaderboard_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            snapshot_date DATETIME,
            squadron_id INTEGER,
            rank INTEGER,
            total_points REAL,
            FOREIGN KEY (squadron_id) REFERENCES squadrons(id)
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS admin_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            created_at DATETIME
        )
    ');

    // Repair corrupted intramural schema if needed
    repairIntramural();

    migrateFromJson();
    seedDefaultConfig();

    // Normalize legacy event rows so every score-bearing event has the
    // fields required by both leaderboard calculation and event cards.
    // These updates are idempotent and repair rows created before the
    // SQLite event-write path was made consistent.
    try {
        $db->exec("UPDATE events SET event_type='other' WHERE event_type IS NULL OR TRIM(event_type)=''");
        $db->exec("UPDATE events SET event_name='Event' WHERE event_name IS NULL OR TRIM(event_name)=''");
        $db->exec("UPDATE events SET points_awarded=value WHERE points_awarded IS NULL");
    } catch (Exception $e) {
        error_log('Failed to normalize legacy event rows: ' . $e->getMessage());
    }
}

/**
 * Calculate current squadron rankings using the same logic as the
 * public leaderboard on index.php: the sum of all `events.points_awarded`
 * plus the sum of `intramural_wl_records.points_awarded`, sorted by
 * total points descending. This is the single source of truth for
 * ranking calculations so no other file needs to duplicate the math.
 *
 * Returns an array of rows: ['squadron_id', 'name', 'total', 'rank'].
 */
function getSquadronRankings() 
{
    $db = getDb();

    $stmt = $db->prepare('SELECT id, name FROM squadrons ORDER BY id');
    $stmt->execute();
    $squadrons = $stmt->fetchAll();

    // Defensively initialize every known squadron to 0 up front. This
    // guarantees a squadron with zero non-intramural events (or a
    // squadron added after the events/intramural tables already had
    // data) is still represented in $totals below, rather than being
    // silently skipped when summing.
    $totals = [];
    foreach ($squadrons as $s) {
        $totals[$s['id']] = 0.0;
    }

    // COALESCE handles rows where points_awarded is NULL at the SQL
    // level, but a NULL squadron_id (orphaned event) or a driver that
    // returns null for the aggregate is also handled explicitly here
    // so a bad row can never quietly drop out of the totals.
    $stmt = $db->prepare('SELECT squadron_id, COALESCE(SUM(COALESCE(points_awarded, value, 0)), 0) as total FROM events GROUP BY squadron_id');
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $squadronId = $row['squadron_id'];
        if ($squadronId === null) {
            continue;
        }
        if (!isset($totals[$squadronId])) {
            // Squadron referenced by an event no longer exists in the
            // squadrons table (or wasn't initialized above) - skip it
            // rather than throwing a notice.
            continue;
        }
        $totals[$squadronId] += (float) ($row['total'] ?? 0);
    }

    $stmt = $db->prepare('SELECT squadron_id, COALESCE(SUM(points_awarded), 0) as total FROM intramural_wl_records GROUP BY squadron_id');
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $squadronId = $row['squadron_id'];
        if ($squadronId === null) {
            continue;
        }
        if (!isset($totals[$squadronId])) {
            continue;
        }
        $totals[$squadronId] += (float) ($row['total'] ?? 0);
    }

    $ranked = [];
    foreach ($squadrons as $s) {
        $ranked[] = [
            'squadron_id' => $s['id'],
            'name' => $s['name'],
            'total' => $totals[$s['id']] ?? 0.0,
        ];
    }

    usort($ranked, fn($a, $b) => $b['total'] <=> $a['total']);

    $rank = 1;
    foreach ($ranked as &$row) {
        $row['rank'] = $rank;
        $rank++;
    }
    unset($row);

    return $ranked;
}

/**
 * Capture a snapshot of the current leaderboard (rank + total points
 * for every squadron) into the leaderboard_snapshots table. This is
 * triggered manually by an admin (see admin-record-snapshot.php), not
 * on every page load, so history reflects meaningful checkpoints.
 *
 * Returns the list of ranked rows that were recorded, along with the
 * snapshot timestamp used.
 */
function recordLeaderboardSnapshot() {
    $db = getDb();

    $ranked = getSquadronRankings();
    $snapshotDate = date('c');

    $stmt = $db->prepare('
        INSERT INTO leaderboard_snapshots (snapshot_date, squadron_id, rank, total_points)
        VALUES (?, ?, ?, ?)
    ');

    foreach ($ranked as $row) {
        $stmt->execute([
            $snapshotDate,
            $row['squadron_id'],
            $row['rank'],
            $row['total'],
        ]);
    }

    return [
        'snapshot_date' => $snapshotDate,
        'rankings' => $ranked,
    ];
}

/**
 * Calculate how a squadron's rank has moved since the most recent
 * leaderboard snapshot.
 *
 * Returns an array: ['current_rank', 'previous_rank', 'movement'],
 * where movement is one of "up X", "down X", "same", or "new" (when
 * there is no prior snapshot for that squadron).
 */
function getLeaderboardMovement($squadronId) {
    $db = getDb();

    $ranked = getSquadronRankings();
    $currentRank = null;
    foreach ($ranked as $row) {
        if ($row['squadron_id'] == $squadronId) {
            $currentRank = $row['rank'];
            break;
        }
    }

    // Find the most recent snapshot date that includes this squadron.
    $stmt = $db->prepare('
        SELECT rank, snapshot_date FROM leaderboard_snapshots
        WHERE squadron_id = ?
        ORDER BY snapshot_date DESC
        LIMIT 1
    ');
    $stmt->execute([$squadronId]);
    $previous = $stmt->fetch();

    if (!$previous) {
        return [
            'current_rank' => $currentRank,
            'previous_rank' => null,
            'movement' => 'new',
        ];
    }

    $previousRank = (int) $previous['rank'];
    $diff = $previousRank - $currentRank;

    if ($diff > 0) {
        $movement = 'up ' . $diff;
    } elseif ($diff < 0) {
        $movement = 'down ' . abs($diff);
    } else {
        $movement = 'same';
    }

    return [
        'current_rank' => $currentRank,
        'previous_rank' => $previousRank,
        'movement' => $movement,
    ];
}

/**
 * Migrate legacy JSON "database" files into the SQLite database.
 *
 * Squadrons and brackets are migrated once: after a successful
 * migration those JSON files are renamed to *.bak so subsequent
 * requests skip that step. Scores are handled separately below and
 * are re-checked on every boot (based on whether the events table is
 * empty) so that a failed migration can be retried automatically.
 */
function migrateFromJson() {
    $squadronsPath = DATA_DIR . '/squadrons.json';
    $scoresPath = DATA_DIR . '/scores.json';
    $bracketsPath = DATA_DIR . '/brackets.json';

    $squadronsBak = $squadronsPath . '.bak';
    $bracketsBak = $bracketsPath . '.bak';

    $db = getDb();

    // Migrate squadrons and brackets once (guarded by .bak markers).
    if (!file_exists($squadronsBak) && !file_exists($bracketsBak)
        && (file_exists($squadronsPath) || file_exists($bracketsPath))) {
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
            if (file_exists($bracketsPath)) {
                @rename($bracketsPath, $bracketsBak);
            }

            error_log('Migration from JSON to SQLite completed successfully (squadrons/brackets).');
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Migration from JSON to SQLite failed (squadrons/brackets): ' . $e->getMessage());
        }
    }

    // Migrate scores every time the events table is empty, so a
    // failed attempt is automatically retried on the next request.
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM events');
    $stmt->execute();
    $eventCount = $stmt->fetch()['cnt'];

    if ($eventCount == 0 && file_exists($scoresPath)) {
        $scores = readJson($scoresPath);

        if (!empty($scores)) {
            try {
                $db->beginTransaction();

                $stmt = $db->prepare('
                    INSERT INTO events (squadron_id, event_type, event_name, value, points_awarded, timestamp, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');

                foreach ($scores as $score) {
                    $value = isset($score['value']) ? (float) $score['value'] : 0;
                    $pointsAwarded = isset($score['points_awarded']) ? (float) $score['points_awarded'] : $value;
                    $eventName = $score['event_name'] ?? ($score['tournament_name'] ?? 'Event');
                    $eventType = $score['event_type'] ?? 'other';

                    $stmt->execute([
                        $score['squadron_id'] ?? null,
                        $eventType,
                        $eventName,
                        $value,
                        $pointsAwarded,
                        $score['timestamp'] ?? date('c'),
                        date('c'),
                    ]);
                }

                $db->commit();
                error_log('Migrated ' . count($scores) . ' entries from JSON to SQLite');
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Migration failed: ' . $e->getMessage());
            }
        }
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

/**
 * Restore default USAFA Group 1 squadrons if database is empty.
 * Called after migration to ensure squadrons table is always populated.
 */
function restoreDefaultSquadrons() {
    $db = getDb();

    // Check if squadrons table is empty or incomplete
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM squadrons');
    $stmt->execute();
    $result = $stmt->fetch();

    if ($result['cnt'] < 10) {
        $squadrons = [
            ['id' => 1, 'name' => 'Mighty Mach One', 'description' => 'Symbolized by the griffin and the Maltese Cross, representing strength, vigilance, and a long tradition of honor.', 'icon' => 'squadron-1-1786053165.jpg'],
            ['id' => 2, 'name' => 'Deuce', 'description' => 'Represented by red, white, and blue contrails streaking toward space, symbolizing speed, patriotism, and the reach beyond the atmosphere.', 'icon' => 'squadron-2-1786053174.jpg'],
            ['id' => 3, 'name' => 'Dogs of War', 'description' => 'Embodied by Cerberus and flames, symbolizing ferocity, guardianship, and relentless fighting spirit.', 'icon' => 'squadron-3-1786053181.jpg'],
            ['id' => 4, 'name' => "Fightin' Fourth", 'description' => 'Represented by a prop and wings alongside four classes united, symbolizing aviation heritage and squadron unity across all four years.', 'icon' => 'squadron-4-1786053188.jpg'],
            ['id' => 5, 'name' => 'Wolfpack', 'description' => "Symbolized by a snarling wolf and the rallying cry 'Feed 'em to the wolves!', representing pack mentality and fierce competitiveness.", 'icon' => 'squadron-5-1786053196.jpg'],
            ['id' => 6, 'name' => 'Bull Six', 'description' => 'Represented by a black bull set against a red background, symbolizing raw power, aggression, and intimidation.', 'icon' => 'squadron-6-1786053203.jpg'],
            ['id' => 7, 'name' => 'Shadow Seven', 'description' => 'Symbolized by a unicorn and a lightning bolt, representing mystique, rarity, and swift, unstoppable striking power.', 'icon' => 'squadron-7-1786053209.jpg'],
            ['id' => 8, 'name' => 'Eagle Eight', 'description' => 'Represented by the F-15 Eagle and four class stars, symbolizing air superiority and the collective achievement of every class.', 'icon' => 'squadron-8-1786053221.jpg'],
            ['id' => 9, 'name' => 'Viking Nine', 'description' => 'Symbolized by dragon ships, representing boldness, exploration, and a fearless warrior spirit.', 'icon' => 'squadron-9-1786053228.jpg'],
            ['id' => 10, 'name' => 'Tiger Ten', 'description' => 'Represented by the Flying Tigers and lightning bolts, symbolizing aggression, speed, and a storied legacy of combat excellence.', 'icon' => 'squadron-10-1786053131.jpg'],
        ];

        $stmt = $db->prepare('INSERT OR REPLACE INTO squadrons (id, name, description, icon_filename, created_at) VALUES (?, ?, ?, ?, ?)');
        foreach ($squadrons as $s) {
            $stmt->execute([$s['id'], $s['name'], $s['description'], $s['icon'], date('c')]);
        }

        error_log('Restored 10 default USAFA squadrons to database');
    }
}

initDatabase();

restoreDefaultSquadrons();

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
 * Return the list of allowed admin usernames, sourced from the
 * ADMIN_USERS environment variable (comma-separated). Falls back to a
 * single default "admin" account when the variable is not set, so
 * existing single-password deployments keep working unmodified.
 */
function getAdminUsernames() {
    $raw = $_ENV['ADMIN_USERS'] ?? getenv('ADMIN_USERS');
    if ($raw === false || trim((string) $raw) === '') {
        return ['admin'];
    }

    $usernames = array_map('trim', explode(',', $raw));
    $usernames = array_filter($usernames, fn($u) => $u !== '');

    return array_values($usernames) ?: ['admin'];
}

/**
 * Validate a submitted username/password pair against ADMIN_USERS and
 * ADMIN_PASSWORD environment variables. Supports both a plaintext
 * ADMIN_PASSWORD and a bcrypt/password_hash()'d value (verified via
 * password_verify()).
 */
function verifyAdminCredentials($username, $password) {
    $username = trim((string) $username);
    if ($username === '') {
        return false;
    }

    $allowedUsers = getAdminUsernames();
    if (!in_array($username, $allowedUsers, true)) {
        return false;
    }

    $adminPassword = $_ENV['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD');
    if ($adminPassword === false || $adminPassword === '') {
        return false;
    }

    // Support hashed passwords (password_hash() output starts with $2y$, $2a$, $argon2, etc.)
    $looksHashed = (bool) preg_match('/^\$2[aiy]\$|^\$argon2/', $adminPassword);
    if ($looksHashed) {
        return password_verify($password, $adminPassword);
    }

    return hash_equals($adminPassword, (string) $password);
}

/**
 * Record (or update) a known admin username in the admin_users table
 * for auditing/history purposes. Safe to call on every successful
 * login since username is UNIQUE.
 */
function recordAdminUser($username) {
    $db = getDb();
    $stmt = $db->prepare('INSERT OR IGNORE INTO admin_users (username, created_at) VALUES (?, ?)');
    $stmt->execute([$username, date('c')]);
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
