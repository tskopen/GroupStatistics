<?php
/** Migration 002: durable notification subscriptions. */
return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS notification_subscriptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        endpoint TEXT NOT NULL UNIQUE,
        auth TEXT NOT NULL,
        p256dh TEXT NOT NULL,
        squadrons_json TEXT NOT NULL DEFAULT '[]',
        all_scores INTEGER NOT NULL DEFAULT 1,
        subscribed_at DATETIME NOT NULL,
        last_active DATETIME NOT NULL
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_notification_active ON notification_subscriptions(last_active)");
};
