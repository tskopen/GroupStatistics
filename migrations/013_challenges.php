<?php
/** Migration 013: challenge posts and manually published submissions. */
return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS challenges (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL CHECK(length(trim(title)) BETWEEN 1 AND 160),
        description TEXT NOT NULL CHECK(length(trim(description)) BETWEEN 1 AND 5000),
        instructions TEXT NOT NULL DEFAULT '',
        starts_at TEXT,
        ends_at TEXT,
        status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published','closed')),
        created_by TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS challenge_submissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        challenge_id INTEGER NOT NULL,
        title TEXT NOT NULL CHECK(length(trim(title)) BETWEEN 1 AND 160),
        description TEXT NOT NULL CHECK(length(trim(description)) BETWEEN 1 AND 5000),
        squadron_id INTEGER,
        submitter_label TEXT,
        source_url TEXT,
        status TEXT NOT NULL DEFAULT 'published' CHECK(status IN ('draft','published')),
        created_by TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(challenge_id) REFERENCES challenges(id) ON DELETE CASCADE,
        FOREIGN KEY(squadron_id) REFERENCES squadrons(id) ON DELETE SET NULL
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_challenges_status_date ON challenges(status, created_at DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_challenge_submissions_challenge ON challenge_submissions(challenge_id,status,created_at)");
    $columns = $db->query("PRAGMA table_info(engagement_awards)")->fetchAll(PDO::FETCH_ASSOC);
    $hasChallenge = false;
    foreach ($columns as $column) if ($column['name'] === 'challenge_id') $hasChallenge = true;
    if (!$hasChallenge) $db->exec("ALTER TABLE engagement_awards ADD COLUMN challenge_id INTEGER REFERENCES challenges(id) ON DELETE SET NULL");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_engagement_awards_challenge ON engagement_awards(challenge_id,status)");
};
