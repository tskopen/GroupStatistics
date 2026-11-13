<?php
/**
 * CLI utility to calculate Group 1 pooled category averages from an XLSX export.
 *
 * Usage:
 *   php scripts/opa-calculate-pooled-averages.php /path/to/export.xlsx [output.csv]
 *
 * If output.csv is omitted, CSV is written to STDOUT. This utility produces a
 * reviewable calculation report; it does not modify drafts or publications.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
if ($argc < 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Usage: php scripts/opa-calculate-pooled-averages.php workbook.xlsx [output.csv]\n");
    exit(2);
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/opa-scoring.php';

$db = getDb();
$categories = opaGetCategories($db);
$sheets = opaReadXlsxWorkbook($argv[1]);
$preview = opaBuildPooledAverageImportPreview($sheets, $categories);

$output = isset($argv[2]) ? fopen($argv[2], 'wb') : STDOUT;
if ($output === false) {
    fwrite(STDERR, "Unable to open CSV output path.\n");
    exit(2);
}
fputcsv($output, ['Category', 'Squadron/Unit', 'Pooled Average (%)', 'Group 1 Rank', 'Individual Scores Count']);
foreach ($preview['records'] as $categoryId => $records) {
    $categoryName = 'Category ' . $categoryId;
    foreach ($categories as $category) {
        if ((int)$category['id'] === (int)$categoryId) {
            $categoryName = (string)$category['display_name'];
            break;
        }
    }
    foreach ($records as $sid => $record) {
        $count = 0;
        if (preg_match('/(\d+)\s+individual scores$/', (string)$record['source_measurement'], $match)) {
            $count = (int)$match[1];
        }
        fputcsv($output, [
            $categoryName,
            'CS' . str_pad((string)$sid, 2, '0', STR_PAD_LEFT),
            $record['category_score'],
            $record['category_rank'] ?? '',
            $count,
        ]);
    }
}
if ($output !== STDOUT) fclose($output);
foreach ($preview['warnings'] as $warning) fwrite(STDERR, "Warning: " . $warning . "\n");
