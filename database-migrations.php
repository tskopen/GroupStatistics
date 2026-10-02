<?php
/** Versioned SQLite migration runner. */
function runDatabaseMigrations(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, filename TEXT NOT NULL, applied_at DATETIME NOT NULL)');
    $files = glob(__DIR__ . '/migrations/*.php') ?: [];
    sort($files, SORT_STRING);

    foreach ($files as $file) {
        $base = basename($file);
        if (!preg_match('/^(\d+)_.*\.php$/', $base, $m)) continue;
        $version = (int)$m[1];
        $stmt = $db->prepare('SELECT 1 FROM schema_migrations WHERE version=?');
        $stmt->execute([$version]);
        if ($stmt->fetchColumn()) continue;

        $migration = require $file;
        if (!is_callable($migration)) {
            throw new RuntimeException("Migration {$base} must return a callable");
        }

        $db->beginTransaction();
        try {
            $migration($db);
            $insert = $db->prepare('INSERT INTO schema_migrations(version,filename,applied_at) VALUES(?,?,?)');
            $insert->execute([$version, $base, date('c')]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw new RuntimeException("Migration {$base} failed: {$e->getMessage()}", 0, $e);
        }
    }
}
