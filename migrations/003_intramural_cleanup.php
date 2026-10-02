<?php
/** Migration 003: normalize intramural game sport identity. */
return static function (PDO $db): void {
    $columns = $db->query('PRAGMA table_info(intramural_games)')->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($columns, 'name');
    if (!in_array('sport_id', $names, true)) {
        $db->exec('ALTER TABLE intramural_games ADD COLUMN sport_id INTEGER REFERENCES intramural_sports(id) ON DELETE SET NULL');
    }

    // Backfill IDs from the legacy sport name where an exact normalized
    // match exists. After this migration, sport_id is authoritative.
    $db->exec("UPDATE intramural_games
        SET sport_id = (
            SELECT s.id FROM intramural_sports s
            WHERE LOWER(TRIM(s.sport_name)) = LOWER(TRIM(intramural_games.sport))
            LIMIT 1
        )
        WHERE sport_id IS NULL AND sport IS NOT NULL AND TRIM(sport) <> ''");

    $db->exec('CREATE INDEX IF NOT EXISTS idx_intramural_games_sport_id ON intramural_games(sport_id)');
};
