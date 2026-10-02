<?php
require_once __DIR__ . '/bracket-schema-migration.php';
require_once __DIR__ . '/bracket-migrate.php';

function bracketBootstrap(PDO $db): void
{
    // Foreign-key enforcement is connection-local in SQLite.
    $db->exec('PRAGMA foreign_keys = ON');
    initBracketTables($db);

    // Migrate legacy brackets only when a legacy file exists. This is
    // idempotent and never overwrites an existing relational bracket.
    $json = DATA_DIR . '/brackets.json';
    migrateLegacyBrackets($db, $json);
}

function bracketSquadrons(): array
{
    $db = getDb();
    $rows = $db->query('SELECT id, name, icon_filename FROM squadrons ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) return $rows;
    $legacy = readJson(DATA_DIR . '/squadrons.json');
    return is_array($legacy) ? $legacy : [];
}
