<?php
/** Migration 010: enforce append-only publication snapshots at the database layer. */
return static function (PDO $db): void {
    foreach ([
        'scoring_publications',
        'scoring_publication_scores',
        'scoring_publication_squadrons',
    ] as $table) {
        $safe = str_replace('_', '', $table);
        $db->exec("CREATE TRIGGER IF NOT EXISTS trg_{$safe}_no_update
            BEFORE UPDATE ON {$table}
            BEGIN SELECT RAISE(ABORT, 'Published scoring snapshots are immutable'); END");
        $db->exec("CREATE TRIGGER IF NOT EXISTS trg_{$safe}_no_delete
            BEFORE DELETE ON {$table}
            BEGIN SELECT RAISE(ABORT, 'Published scoring snapshots are immutable'); END");
    }
};
