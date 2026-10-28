<?php
/** Migration 006: public group announcements, separate from scoring events. */
return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS group_announcements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL CHECK (length(trim(title)) BETWEEN 1 AND 120),
        description TEXT NOT NULL CHECK (length(trim(description)) BETWEEN 1 AND 5000),
        start_at TEXT,
        end_at TEXT,
        location TEXT,
        link_url TEXT,
        status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published')),
        created_by TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_group_announcements_status_start
        ON group_announcements(status, start_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_group_announcements_updated
        ON group_announcements(updated_at)");
};
