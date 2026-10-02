<?php
/**
 * Migration 004: import legacy JSON once, then archive the files.
 * Runtime code never reads these JSON files after this migration succeeds.
 */
return static function (PDO $db): void {
    $dataDir = defined('DATA_DIR') ? DATA_DIR : '/data';
    $imports = [
        'squadrons.json' => function (array $rows) use ($db): void {
            $stmt = $db->prepare('INSERT OR IGNORE INTO squadrons(id,name,description,icon_filename,created_at) VALUES(?,?,?,?,?)');
            foreach ($rows as $row) {
                if (!isset($row['id'], $row['name'])) continue;
                $stmt->execute([(int)$row['id'], $row['name'], $row['description'] ?? null, $row['icon'] ?? $row['icon_filename'] ?? null, $row['created_at'] ?? date('c')]);
            }
        },
        'scores.json' => function (array $rows) use ($db): void {
            $stmt = $db->prepare('INSERT INTO events(squadron_id,event_type,event_name,value,points_awarded,timestamp,created_at) VALUES(?,?,?,?,?,?,?)');
            foreach ($rows as $row) {
                if (!isset($row['squadron_id'])) continue;
                $value = (float)($row['value'] ?? 0);
                $stmt->execute([(int)$row['squadron_id'], strtolower(trim((string)($row['event_type'] ?? 'other'))), $row['event_name'] ?? $row['tournament_name'] ?? 'Event', $value, (float)($row['points_awarded'] ?? $value), $row['timestamp'] ?? date('c'), date('c')]);
            }
        },
        'brackets.json' => function (array $rows) use ($db): void {
            $stmt = $db->prepare('INSERT OR IGNORE INTO brackets(id,name,created_date,updated_at,champion_id,rounds,bracket_type) VALUES(?,?,?,?,?,?,?)');
            foreach ($rows as $row) {
                if (!isset($row['id'])) continue;
                $stmt->execute([(string)$row['id'], $row['name'] ?? 'Bracket', $row['created_date'] ?? $row['created_at'] ?? date('c'), $row['updated_at'] ?? $row['created_date'] ?? date('c'), $row['champion_id'] ?? null, json_encode($row['rounds'] ?? []), $row['bracket_type'] ?? 'multi_round']);
            }
        },
    ];

    foreach ($imports as $filename => $importer) {
        $path = $dataDir . '/' . $filename;
        $marker = $path . '.migrated';
        if (!is_file($path) || is_file($marker)) continue;
        $raw = file_get_contents($path);
        $rows = json_decode($raw ?: '', true);
        if (!is_array($rows)) continue;
        $importer($rows);
        // Keep a local recovery copy, but remove the active runtime filename.
        @rename($path, $path . '.legacy.bak');
        @file_put_contents($marker, date('c'));
    }
};
