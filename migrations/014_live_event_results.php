<?php
/**
 * Migration 014: isolate public live-board results from scoring/leaderboard data.
 *
 * The live board is a presentation/results ledger only. It is deliberately not
 * referenced by OPA publication calculations, legacy aggregate helpers, or
 * intramural scoring.
 */
return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS live_event_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        squadron_id INTEGER,
        event_type TEXT NOT NULL DEFAULT 'other',
        event_name TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        value REAL NOT NULL DEFAULT 0,
        timestamp TEXT NOT NULL,
        created_at TEXT NOT NULL,
        created_by TEXT NOT NULL DEFAULT 'admin',
        legacy_event_id INTEGER UNIQUE,
        FOREIGN KEY (squadron_id) REFERENCES squadrons(id) ON DELETE SET NULL
    )");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_live_event_results_timestamp
        ON live_event_results(timestamp DESC, id DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_live_event_results_type_name
        ON live_event_results(event_type, event_name)");

    // Preserve historical public event cards, but move their result-only rows
    // out of the legacy events scoring ledger. Bracket and Cadet of the Month
    // records stay in events because their dedicated systems link to those rows.
    $db->exec("
        INSERT OR IGNORE INTO live_event_results
            (squadron_id,event_type,event_name,description,value,timestamp,created_at,created_by,legacy_event_id)
        SELECT
            e.squadron_id,
            COALESCE(NULLIF(LOWER(TRIM(e.event_type)),''),'other'),
            COALESCE(NULLIF(TRIM(e.event_name),''),'Event'),
            '',
            COALESCE(e.value,e.points_awarded,0),
            COALESCE(NULLIF(e.timestamp,''),NULLIF(e.created_at,''),datetime('now')),
            COALESCE(NULLIF(e.created_at,''),NULLIF(e.timestamp,''),datetime('now')),
            'migration',
            e.id
        FROM events e
        WHERE LOWER(COALESCE(TRIM(e.event_type),'')) NOT IN ('bracket','cadet_of_month')
          AND NOT EXISTS (
              SELECT 1 FROM bracket_score_events bse WHERE bse.event_id=e.id
          )
          AND NOT EXISTS (
              SELECT 1 FROM cadet_of_month_awards cotm WHERE cotm.event_id=e.id
          )
    ");

    // Delete only source rows that were successfully copied. This also makes
    // the migration safe to retry if a deployment is interrupted mid-upgrade.
    $db->exec("
        DELETE FROM events
        WHERE id IN (
            SELECT legacy_event_id
            FROM live_event_results
            WHERE legacy_event_id IS NOT NULL
        )
    ");

    // Allow descriptive award-style cards as a first-class live-board type.
    $awardType = $db->prepare("
        INSERT OR IGNORE INTO event_type_config(event_type,display_name,description,emoji)
        VALUES(?,?,?,?)
    ");
    $awardType->execute(['award','Awards and Recognition','Award and recognition announcements','🏅']);
};
