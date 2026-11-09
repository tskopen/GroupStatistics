<?php
/** Migration 015: configurable role hierarchy for the Group directory chart. */
return static function (PDO $db): void {
    $columns = $db->query("PRAGMA table_info(directory_roles)")->fetchAll(PDO::FETCH_ASSOC);
    $hasParent = false;
    foreach ($columns as $column) if ($column['name'] === 'parent_role_id') $hasParent = true;
    if (!$hasParent) $db->exec("ALTER TABLE directory_roles ADD COLUMN parent_role_id INTEGER NULL REFERENCES directory_roles(id) ON DELETE SET NULL");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_directory_roles_parent ON directory_roles(parent_role_id)");

    $find = static function (array $terms) use ($db): ?int {
        $stmt = $db->query("SELECT id, title FROM directory_roles WHERE status='published'");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $role) {
            $normalized = strtolower(preg_replace('/[^a-z0-9]+/', '', $role['title']));
            foreach ($terms as $term) {
                if (str_contains($normalized, strtolower(preg_replace('/[^a-z0-9]+/', '', $term)))) return (int)$role['id'];
            }
        }
        return null;
    };
    $commander = $find(['Group commander', 'Gp/CC']);
    $chief = $find(['Group chief of staff', 'Gp/CoS']);
    $deputy = $find(['Deputy group commander', 'Gp/CD']);
    if ($commander) {
        if ($deputy) $db->prepare("UPDATE directory_roles SET parent_role_id=? WHERE id=? AND parent_role_id IS NULL")->execute([$commander, $deputy]);
        if ($chief) $db->prepare("UPDATE directory_roles SET parent_role_id=? WHERE id=? AND parent_role_id IS NULL")->execute([$commander, $chief]);
    }
    if ($chief) {
        foreach ([['Exec','Executive officer'], ['SEL','Senior enlisted leader'], ['First Shirt','First sergeant'], ['Gp/Supt','Staff superintendent']] as $terms) {
            $role = $find($terms);
            if ($role && $role !== $chief) $db->prepare("UPDATE directory_roles SET parent_role_id=? WHERE id=? AND parent_role_id IS NULL")->execute([$chief, $role]);
        }
    }
};
