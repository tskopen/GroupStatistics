<?php
/** Migration 007: flexible engagement awards, multi-squadron recipients, and optional photos. */
return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS engagement_awards (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL CHECK (length(trim(title)) BETWEEN 1 AND 160),
        description TEXT NOT NULL CHECK (length(trim(description)) BETWEEN 1 AND 5000),
        award_type TEXT NOT NULL DEFAULT 'description'
            CHECK (award_type IN ('points', 'description', 'both', 'completion')),
        points_awarded REAL,
        teams_url TEXT,
        status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published')),
        created_by TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        CHECK ((award_type IN ('points', 'both') AND points_awarded IS NOT NULL)
            OR (award_type IN ('description', 'completion') AND points_awarded IS NULL))
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS engagement_award_recipients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        award_id INTEGER NOT NULL,
        squadron_id INTEGER NOT NULL,
        points_awarded REAL,
        created_at TEXT NOT NULL,
        UNIQUE (award_id, squadron_id),
        FOREIGN KEY (award_id) REFERENCES engagement_awards(id) ON DELETE CASCADE,
        FOREIGN KEY (squadron_id) REFERENCES squadrons(id) ON DELETE CASCADE
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS engagement_award_photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        award_id INTEGER NOT NULL,
        filename TEXT NOT NULL UNIQUE,
        alt_text TEXT NOT NULL DEFAULT '',
        uploaded_by TEXT,
        created_at TEXT NOT NULL,
        FOREIGN KEY (award_id) REFERENCES engagement_awards(id) ON DELETE CASCADE
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_engagement_awards_status_date
        ON engagement_awards(status, created_at DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_engagement_award_recipients_squadron
        ON engagement_award_recipients(squadron_id, award_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_engagement_award_photos_award
        ON engagement_award_photos(award_id, id)");
};
