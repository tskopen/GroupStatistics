<?php
/** Migration 001: canonical SQLite schema. */
return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS squadrons (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        description TEXT,
        icon_filename TEXT,
        created_at DATETIME
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        squadron_id INTEGER,
        event_type TEXT,
        event_name TEXT,
        value REAL,
        points_awarded REAL,
        timestamp DATETIME,
        created_at DATETIME,
        FOREIGN KEY (squadron_id) REFERENCES squadrons(id) ON DELETE SET NULL
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS brackets (
        id TEXT PRIMARY KEY,
        name TEXT,
        created_date DATETIME,
        updated_at DATETIME,
        champion_id INTEGER,
        rounds JSON,
        bracket_type TEXT NOT NULL DEFAULT 'multi_round',
        FOREIGN KEY (champion_id) REFERENCES squadrons(id) ON DELETE SET NULL
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS event_type_config (
        event_type TEXT PRIMARY KEY,
        display_name TEXT,
        description TEXT,
        emoji TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS intramural_sports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sport_name TEXT NOT NULL,
        emoji TEXT,
        points_win REAL DEFAULT 0,
        points_loss REAL DEFAULT 0,
        points_bonus_perfect REAL DEFAULT 0,
        created_at DATETIME
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS intramural_games (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sport_id INTEGER,
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
        FOREIGN KEY (sport_id) REFERENCES intramural_sports(id) ON DELETE SET NULL,
        FOREIGN KEY (team1_id) REFERENCES squadrons(id) ON DELETE SET NULL,
        FOREIGN KEY (team2_id) REFERENCES squadrons(id) ON DELETE SET NULL,
        FOREIGN KEY (winner_id) REFERENCES squadrons(id) ON DELETE SET NULL
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS intramural_wl_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        squadron_id INTEGER,
        sport_id INTEGER,
        wins INTEGER DEFAULT 0,
        losses INTEGER DEFAULT 0,
        points_awarded REAL DEFAULT 0,
        updated_at DATETIME,
        FOREIGN KEY (squadron_id) REFERENCES squadrons(id) ON DELETE CASCADE,
        FOREIGN KEY (sport_id) REFERENCES intramural_sports(id) ON DELETE CASCADE,
        UNIQUE(squadron_id, sport_id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS admin_config (key TEXT PRIMARY KEY, value TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS leaderboard_snapshots (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        snapshot_date DATETIME,
        squadron_id INTEGER,
        rank INTEGER,
        total_points REAL,
        FOREIGN KEY (squadron_id) REFERENCES squadrons(id) ON DELETE CASCADE
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS admin_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        created_at DATETIME
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS bracket_participants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        bracket_id TEXT NOT NULL,
        squadron_id INTEGER NOT NULL,
        seed INTEGER,
        created_at DATETIME NOT NULL,
        UNIQUE(bracket_id, squadron_id),
        FOREIGN KEY (bracket_id) REFERENCES brackets(id) ON DELETE CASCADE,
        FOREIGN KEY (squadron_id) REFERENCES squadrons(id) ON DELETE CASCADE
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS bracket_rounds (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        bracket_id TEXT NOT NULL,
        round_number INTEGER NOT NULL,
        name TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE(bracket_id, round_number),
        FOREIGN KEY (bracket_id) REFERENCES brackets(id) ON DELETE CASCADE
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS bracket_matchups (
        id TEXT PRIMARY KEY,
        bracket_id TEXT NOT NULL,
        round_id INTEGER NOT NULL,
        match_number INTEGER NOT NULL,
        team1_id INTEGER,
        team2_id INTEGER,
        team1_source_matchup_id TEXT,
        team2_source_matchup_id TEXT,
        team1_score INTEGER,
        team2_score INTEGER,
        winner_id INTEGER,
        points REAL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'pending',
        next_matchup_id TEXT,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE(round_id, match_number),
        FOREIGN KEY (bracket_id) REFERENCES brackets(id) ON DELETE CASCADE,
        FOREIGN KEY (round_id) REFERENCES bracket_rounds(id) ON DELETE CASCADE,
        FOREIGN KEY (team1_id) REFERENCES squadrons(id) ON DELETE SET NULL,
        FOREIGN KEY (team2_id) REFERENCES squadrons(id) ON DELETE SET NULL,
        FOREIGN KEY (winner_id) REFERENCES squadrons(id) ON DELETE SET NULL
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS bracket_score_events (
        matchup_id TEXT PRIMARY KEY,
        event_id INTEGER NOT NULL UNIQUE,
        FOREIGN KEY (matchup_id) REFERENCES bracket_matchups(id) ON DELETE CASCADE,
        FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_events_squadron ON events(squadron_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_events_timestamp ON events(timestamp)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_intramural_games_sport ON intramural_games(sport_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bracket_rounds ON bracket_rounds(bracket_id, round_number)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bracket_matchups ON bracket_matchups(bracket_id, round_id, match_number)");
};
