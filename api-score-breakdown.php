<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require __DIR__ . '/config.php';

$db = getDb();
$squadronId = isset($_GET['squadron_id']) ? (int)$_GET['squadron_id'] : 0;
if ($squadronId < 1 || $squadronId > 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Choose a valid Group 1 squadron.']);
    exit;
}

$stmt = $db->prepare('SELECT id,name FROM squadrons WHERE id=? LIMIT 1');
$stmt->execute([$squadronId]);
$squadron = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$squadron) {
    http_response_code(404);
    echo json_encode(['error' => 'Squadron not found.']);
    exit;
}

$publicationAId = isset($_GET['publication_a']) && $_GET['publication_a'] !== ''
    ? (int)$_GET['publication_a'] : null;
$publicationBId = isset($_GET['publication_b']) && $_GET['publication_b'] !== ''
    ? (int)$_GET['publication_b'] : null;

try {
    $comparison = opaGetSquadronPublicationComparison($db, $squadronId, $publicationAId, $publicationBId);
    if (!$comparison) {
        echo json_encode(['error' => 'Official OPA scores have not been published for this squadron yet.']);
        exit;
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$publicationOptions = array_map(static function (array $publication): array {
    return [
        'id' => (int)$publication['id'],
        'number' => (int)$publication['publication_number'],
        'label' => 'P' . (int)$publication['publication_number'],
        'published_at' => $publication['published_at'],
        'published_by' => $publication['published_by'],
        'data_entry_method' => $publication['data_entry_method'],
        'source_filename' => $publication['source_filename'],
        'records_processed' => (int)$publication['records_processed'],
    ];
}, $comparison['publications']);

$publicationA = $comparison['publication_a'];
$publicationB = $comparison['publication_b'];
$overall = $comparison['overall'];

$serializePublication = static function (array $publication, array $values): array {
    return [
        'id' => (int)$publication['id'],
        'number' => (int)$publication['publication_number'],
        'label' => 'P' . (int)$publication['publication_number'],
        'published_at' => $publication['published_at'],
        'published_by' => $publication['published_by'],
        'data_entry_method' => $publication['data_entry_method'],
        'source_filename' => $publication['source_filename'],
        'opa' => (float)$values['opa'],
        'overall_rank' => (int)$values['rank'],
    ];
};

echo json_encode([
    'squadron_id' => (int)$squadron['id'],
    'squadron_name' => $squadron['name'],
    'publications' => $publicationOptions,
    'publication_a' => $serializePublication($publicationA, $overall['a']),
    'publication_b' => $serializePublication($publicationB, $overall['b']),
    'overall_change' => [
        'opa' => (float)$overall['change']['opa'],
        'rank_movement' => (int)$overall['change']['rank_movement'],
    ],
    'category_count' => (int)$comparison['category_count'],
    'categories' => $comparison['categories'],
], JSON_INVALID_UTF8_SUBSTITUTE);
