<?php
/** Migration 017: expand OPA workbook categories and configure reconstructed Military weights. */
return static function (PDO $db): void {
    $columns = $db->query('PRAGMA table_info(scoring_categories)')->fetchAll(PDO::FETCH_ASSOC);
    $hasWeight = false;
    foreach ($columns as $column) if ($column['name'] === 'military_weight') $hasWeight = true;
    if (!$hasWeight) $db->exec('ALTER TABLE scoring_categories ADD COLUMN military_weight REAL NOT NULL DEFAULT 0');

    $now = date('c');
    $categories = [
        ['mae', 'Major Assessment Event', 'Major Assessment Event ranking and average', 'higher', 10, 0],
        ['marching', 'Marching', 'Marching ranking and average', 'higher', 20, 0],
        ['sami', 'SAMI', 'SAMI average; contributes to Military score', 'higher', 30, 0.25],
        ['ami', 'AMI', 'AMI average; contributes to Military score', 'higher', 40, 0.25],
        ['pai', 'PAI', 'PAI average; contributes to Military score', 'higher', 50, 0.05],
        ['cai', 'CAI', 'CAI ranking and average', 'higher', 60, 0],
        ['ppnonoticeami', 'PP No-Notice AMI', 'PP No-Notice AMI ranking and average', 'higher', 70, 0],
        ['commissioningeducation', 'Commissioning Education', 'Commissioning Education average; contributes to Military score', 'higher', 80, 0.75],
        ['knowledgetesting', 'Knowledge Testing', 'Knowledge Testing average; contributes to Military score', 'higher', 90, 0.75],
        ['groupcommander', 'Group Commander', 'Group Commander ranking', 'manual', 100, 0],
        ['spotdiscrepancies', 'Spot Discrepancies', 'Spot Discrepancies ranking', 'lower', 110, 0],
    ];
    $upsert = $db->prepare('INSERT INTO scoring_categories
        (category_key,display_name,description,ranking_direction,is_active,sort_order,created_at,updated_at,military_weight)
        VALUES(?,?,?,?,1,?,?,?,?)
        ON CONFLICT(category_key) DO UPDATE SET display_name=excluded.display_name,
        description=excluded.description,ranking_direction=excluded.ranking_direction,
        is_active=1,sort_order=excluded.sort_order,military_weight=excluded.military_weight,updated_at=excluded.updated_at');
    foreach ($categories as $category) {
        $upsert->execute([$category[0],$category[1],$category[2],$category[3],$category[4],$now,$now,$category[5]]);
    }
};
