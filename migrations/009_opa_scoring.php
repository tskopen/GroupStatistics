<?php
/** Migration 009: category-based OPA scoring, editable drafts, and immutable publications. */
return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS scoring_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_key TEXT NOT NULL UNIQUE,
        display_name TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        ranking_direction TEXT NOT NULL DEFAULT 'manual'
            CHECK (ranking_direction IN ('manual', 'higher', 'lower')),
        is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS scoring_drafts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL DEFAULT 'Scoring update',
        status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published', 'discarded')),
        created_by TEXT NOT NULL,
        updated_by TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS scoring_draft_scores (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        draft_id INTEGER NOT NULL,
        category_id INTEGER NOT NULL,
        squadron_id INTEGER NOT NULL,
        category_rank INTEGER CHECK (category_rank IS NULL OR category_rank BETWEEN 1 AND 10),
        category_score REAL CHECK (category_score IS NULL OR (category_score >= 0 AND category_score <= 100)),
        source_rank INTEGER,
        source_measurement TEXT,
        entry_source TEXT NOT NULL DEFAULT 'manual'
            CHECK (entry_source IN ('manual', 'import', 'modified')),
        validation_note TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL,
        UNIQUE (draft_id, category_id, squadron_id),
        FOREIGN KEY (draft_id) REFERENCES scoring_drafts(id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES scoring_categories(id) ON DELETE RESTRICT,
        FOREIGN KEY (squadron_id) REFERENCES squadrons(id) ON DELETE RESTRICT
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS scoring_imports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        draft_id INTEGER NOT NULL,
        file_name TEXT NOT NULL,
        file_sha256 TEXT NOT NULL UNIQUE,
        records_processed INTEGER NOT NULL DEFAULT 0,
        categories_json TEXT NOT NULL DEFAULT '[]',
        imported_by TEXT NOT NULL,
        imported_at TEXT NOT NULL,
        FOREIGN KEY (draft_id) REFERENCES scoring_drafts(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS scoring_publications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_draft_id INTEGER,
        published_at TEXT NOT NULL,
        published_by TEXT NOT NULL,
        data_entry_method TEXT NOT NULL DEFAULT 'manual',
        source_filename TEXT,
        records_processed INTEGER NOT NULL DEFAULT 0,
        categories_json TEXT NOT NULL DEFAULT '[]',
        FOREIGN KEY (source_draft_id) REFERENCES scoring_drafts(id) ON DELETE SET NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS scoring_publication_scores (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        publication_id INTEGER NOT NULL,
        category_id INTEGER,
        category_key TEXT NOT NULL,
        category_name TEXT NOT NULL,
        squadron_id INTEGER NOT NULL,
        category_rank INTEGER NOT NULL CHECK (category_rank BETWEEN 1 AND 10),
        category_score REAL NOT NULL CHECK (category_score >= 0 AND category_score <= 100),
        category_points REAL NOT NULL,
        source_rank INTEGER,
        source_measurement TEXT,
        previous_rank INTEGER,
        rank_change INTEGER,
        previous_score REAL,
        score_change REAL,
        UNIQUE (publication_id, category_key, squadron_id),
        FOREIGN KEY (publication_id) REFERENCES scoring_publications(id) ON DELETE RESTRICT,
        FOREIGN KEY (category_id) REFERENCES scoring_categories(id) ON DELETE SET NULL,
        FOREIGN KEY (squadron_id) REFERENCES squadrons(id) ON DELETE RESTRICT
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS scoring_publication_squadrons (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        publication_id INTEGER NOT NULL,
        squadron_id INTEGER NOT NULL,
        opa REAL NOT NULL,
        overall_rank INTEGER NOT NULL,
        previous_opa REAL,
        opa_change REAL,
        previous_rank INTEGER,
        rank_change INTEGER,
        UNIQUE (publication_id, squadron_id),
        FOREIGN KEY (publication_id) REFERENCES scoring_publications(id) ON DELETE RESTRICT,
        FOREIGN KEY (squadron_id) REFERENCES squadrons(id) ON DELETE RESTRICT
    )");

    $db->exec('CREATE INDEX IF NOT EXISTS idx_scoring_drafts_status ON scoring_drafts(status, updated_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_scoring_draft_scores_draft ON scoring_draft_scores(draft_id, category_id, squadron_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_scoring_publications_date ON scoring_publications(published_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_scoring_publication_squadrons ON scoring_publication_squadrons(publication_id, overall_rank)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_scoring_publication_scores ON scoring_publication_scores(publication_id, category_key, squadron_id)');

    $now = date('c');
    $seed = $db->prepare(
        'INSERT OR IGNORE INTO scoring_categories
         (category_key, display_name, description, ranking_direction, is_active, sort_order, created_at, updated_at)
         VALUES (?, ?, ?, ?, 1, ?, ?, ?)'
    );
    foreach ([
        ['ami', 'AMI', 'AMI performance', 'manual', 10],
        ['sami', 'SAMI', 'SAMI performance', 'manual', 20],
        ['pft', 'PFT', 'Physical Fitness Test performance', 'manual', 30],
    ] as $category) {
        $seed->execute([$category[0], $category[1], $category[2], $category[3], $category[4], $now, $now]);
    }
};
