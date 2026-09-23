<?php
/**
 * Database migrations for SQLite schema.
 * Safe to run multiple times; uses IF NOT EXISTS.
 * Initializes event_type_config table for modular scoring.
 */
require __DIR__ . '/config.php';

function runMigrations() {
    try {
        $db = getDB();
        
        // Table: event_type_config
        // Stores configurable scoring rules per event type (samis, pft, bracket, intramural, custom, etc.)
        $db->exec("
            CREATE TABLE IF NOT EXISTS event_type_config (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type TEXT UNIQUE NOT NULL,
                display_name TEXT NOT NULL,
                description TEXT,
                points_awarded REAL DEFAULT 0,
                emoji TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Table: intramural_sports (future use, Phase 2)
        // Stores per-sport point rules (basketball, dodgeball, etc.)
        $db->exec("
            CREATE TABLE IF NOT EXISTS intramural_sports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sport_name TEXT UNIQUE NOT NULL,
                emoji TEXT,
                points_win REAL DEFAULT 5,
                points_loss REAL DEFAULT 0,
                points_bonus_perfect REAL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Table: intramural_games (future use, Phase 2)
        // Individual game records for tracking history
        $db->exec("
            CREATE TABLE IF NOT EXISTS intramural_games (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sport_id INTEGER NOT NULL REFERENCES intramural_sports(id),
                team1_id INTEGER NOT NULL REFERENCES squadrons(id),
                team2_id INTEGER NOT NULL REFERENCES squadrons(id),
                team1_score INTEGER,
                team2_score INTEGER,
                winner_id INTEGER REFERENCES squadrons(id),
                points_team1 REAL,
                points_team2 REAL,
                game_date DATE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Table: intramural_wl_records (future use, Phase 2)
        // Aggregated win-loss records per squadron per sport
        $db->exec("
            CREATE TABLE IF NOT EXISTS intramural_wl_records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                squadron_id INTEGER NOT NULL REFERENCES squadrons(id),
                sport_id INTEGER NOT NULL REFERENCES intramural_sports(id),
                wins INTEGER DEFAULT 0,
                losses INTEGER DEFAULT 0,
                points_awarded REAL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(squadron_id, sport_id)
            )
        ");

        // Seed default event types if table is empty
        $count = dbFetchOne("SELECT COUNT(*) as cnt FROM event_type_config");
        if ($count['cnt'] == 0) {
            $defaults = [
                ['samis', 'SAMIS', 'Squadron Assessment & Motivation Initiative System', 10],
                ['pft', 'PFT', 'Physical Fitness Test', 5],
                ['bracket', 'Bracket Tournament', 'Competitive bracket tournament', 0],
                ['ami', 'AMI', 'Air & Military Institutions', 8],
                ['other', 'Other', 'Miscellaneous events', 0],
            ];
            
            foreach ($defaults as [$type, $display, $desc, $pts]) {
                $db->prepare("
                    INSERT OR IGNORE INTO event_type_config (event_type, display_name, description, points_awarded)
                    VALUES (?, ?, ?, ?)
                ")->execute([$type, $display, $desc, $pts]);
            }
        }

        error_log('Database migrations completed successfully');
        return true;
    } catch (PDOException $e) {
        error_log('Migration error: ' . $e->getMessage());
        return false;
    }
}

// Run migrations on include
runMigrations();
