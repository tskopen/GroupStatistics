<?php
/**
 * Idempotent bracket migration helper. Include from an admin request after
 * config.php has initialized the database. Legacy brackets.json is read only
 * when a matching SQLite bracket does not already exist.
 */
function migrateLegacyBrackets(PDO $db, string $jsonPath): int
{
    if (!is_file($jsonPath)) return 0;
    if (!function_exists('initBracketTables')) return 0;
    initBracketTables($db);
    $raw = file_get_contents($jsonPath);
    $legacy = json_decode($raw, true);
    if (!is_array($legacy)) return 0;

    $count = 0;
    foreach ($legacy as $bracket) {
        if (empty($bracket['id']) || empty($bracket['name'])) continue;
        $exists = $db->prepare('SELECT 1 FROM brackets WHERE id = ? LIMIT 1');
        $exists->execute([(string)$bracket['id']]);
        if ($exists->fetchColumn()) continue;
        try {
            bracketSave($db, $bracket);
            $count++;
        } catch (Throwable $e) {
            error_log('Bracket migration failed for ' . $bracket['id'] . ': ' . $e->getMessage());
        }
    }
    return $count;
}
