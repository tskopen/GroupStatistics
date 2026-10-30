<?php
/** Migration 011: attach an optional short description to each OPA publication. */
return static function (PDO $db): void {
    $columns = $db->query('PRAGMA table_info(scoring_publications)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('description', $columns, true)) {
        $db->exec("ALTER TABLE scoring_publications ADD COLUMN description TEXT NOT NULL DEFAULT ''");
    }
};
