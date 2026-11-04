<?php
/** Migration 018: individual administrator password hashes. */
return static function (PDO $db): void {
    $columns = $db->query("PRAGMA table_info(admin_users)")->fetchAll(PDO::FETCH_ASSOC);
    $hasHash = false;
    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'password_hash') { $hasHash = true; break; }
    }
    if (!$hasHash) $db->exec("ALTER TABLE admin_users ADD COLUMN password_hash TEXT");
};
