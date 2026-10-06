<?php
/** Migration 005: Cadet of the Month awards and scoring linkage. */
return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS cadet_of_month_awards (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        award_month TEXT NOT NULL,
        squadron_id INTEGER,
        class_year TEXT NOT NULL,
        cadet_name TEXT NOT NULL,
        title TEXT NOT NULL,
        description TEXT NOT NULL,
        photo_filename TEXT,
        points_awarded REAL NOT NULL DEFAULT 0,
        event_id INTEGER,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        FOREIGN KEY (squadron_id) REFERENCES squadrons(id) ON DELETE CASCADE,
        FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
    )");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_cotm_squadron_slot ON cadet_of_month_awards(award_month,squadron_id,class_year) WHERE squadron_id IS NOT NULL");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_cotm_group_slot ON cadet_of_month_awards(award_month,class_year) WHERE squadron_id IS NULL");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cotm_month ON cadet_of_month_awards(award_month)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cotm_event ON cadet_of_month_awards(event_id)");
    $stmt=$db->prepare("INSERT OR IGNORE INTO event_type_config(event_type,display_name,description,emoji) VALUES(?,?,?,?)");
    $stmt->execute(['cadet_of_month','Cadet of the Month','Cadet of the Month awards','⭐']);
};
