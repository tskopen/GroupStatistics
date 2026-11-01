<?php
/** Migration 012: audit administrator corrections and removals of published OPA snapshots. */
return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS scoring_publication_corrections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        publication_id INTEGER NOT NULL,
        operation TEXT NOT NULL CHECK (operation IN ('edit', 'delete')),
        reason TEXT NOT NULL,
        performed_by TEXT NOT NULL,
        performed_at TEXT NOT NULL,
        before_json TEXT NOT NULL DEFAULT '{}',
        after_json TEXT NOT NULL DEFAULT '{}'
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_scoring_publication_corrections_publication
        ON scoring_publication_corrections(publication_id, id DESC)');
};
