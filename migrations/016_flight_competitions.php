<?php
/**
 * Migration 016: isolated squadron flights and Flight vs Flight competitions.
 *
 * Flight competition data is deliberately separate from OPA, intramural,
 * legacy events, brackets, and the group challenge/award systems.
 */
return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS flights (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        squadron_id INTEGER NOT NULL,
        name TEXT NOT NULL CHECK(length(trim(name)) BETWEEN 1 AND 80),
        display_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(squadron_id, name),
        UNIQUE(id, squadron_id),
        FOREIGN KEY(squadron_id) REFERENCES squadrons(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS flight_competitions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        squadron_id INTEGER NOT NULL,
        title TEXT NOT NULL CHECK(length(trim(title)) BETWEEN 1 AND 160),
        description TEXT NOT NULL DEFAULT '',
        starts_at TEXT NOT NULL,
        ends_at TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published')),
        created_by TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(id, squadron_id),
        CHECK(ends_at >= starts_at),
        FOREIGN KEY(squadron_id) REFERENCES squadrons(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS flight_competition_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        competition_id INTEGER NOT NULL,
        flight_id INTEGER NOT NULL,
        squadron_id INTEGER NOT NULL,
        title TEXT NOT NULL DEFAULT '',
        description TEXT NOT NULL CHECK(length(trim(description)) BETWEEN 1 AND 2000),
        points REAL NOT NULL DEFAULT 0,
        awarded_at TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published')),
        created_by TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(competition_id, squadron_id)
            REFERENCES flight_competitions(id, squadron_id) ON DELETE CASCADE,
        FOREIGN KEY(flight_id, squadron_id)
            REFERENCES flights(id, squadron_id) ON DELETE RESTRICT
    )");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_flights_squadron_active
        ON flights(squadron_id, is_active, display_order, name)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_flight_competitions_squadron_status
        ON flight_competitions(squadron_id, status, starts_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_flight_results_competition_status
        ON flight_competition_results(competition_id, status, awarded_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_flight_results_flight
        ON flight_competition_results(flight_id, squadron_id, status)");
};
