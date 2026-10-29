<?php
/** Migration 008: Meet the Group directory sections, profiles, and role assignments. */
return static function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS directory_sections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        description TEXT NOT NULL DEFAULT '',
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_visible INTEGER NOT NULL DEFAULT 1 CHECK (is_visible IN (0, 1)),
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS directory_people (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        display_name TEXT NOT NULL,
        bio TEXT NOT NULL DEFAULT '',
        contact TEXT NOT NULL DEFAULT '',
        photo_filename TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS directory_roles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        section_id INTEGER NOT NULL,
        person_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        responsibilities TEXT NOT NULL DEFAULT '',
        sort_order INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published')),
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY (section_id) REFERENCES directory_sections(id) ON DELETE RESTRICT,
        FOREIGN KEY (person_id) REFERENCES directory_people(id) ON DELETE CASCADE
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_directory_sections_order ON directory_sections(is_visible, sort_order, name)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_directory_roles_section ON directory_roles(section_id, status, sort_order, id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_directory_roles_person ON directory_roles(person_id)");
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare("INSERT OR IGNORE INTO directory_sections(name, description, sort_order, is_visible, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)");
    foreach ([
        ['Group Leadership', 'Group-level leadership and command team.', 10],
        ['Squadrons', 'Squadron leadership and points of contact.', 20],
        ['Special Staff', 'Staff roles and group-wide support functions.', 30],
        ['Chaplains and Support', 'Support resources and approved points of contact.', 40],
    ] as [$name, $description, $sort]) {
        $stmt->execute([$name, $description, $sort, $now, $now]);
    }
};
