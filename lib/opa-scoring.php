<?php
/**
 * Category-based OPA scoring and Excel workbook inspection.
 *
 * The event/intramural tables remain intact for historical event cards, but
 * none of these functions reads event points when producing official standings.
 */

function opaNow(): string {
    return date('c');
}

function opaGetCategories(PDO $db, bool $activeOnly = true): array {
    $sql = 'SELECT * FROM scoring_categories';
    if ($activeOnly) $sql .= ' WHERE is_active = 1';
    $sql .= ' ORDER BY sort_order, display_name, id';
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function opaCreateDraft(PDO $db, string $username, string $title = 'Scoring update'): int {
    $now = opaNow();
    $stmt = $db->prepare("INSERT INTO scoring_drafts(title,status,created_by,updated_by,created_at,updated_at)
        VALUES(?, 'draft', ?, ?, ?, ?)");
    $stmt->execute([$title, $username, $username, $now, $now]);
    $draftId = (int)$db->lastInsertId();
    opaEnsureDraftRows($db, $draftId);
    return $draftId;
}

function opaEnsureDraftRows(PDO $db, int $draftId): void {
    $now = opaNow();
    $insert = $db->prepare("INSERT OR IGNORE INTO scoring_draft_scores
        (draft_id,category_id,squadron_id,entry_source,updated_at) VALUES(?,?,?,'manual',?)");
    $squadrons = $db->query('SELECT id FROM squadrons WHERE id BETWEEN 1 AND 10 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    foreach (opaGetCategories($db) as $category) {
        foreach ($squadrons as $squadronId) {
            $insert->execute([$draftId, (int)$category['id'], (int)$squadronId, $now]);
        }
    }
}

function opaGetDraft(PDO $db, int $draftId): ?array {
    $stmt = $db->prepare("SELECT * FROM scoring_drafts WHERE id = ? AND status = 'draft' LIMIT 1");
    $stmt->execute([$draftId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function opaGetDraftRows(PDO $db, int $draftId): array {
    $stmt = $db->prepare("SELECT ds.*, s.name AS squadron_name, c.category_key, c.display_name AS category_name,
            c.ranking_direction, c.is_active, c.sort_order
        FROM scoring_draft_scores ds
        JOIN squadrons s ON s.id = ds.squadron_id
        JOIN scoring_categories c ON c.id = ds.category_id
        WHERE ds.draft_id = ? AND s.id BETWEEN 1 AND 10
        ORDER BY c.sort_order, c.display_name, s.id");
    $stmt->execute([$draftId]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[(int)$row['category_id']][(int)$row['squadron_id']] = $row;
    }
    return $rows;
}

function opaCategoryPoints(?int $rank): ?int {
    return ($rank !== null && $rank >= 1 && $rank <= 10) ? 11 - $rank : null;
}

function opaRecalculateDraftRanks(PDO $db, int $draftId, int $categoryId): void {
    $categoryStmt = $db->prepare('SELECT ranking_direction,display_name FROM scoring_categories WHERE id=? AND is_active=1');
    $categoryStmt->execute([$categoryId]);
    $category = $categoryStmt->fetch(PDO::FETCH_ASSOC);
    if (!$category) throw new RuntimeException('Choose an active category before recalculating ranks.');
    $direction = (string)$category['ranking_direction'];
    if (!in_array($direction, ['higher', 'lower'], true)) throw new RuntimeException('Set this category ranking direction to higher-is-better or lower-is-better before recalculating.');
    $stmt = $db->prepare('SELECT squadron_id,category_score FROM scoring_draft_scores WHERE draft_id=? AND category_id=? AND squadron_id BETWEEN 1 AND 10 ORDER BY squadron_id');
    $stmt->execute([$draftId, $categoryId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 10) throw new RuntimeException($category['display_name'] . ': all ten Group 1 squadrons must have percentage scores before ranks can be recalculated.');
    foreach ($rows as $row) {
        if ($row['category_score'] === null || !is_numeric($row['category_score']) || (float)$row['category_score'] < 0 || (float)$row['category_score'] > 100) {
            throw new RuntimeException($category['display_name'] . ': each squadron needs a percentage score from 0–100 before ranks can be recalculated.');
        }
    }
    usort($rows, static function (array $a, array $b) use ($direction): int {
        $comparison = (float)$a['category_score'] <=> (float)$b['category_score'];
        if ($comparison === 0) return (int)$a['squadron_id'] <=> (int)$b['squadron_id'];
        return $direction === 'higher' ? -$comparison : $comparison;
    });
    for ($index = 1; $index < count($rows); $index++) {
        if (abs((float)$rows[$index - 1]['category_score'] - (float)$rows[$index]['category_score']) < 0.0000001) {
            throw new RuntimeException($category['display_name'] . ': tied percentage scores require manual rank resolution; automatic unique rankings were not applied.');
        }
    }
    $update = $db->prepare("UPDATE scoring_draft_scores SET category_rank=?,rank_source=CASE WHEN rank_source IN ('import','modified') THEN 'modified' ELSE 'manual' END,entry_source=CASE WHEN entry_source IN ('import','modified') THEN 'modified' ELSE 'manual' END,updated_at=? WHERE draft_id=? AND category_id=? AND squadron_id=?");
    foreach ($rows as $index => $row) $update->execute([$index + 1, opaNow(), $draftId, $categoryId, (int)$row['squadron_id']]);
}

function opaGetDraftPreview(PDO $db, int $draftId): array {
    $categories = opaGetCategories($db);
    $rows = opaGetDraftRows($db, $draftId);
    $squadrons = $db->query('SELECT id,name FROM squadrons WHERE id BETWEEN 1 AND 10 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $preview = [];
    foreach ($squadrons as $squadron) {
        $scores = [];
        $points = [];
        $complete = count($categories) > 0;
        foreach ($categories as $category) {
            $categoryId = (int)$category['id'];
            $record = $rows[$categoryId][(int)$squadron['id']] ?? [];
            $rank = isset($record['category_rank']) ? (int)$record['category_rank'] : null;
            $score = ($record['category_score'] ?? null) !== null ? (float)$record['category_score'] : null;
            $points[$categoryId] = opaCategoryPoints($rank);
            if ($score === null) $complete = false;
            $scores[] = $score;
        }
        $opa = ($complete && $scores) ? array_sum($scores) / count($scores) : null;
        $preview[] = [
            'squadron_id' => (int)$squadron['id'],
            'name' => $squadron['name'],
            'opa' => $opa,
            'category_points' => $points,
            'complete' => $complete,
        ];
    }
    usort($preview, static function (array $a, array $b): int {
        if ($a['opa'] === null && $b['opa'] === null) return $a['squadron_id'] <=> $b['squadron_id'];
        if ($a['opa'] === null) return 1;
        if ($b['opa'] === null) return -1;
        $diff = (float)$b['opa'] - (float)$a['opa'];
        return abs($diff) < 0.0000001 ? ($a['squadron_id'] <=> $b['squadron_id']) : ($diff < 0 ? -1 : 1);
    });
    $rank = 0;
    $lastOpa = null;
    foreach ($preview as $index => &$row) {
        if ($row['opa'] === null) {
            $row['rank'] = null;
            continue;
        }
        if ($lastOpa === null || abs((float)$row['opa'] - (float)$lastOpa) >= 0.0000001) {
            $rank = $index + 1;
            $lastOpa = (float)$row['opa'];
        }
        $row['rank'] = $rank;
    }
    unset($row);
    return $preview;
}

function opaValidateDraft(PDO $db, int $draftId): array {
    $categories = opaGetCategories($db);
    $rows = opaGetDraftRows($db, $draftId);
    $squadrons = $db->query('SELECT id,name FROM squadrons WHERE id BETWEEN 1 AND 10 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $errors = [];
    $warnings = [];
    if (!$categories) $errors[] = 'At least one scoring category must be active.';
    if (count($squadrons) !== 10) $errors[] = 'The database must contain Squadrons 1–10 before publishing.';

    foreach ($categories as $category) {
        $categoryId = (int)$category['id'];
        $seenRanks = [];
        $rankScoreRows = [];
        foreach ($squadrons as $squadron) {
            $sid = (int)$squadron['id'];
            $row = $rows[$categoryId][$sid] ?? null;
            $rank = $row && $row['category_rank'] !== null ? (int)$row['category_rank'] : null;
            $score = $row && $row['category_score'] !== null ? (float)$row['category_score'] : null;
            if ($rank === null) $errors[] = $category['display_name'] . ': Squadron ' . $sid . ' is missing a rank.';
            elseif ($rank < 1 || $rank > 10) $errors[] = $category['display_name'] . ': Squadron ' . $sid . ' rank must be between 1 and 10.';
            elseif (isset($seenRanks[$rank])) $errors[] = $category['display_name'] . ': rank ' . $rank . ' is assigned to more than one squadron.';
            else $seenRanks[$rank] = $sid;
            if ($score === null || $score < 0 || $score > 100) $errors[] = $category['display_name'] . ': Squadron ' . $sid . ' needs a percentage score from 0–100.';
            if ($score !== null && $rank !== null) $rankScoreRows[] = ['rank' => $rank, 'score' => $score];
        }
        if (count($rankScoreRows) === 10 && in_array($category['ranking_direction'], ['higher', 'lower'], true)) {
            usort($rankScoreRows, static fn($a, $b) => $a['rank'] <=> $b['rank']);
            for ($i = 1; $i < count($rankScoreRows); $i++) {
                $previous = $rankScoreRows[$i - 1]['score'];
                $current = $rankScoreRows[$i]['score'];
                $inconsistent = $category['ranking_direction'] === 'higher'
                    ? ($current > $previous + 0.0000001)
                    : ($current < $previous - 0.0000001);
                if ($inconsistent) {
                    $warnings[] = $category['display_name'] . ': the entered rank order may conflict with the configured score direction. Review the percentage scores and rankings.';
                    break;
                }
            }
        }
    }
    return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
}

function opaPublishDraft(PDO $db, int $draftId, string $username): array {
    $draft = opaGetDraft($db, $draftId);
    if (!$draft) throw new RuntimeException('The selected draft is no longer available.');
    $validation = opaValidateDraft($db, $draftId);
    if ($validation['errors']) throw new RuntimeException(implode("\n", $validation['errors']));
    $categories = opaGetCategories($db);
    $rows = opaGetDraftRows($db, $draftId);
    $squadrons = $db->query('SELECT id,name FROM squadrons WHERE id BETWEEN 1 AND 10 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $previousPublication = $db->query('SELECT id FROM scoring_publications ORDER BY id DESC LIMIT 1')->fetchColumn();
    $previousId = $previousPublication !== false ? (int)$previousPublication : null;

    $opaRows = [];
    foreach ($squadrons as $squadron) {
        $sid = (int)$squadron['id'];
        $values = [];
        foreach ($categories as $category) $values[] = (float)$rows[(int)$category['id']][$sid]['category_score'];
        $opaRows[] = ['squadron_id' => $sid, 'opa' => array_sum($values) / count($values)];
    }
    usort($opaRows, static function (array $a, array $b): int {
        $diff = $b['opa'] - $a['opa'];
        return abs($diff) < 0.0000001 ? ($a['squadron_id'] <=> $b['squadron_id']) : ($diff < 0 ? -1 : 1);
    });

    $rankBySquadron = [];
    $lastOpa = null;
    $rank = 0;
    foreach ($opaRows as $index => $row) {
        if ($lastOpa === null || abs($row['opa'] - $lastOpa) >= 0.0000001) {
            $rank = $index + 1;
            $lastOpa = $row['opa'];
        }
        $rankBySquadron[$row['squadron_id']] = $rank;
    }

    $methodRows = $db->prepare("SELECT category_rank,category_score,rank_source,score_source FROM scoring_draft_scores
        WHERE draft_id = ? AND category_id IN (SELECT id FROM scoring_categories WHERE is_active=1)");
    $methodRows->execute([$draftId]);
    $hasManualValues = false;
    foreach ($methodRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['category_rank'] !== null && in_array($row['rank_source'], ['manual', 'modified'], true)) $hasManualValues = true;
        if ($row['category_score'] !== null && in_array($row['score_source'], ['manual', 'modified'], true)) $hasManualValues = true;
    }
    $importCountStmt = $db->prepare('SELECT COUNT(*) FROM scoring_imports WHERE draft_id=?');
    $importCountStmt->execute([$draftId]);
    $hasImports = (int)$importCountStmt->fetchColumn() > 0;
    $method = $hasImports && $hasManualValues ? 'mixed' : ($hasImports ? 'excel' : 'manual');
    $filenameStmt = $db->prepare('SELECT file_name FROM scoring_imports WHERE draft_id=? ORDER BY id DESC LIMIT 1');
    $filenameStmt->execute([$draftId]);
    $filename = $filenameStmt->fetchColumn();
    $categoryNames = array_map(static fn($c) => $c['display_name'], $categories);
    $now = opaNow();

    $db->beginTransaction();
    try {
        $insertPub = $db->prepare("INSERT INTO scoring_publications
            (source_draft_id,published_at,published_by,data_entry_method,source_filename,records_processed,categories_json)
            VALUES(?,?,?,?,?,?,?)");
        $insertPub->execute([$draftId, $now, $username, $method, $filename ?: null, count($squadrons) * count($categories), json_encode($categoryNames)]);
        $publicationId = (int)$db->lastInsertId();

        $previousSquadronStmt = $previousId ? $db->prepare('SELECT opa,overall_rank FROM scoring_publication_squadrons WHERE publication_id=? AND squadron_id=?') : null;
        $insertSquadron = $db->prepare("INSERT INTO scoring_publication_squadrons
            (publication_id,squadron_id,opa,overall_rank,previous_opa,opa_change,previous_rank,rank_change)
            VALUES(?,?,?,?,?,?,?,?)");
        foreach ($opaRows as $row) {
            $sid = (int)$row['squadron_id'];
            $previousOpa = null;
            $previousRank = null;
            if ($previousSquadronStmt) {
                $previousSquadronStmt->execute([$previousId, $sid]);
                $previous = $previousSquadronStmt->fetch(PDO::FETCH_ASSOC);
                if ($previous) {
                    $previousOpa = (float)$previous['opa'];
                    $previousRank = (int)$previous['overall_rank'];
                }
            }
            $insertSquadron->execute([
                $publicationId, $sid, $row['opa'], $rankBySquadron[$sid],
                $previousOpa, $previousOpa !== null ? $row['opa'] - $previousOpa : null,
                $previousRank, $previousRank !== null ? $previousRank - $rankBySquadron[$sid] : null,
            ]);
        }

        $previousCategoryStmt = $previousId ? $db->prepare('SELECT category_rank,category_score FROM scoring_publication_scores WHERE publication_id=? AND category_key=? AND squadron_id=?') : null;
        $insertScore = $db->prepare("INSERT INTO scoring_publication_scores
            (publication_id,category_id,category_key,category_name,squadron_id,category_rank,category_score,category_points,source_rank,source_measurement,previous_rank,rank_change,previous_score,score_change)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($categories as $category) {
            $categoryId = (int)$category['id'];
            foreach ($squadrons as $squadron) {
                $sid = (int)$squadron['id'];
                $draftRow = $rows[$categoryId][$sid];
                $currentRank = (int)$draftRow['category_rank'];
                $currentScore = (float)$draftRow['category_score'];
                $previousRank = null;
                $previousScore = null;
                if ($previousCategoryStmt) {
                    $previousCategoryStmt->execute([$previousId, $category['category_key'], $sid]);
                    $previous = $previousCategoryStmt->fetch(PDO::FETCH_ASSOC);
                    if ($previous) {
                        $previousRank = (int)$previous['category_rank'];
                        $previousScore = (float)$previous['category_score'];
                    }
                }
                $insertScore->execute([
                    $publicationId, $categoryId, $category['category_key'], $category['display_name'],
                    $sid, $currentRank, $currentScore, 11 - $currentRank,
                    $draftRow['source_rank'] !== null ? (int)$draftRow['source_rank'] : null,
                    $draftRow['source_measurement'],
                    $previousRank, $previousRank !== null ? $previousRank - $currentRank : null,
                    $previousScore, $previousScore !== null ? $currentScore - $previousScore : null,
                ]);
            }
        }

        $db->prepare("UPDATE scoring_drafts SET status='published',updated_by=?,updated_at=? WHERE id=?")
            ->execute([$username, $now, $draftId]);
        $db->commit();
        return ['publication_id' => $publicationId, 'published_at' => $now, 'warnings' => $validation['warnings']];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function opaGetLatestPublicationId(PDO $db): ?int {
    $value = $db->query('SELECT id FROM scoring_publications ORDER BY id DESC LIMIT 1')->fetchColumn();
    return $value === false ? null : (int)$value;
}

function opaGetPublishedLeaderboard(PDO $db, ?int $publicationId = null): array {
    if ($publicationId === null) $publicationId = opaGetLatestPublicationId($db);
    if ($publicationId === null) return [];
    $stmt = $db->prepare("SELECT ps.squadron_id, s.name, ps.opa AS total, ps.opa, ps.overall_rank AS rank,
            ps.previous_opa, ps.opa_change, ps.previous_rank, ps.rank_change
        FROM scoring_publication_squadrons ps
        JOIN squadrons s ON s.id = ps.squadron_id
        WHERE ps.publication_id = ?
        ORDER BY ps.overall_rank, s.id");
    $stmt->execute([$publicationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function opaGetSquadronBreakdown(PDO $db, int $squadronId): ?array {
    $stmt = $db->prepare('SELECT id,published_at FROM scoring_publications ORDER BY id DESC LIMIT 2');
    $stmt->execute();
    $publications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$publications) return null;
    $latestId = (int)$publications[0]['id'];
    $previousId = isset($publications[1]) ? (int)$publications[1]['id'] : null;
    $latestStmt = $db->prepare('SELECT * FROM scoring_publication_squadrons WHERE publication_id=? AND squadron_id=?');
    $latestStmt->execute([$latestId, $squadronId]);
    $latest = $latestStmt->fetch(PDO::FETCH_ASSOC);
    if (!$latest) return null;

    $previous = null;
    if ($previousId !== null) {
        $latestStmt->execute([$previousId, $squadronId]);
        $previous = $latestStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $categoryStmt = $db->prepare('SELECT * FROM scoring_publication_scores WHERE publication_id=? AND squadron_id=? ORDER BY category_name');
    $categoryStmt->execute([$latestId, $squadronId]);
    $categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
    $historyStmt = $db->prepare('SELECT p.id,p.published_at,ps.opa,ps.overall_rank
        FROM scoring_publication_squadrons ps JOIN scoring_publications p ON p.id=ps.publication_id
        WHERE ps.squadron_id=? ORDER BY p.id DESC');
    $historyStmt->execute([$squadronId]);
    return [
        'publication' => $publications[0],
        'current' => $latest,
        'previous' => $previous,
        'categories' => $categories,
        'history' => $historyStmt->fetchAll(PDO::FETCH_ASSOC),
    ];
}

/**
 * Compare any two immutable publication snapshots for one squadron.
 * Change convention: positive rank movement means the squadron improved;
 * score, points, and OPA changes are Publication B minus Publication A.
 */
function opaGetSquadronPublicationComparison(PDO $db, int $squadronId, ?int $publicationAId = null, ?int $publicationBId = null): ?array {
    $publicationRows = $db->query('SELECT id,published_at,published_by,data_entry_method,source_filename,records_processed,categories_json
        FROM scoring_publications ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    if (!$publicationRows) return null;

    $publications = [];
    $byId = [];
    foreach ($publicationRows as $index => $publication) {
        $publication['publication_number'] = $index + 1;
        $publication['label'] = 'P' . ($index + 1);
        $publications[] = $publication;
        $byId[(int)$publication['id']] = $publication;
    }

    $latest = $publications[count($publications) - 1];
    $defaultA = count($publications) > 1 ? $publications[count($publications) - 2] : $latest;
    $publicationAId = $publicationAId ?? (int)$defaultA['id'];
    $publicationBId = $publicationBId ?? (int)$latest['id'];
    if (!isset($byId[$publicationAId]) || !isset($byId[$publicationBId])) {
        throw new InvalidArgumentException('Choose two valid published scoring periods.');
    }
    $publicationA = $byId[$publicationAId];
    $publicationB = $byId[$publicationBId];

    $overallStmt = $db->prepare('SELECT opa,overall_rank FROM scoring_publication_squadrons WHERE publication_id=? AND squadron_id=?');
    $overallStmt->execute([$publicationAId, $squadronId]);
    $overallA = $overallStmt->fetch(PDO::FETCH_ASSOC);
    $overallStmt->execute([$publicationBId, $squadronId]);
    $overallB = $overallStmt->fetch(PDO::FETCH_ASSOC);
    if (!$overallA || !$overallB) return null;

    $categoryStmt = $db->prepare('SELECT category_key,category_name,category_rank,category_score,category_points,source_rank,source_measurement
        FROM scoring_publication_scores WHERE publication_id=? AND squadron_id=? ORDER BY category_name');
    $categoryStmt->execute([$publicationAId, $squadronId]);
    $categoryA = [];
    foreach ($categoryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $categoryA[$row['category_key']] = $row;
    $categoryStmt->execute([$publicationBId, $squadronId]);
    $categoryB = [];
    foreach ($categoryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $categoryB[$row['category_key']] = $row;

    $categoryKeys = array_values(array_unique(array_merge(array_keys($categoryA), array_keys($categoryB))));
    $categories = [];
    foreach ($categoryKeys as $key) {
        $a = $categoryA[$key] ?? null;
        $b = $categoryB[$key] ?? null;
        $name = $b['category_name'] ?? ($a['category_name'] ?? $key);
        $toValue = static function (?array $row): ?array {
            if ($row === null) return null;
            return [
                'rank' => (int)$row['category_rank'],
                'score' => (float)$row['category_score'],
                'points' => (float)$row['category_points'],
                'source_rank' => $row['source_rank'] !== null ? (int)$row['source_rank'] : null,
                'source_measurement' => $row['source_measurement'],
            ];
        };
        $valueA = $toValue($a);
        $valueB = $toValue($b);
        $categories[] = [
            'key' => $key,
            'name' => $name,
            'a' => $valueA,
            'b' => $valueB,
            'change' => [
                'rank_movement' => ($valueA !== null && $valueB !== null) ? $valueA['rank'] - $valueB['rank'] : null,
                'score' => ($valueA !== null && $valueB !== null) ? $valueB['score'] - $valueA['score'] : null,
                'points' => ($valueA !== null && $valueB !== null) ? $valueB['points'] - $valueA['points'] : null,
            ],
        ];
    }
    usort($categories, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

    $opaA = (float)$overallA['opa'];
    $opaB = (float)$overallB['opa'];
    $rankA = (int)$overallA['overall_rank'];
    $rankB = (int)$overallB['overall_rank'];
    return [
        'publications' => $publications,
        'publication_a' => $publicationA,
        'publication_b' => $publicationB,
        'overall' => [
            'a' => ['opa' => $opaA, 'rank' => $rankA],
            'b' => ['opa' => $opaB, 'rank' => $rankB],
            'change' => [
                'opa' => $opaB - $opaA,
                'rank_movement' => $rankA - $rankB,
            ],
        ],
        'categories' => $categories,
        'category_count' => count($categories),
    ];
}

/** Read an .xlsx workbook using PHP's ZipArchive and SimpleXML extensions. */
function opaReadXlsxWorkbook(string $filePath): array {
    if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) {
        throw new RuntimeException('This server cannot inspect .xlsx files because ZipArchive and SimpleXML support are required. Manual score entry remains available.');
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) throw new RuntimeException('The workbook could not be opened as a valid .xlsx file.');
    try {
        if ($zip->numFiles > 500) throw new RuntimeException('The workbook contains too many ZIP entries to inspect safely.');
        $expandedBytes = 0;
        for ($entryIndex = 0; $entryIndex < $zip->numFiles; $entryIndex++) {
            $stat = $zip->statIndex($entryIndex);
            $expandedBytes += (int)($stat['size'] ?? 0);
            if ($expandedBytes > 30000000) throw new RuntimeException('The expanded workbook is too large to inspect safely (30 MB limit).');
        }
        $mainNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $pkgRelNs = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) throw new RuntimeException('The workbook is missing required worksheet metadata.');
        $workbook = @simplexml_load_string($workbookXml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOBLANKS);
        $relationships = @simplexml_load_string($relsXml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOBLANKS);
        if (!$workbook || !$relationships) throw new RuntimeException('The workbook metadata is malformed.');
        $percentageStyles = [];
        $stylesXml = $zip->getFromName('xl/styles.xml');
        if ($stylesXml !== false) {
            $styles = @simplexml_load_string($stylesXml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOBLANKS);
            if ($styles) {
                $styles->registerXPathNamespace('m', $mainNs);
                $percentFormatIds = [9, 10];
                foreach (($styles->xpath('//m:numFmts/m:numFmt') ?: []) as $format) {
                    if (strpos((string)$format['formatCode'], '%') !== false) $percentFormatIds[] = (int)$format['numFmtId'];
                }
                foreach (($styles->xpath('//m:cellXfs/m:xf') ?: []) as $styleIndex => $format) {
                    if (in_array((int)$format['numFmtId'], $percentFormatIds, true)) $percentageStyles[(int)$styleIndex] = true;
                }
            }
        }
        $strings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $shared = @simplexml_load_string($sharedXml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOBLANKS);
            if ($shared) {
                $shared->registerXPathNamespace('m', $mainNs);
                foreach (($shared->xpath('//m:si') ?: []) as $item) {
                    $parts = $item->xpath('.//m:t') ?: [];
                    $value = '';
                    foreach ($parts as $part) $value .= (string)$part;
                    $strings[] = $value;
                }
            }
        }
        $relationships->registerXPathNamespace('r', $pkgRelNs);
        $targets = [];
        foreach (($relationships->xpath('//r:Relationship') ?: []) as $relationship) {
            $targets[(string)$relationship['Id']] = (string)$relationship['Target'];
        }
        $workbook->registerXPathNamespace('m', $mainNs);
        $sheets = [];
        foreach (($workbook->xpath('//m:sheets/m:sheet') ?: []) as $sheet) {
            $rid = (string)$sheet->attributes($relNs)['id'];
            $target = $targets[$rid] ?? '';
            if ($target === '') continue;
            $target = str_replace('\\', '/', ltrim($target, '/'));
            if (strpos($target, '../') !== false || $target === '') continue;
            if (strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;
            $sheetXml = $zip->getFromName($target);
            if ($sheetXml === false) continue;
            $xml = @simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOBLANKS);
            if (!$xml) continue;
            $xml->registerXPathNamespace('m', $mainNs);
            $rows = [];
            foreach (($xml->xpath('//m:sheetData/m:row') ?: []) as $row) {
                $rowValues = [];
                foreach (($row->xpath('./m:c') ?: []) as $cell) {
                    $reference = (string)$cell['r'];
                    if (!preg_match('/^([A-Z]+)/i', $reference, $match)) continue;
                    $column = 0;
                    foreach (str_split(strtoupper($match[1])) as $letter) $column = $column * 26 + ord($letter) - 64;
                    $column--;
                    $type = (string)$cell['t'];
                    $styleIndex = (int)$cell['s'];
                    $valueNode = $cell->xpath('./m:v');
                    $value = $valueNode ? (string)$valueNode[0] : '';
                    if ($type === 's') $value = $strings[(int)$value] ?? '';
                    elseif ($type === 'inlineStr') {
                        $parts = $cell->xpath('.//m:is//m:t') ?: [];
                        $value = '';
                        foreach ($parts as $part) $value .= (string)$part;
                    }
                    if ($type !== 's' && $type !== 'inlineStr' && isset($percentageStyles[$styleIndex]) && is_numeric($value)) {
                        $value = (string)((float)$value * 100) . '%';
                    }
                    if ($value !== '') $rowValues[$column] = $value;
                }
                if ($rowValues) {
                    ksort($rowValues);
                    $rows[] = ['row_number' => (int)$row['r'], 'cells' => $rowValues];
                }
            }
            $sheets[] = ['name' => (string)$sheet['name'], 'rows' => $rows];
        }
        if (!$sheets) throw new RuntimeException('No readable worksheets were found in the workbook.');
        return $sheets;
    } finally {
        $zip->close();
    }
}

function opaNormalizeHeader(string $value): string {
    $value = strtolower(trim($value));
    $value = str_replace('%', ' percent ', $value);
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

function opaFindHeaderColumn(array $headers, array $aliases, bool $contains = false): ?int {
    foreach ($headers as $index => $header) {
        $normalized = opaNormalizeHeader((string)$header);
        foreach ($aliases as $alias) {
            if ($normalized === $alias || ($contains && $alias !== '' && strpos($normalized, $alias) !== false)) return (int)$index;
        }
    }
    return null;
}

function opaParseSquadronIdentifier($value): ?int {
    $value = trim((string)$value);
    if ($value === '') return null;
    if (preg_match('/^\d{1,2}$/', $value)) return (int)$value;
    if (preg_match('/(?:squadron|sq)\s*#?\s*0*(\d{1,2})\b/i', $value, $match)) return (int)$match[1];
    if (preg_match('/\b0*(\d{1,2})(?:st|nd|rd|th)?\s+(?:squadron|sq)\b/i', $value, $match)) return (int)$match[1];
    return null;
}

function opaParseNumericValue($value): ?float {
    if ($value === null || trim((string)$value) === '') return null;
    $text = str_replace([',', chr(36)], '', trim((string)$value));
    $text = ltrim($text, '#');
    $text = preg_replace('/\s+/', '', $text) ?? $text;
    $text = preg_replace('/(st|nd|rd|th)$/i', '', $text) ?? $text;
    if (!is_numeric(rtrim($text, '%'))) return null;
    return (float)rtrim($text, '%');
}

function opaMatchCategory(string $label, array $categories): ?array {
    $needle = opaNormalizeHeader($label);
    if ($needle === '') return null;
    $matches = [];
    foreach ($categories as $category) {
        $key = opaNormalizeHeader((string)$category['category_key']);
        $name = opaNormalizeHeader((string)$category['display_name']);
        if ($needle === $key || $needle === $name || ($key !== '' && strpos($needle, $key) === 0)) $matches[] = $category;
    }
    return count($matches) === 1 ? $matches[0] : null;
}

/**
 * Identify a table by its header labels, rather than assuming a worksheet name,
 * fixed column order, or fixed row number. Unknown layouts are reported, not guessed.
 */
function opaBuildImportPreview(array $sheets, array $categories): array {
    $records = [];
    $warnings = [];
    $identifiedSheets = 0;
    foreach ($sheets as $sheet) {
        $headerIndex = null;
        $columns = null;
        foreach (array_slice($sheet['rows'], 0, 25) as $index => $row) {
            $headers = $row['cells'];
            $squadronColumn = opaFindHeaderColumn($headers, ['squadron', 'squadronid', 'squadronnumber', 'squadronno', 'squadronname', 'sq', 'sqnumber', 'unit'], true);
            $rankColumn = opaFindHeaderColumn($headers, ['rank', 'sourcerank', 'overallrank', 'placement', 'place', 'position', 'standing'], true);
            $percentColumn = opaFindHeaderColumn($headers, ['categoryscore', 'percentagescore', 'percent', 'percentage', 'scorepercent', 'scorepct', 'pctscore'], true);
            $rawColumn = opaFindHeaderColumn($headers, ['rawmeasurement', 'measurement', 'performancemeasurement', 'performancevalue', 'metricvalue', 'resultvalue'], true);
            $categoryColumn = opaFindHeaderColumn($headers, ['category', 'scoringcategory'], true);
            if ($squadronColumn !== null && ($rankColumn !== null || $percentColumn !== null || $rawColumn !== null)) {
                $headerIndex = $index;
                $columns = [
                    'squadron' => $squadronColumn, 'rank' => $rankColumn,
                    'percent' => $percentColumn, 'raw' => $rawColumn, 'category' => $categoryColumn,
                ];
                break;
            }
        }
        if ($headerIndex === null || $columns === null) {
            $warnings[] = 'Worksheet "' . $sheet['name'] . '" was skipped because its header row could not be reliably identified.';
            continue;
        }
        $identifiedSheets++;
        $sheetCategory = opaMatchCategory((string)$sheet['name'], $categories);
        $sheetRecords = [];
        foreach (array_slice($sheet['rows'], $headerIndex + 1) as $row) {
            $cells = $row['cells'];
            $squadronId = opaParseSquadronIdentifier($cells[$columns['squadron']] ?? '');
            if ($squadronId === null || $squadronId < 1 || $squadronId > 10) continue;
            $category = $sheetCategory;
            if ($columns['category'] !== null) {
                $rowCategory = opaMatchCategory((string)($cells[$columns['category']] ?? ''), $categories);
                if ($rowCategory) $category = $rowCategory;
            }
            if (!$category || (int)$category['is_active'] !== 1) {
                $warnings[] = 'Worksheet "' . $sheet['name'] . '" includes Group 1 data but its category could not be matched to an active scoring category.';
                continue;
            }
            $sourceRank = $columns['rank'] !== null ? opaParseNumericValue($cells[$columns['rank']] ?? null) : null;
            $score = $columns['percent'] !== null ? opaParseNumericValue($cells[$columns['percent']] ?? null) : null;
            if ($score !== null && $score > 0 && $score <= 1 && $columns['percent'] !== null && strpos((string)($cells[$columns['percent']] ?? ''), '%') === false) {
                $warnings[] = $category['display_name'] . ': a percentage value between 0 and 1 was found without a percent sign. It was read literally; verify whether the workbook stores fractions or percent values.';
            }
            if ($score !== null && ($score < 0 || $score > 100)) {
                $warnings[] = 'Worksheet "' . $sheet['name'] . '" has a percentage outside 0–100 for Squadron ' . $squadronId . '; that score was not imported.';
                $score = null;
            }
            $rawValue = $columns['raw'] !== null ? opaParseNumericValue($cells[$columns['raw']] ?? null) : null;
            if ($sourceRank === null && $score === null && $rawValue === null) continue;
            $categoryId = (int)$category['id'];
            if (isset($sheetRecords[$categoryId][$squadronId])) {
                $warnings[] = $category['display_name'] . ': duplicate source rows were found for Squadron ' . $squadronId . ' in worksheet "' . $sheet['name'] . '". The duplicate row was ignored.';
                continue;
            }
            $sheetRecords[$categoryId][$squadronId] = [
                'category_id' => $categoryId, 'squadron_id' => $squadronId,
                'source_rank' => ($sourceRank !== null && $sourceRank >= 1 && $sourceRank <= 1000 && floor($sourceRank) == $sourceRank) ? (int)$sourceRank : null,
                'category_rank' => null, 'category_score' => $score,
                'source_measurement' => $rawValue !== null ? (string)$rawValue : null,
            ];
        }

        foreach ($sheetRecords as $categoryId => $categoryRecords) {
            $category = null;
            foreach ($categories as $candidate) if ((int)$candidate['id'] === (int)$categoryId) $category = $candidate;
            if (!$category) continue;
            $ids = array_keys($categoryRecords);
            $hasAllTen = count($ids) === 10;
            $rankValues = array_filter(array_column($categoryRecords, 'source_rank'), static fn($v) => $v !== null);
            if (count($rankValues) === 10 && $hasAllTen) {
                $counts = array_count_values($rankValues);
                if (max($counts) > 1) {
                    $warnings[] = $category['display_name'] . ': tied source rankings were found among Squadrons 1–10. Percentage scores were imported, but Group 1 ranks were left blank for manual review.';
                } else {
                    $ordered = array_values($categoryRecords);
                    usort($ordered, static fn($a, $b) => $a['source_rank'] <=> $b['source_rank']);
                    foreach ($ordered as $index => $record) $categoryRecords[$record['squadron_id']]['category_rank'] = $index + 1;
                }
            } elseif ($hasAllTen && in_array($category['ranking_direction'], ['higher', 'lower'], true)) {
                $basisKey = $columns['raw'] !== null ? 'source_measurement' : 'category_score';
                $values = array_map(static fn($record) => $record[$basisKey], array_values($categoryRecords));
                $numericValues = array_filter($values, static fn($v) => $v !== null && is_numeric($v));
                if (count($numericValues) === 10) {
                    $normalized = array_map(static fn($v) => (string)(float)$v, $numericValues);
                    $counts = array_count_values($normalized);
                    if (max($counts) > 1) {
                        $warnings[] = $category['display_name'] . ': tied performance values were found among Squadrons 1–10. Group 1 ranks were left blank for manual review.';
                    } else {
                        $ordered = array_values($categoryRecords);
                        usort($ordered, static function ($a, $b) use ($category, $basisKey) {
                            $comparison = (float)$a[$basisKey] <=> (float)$b[$basisKey];
                            return $category['ranking_direction'] === 'higher' ? -$comparison : $comparison;
                        });
                        foreach ($ordered as $index => $record) $categoryRecords[$record['squadron_id']]['category_rank'] = $index + 1;
                    }
                } else {
                    $warnings[] = $category['display_name'] . ': performance measurements were incomplete or non-numeric, so ranks were not inferred.';
                }
            } elseif (($columns['raw'] !== null || $columns['percent'] !== null) && $category['ranking_direction'] === 'manual') {
                $warnings[] = $category['display_name'] . ': performance values were found, but the category ranking direction is not configured. Set higher-is-better or lower-is-better before calculating ranks.';
            } elseif ($columns['rank'] !== null) {
                $warnings[] = $category['display_name'] . ': source ranks were incomplete for Squadrons 1–10, so Group 1 ranks were left blank.';
            }
            foreach ($categoryRecords as $record) {
                $sid = (int)$record['squadron_id'];
                if (isset($records[$categoryId][$sid])) {
                    $warnings[] = $category['display_name'] . ': repeated Squadron ' . $sid . ' records were found across worksheets or reporting periods. The later record was ignored; select one reporting period before import.';
                    continue;
                }
                $records[$categoryId][$sid] = $record;
            }
        }
    }
    if (!$identifiedSheets) $warnings[] = 'No worksheet contained a recognizable Squadron plus rank, percentage, or raw-measurement header. The workbook was not applied.';
    return ['records' => $records, 'warnings' => array_values(array_unique($warnings)), 'identified_sheets' => $identifiedSheets];
}

function opaApplyImportPreview(PDO $db, int $draftId, array $preview, string $fileName, string $fileHash, string $username): int {
    $duplicate = $db->prepare('SELECT id FROM scoring_imports WHERE file_sha256=?');
    $duplicate->execute([$fileHash]);
    if ($duplicate->fetchColumn()) throw new RuntimeException('This exact workbook has already been imported. Upload a revised workbook or continue editing the existing draft.');
    $records = $preview['records'] ?? [];
    if (!$records) throw new RuntimeException('The workbook did not contain any importable Group 1 records.');
    $db->beginTransaction();
    try {
        $now = opaNow();
        $update = $db->prepare("UPDATE scoring_draft_scores SET
            category_rank=COALESCE(?,category_rank),
            rank_source=CASE WHEN ? IS NULL THEN rank_source ELSE 'import' END,
            category_score=COALESCE(?,category_score),
            score_source=CASE WHEN ? IS NULL THEN score_source ELSE 'import' END,
            source_rank=COALESCE(?,source_rank),
            source_measurement=COALESCE(?,source_measurement),
            entry_source='import', validation_note=?, updated_at=?
            WHERE draft_id=? AND category_id=? AND squadron_id=?");
        $recordCount = 0;
        $categoryNames = [];
        foreach ($records as $categoryId => $squadronRecords) {
            $categoryStmt = $db->prepare('SELECT display_name,is_active FROM scoring_categories WHERE id=?');
            $categoryStmt->execute([(int)$categoryId]);
            $category = $categoryStmt->fetch(PDO::FETCH_ASSOC);
            if (!$category || (int)$category['is_active'] !== 1) continue;
            $categoryNames[] = $category['display_name'];
            foreach ($squadronRecords as $squadronId => $record) {
                $update->execute([
                    $record['category_rank'], $record['category_rank'],
                    $record['category_score'], $record['category_score'],
                    $record['source_rank'], $record['source_measurement'],
                    '', $now, $draftId, (int)$categoryId, (int)$squadronId,
                ]);
                $recordCount++;
            }
        }
        if ($recordCount === 0) throw new RuntimeException('No supported category rows could be applied to this draft.');
        $insert = $db->prepare('INSERT INTO scoring_imports(draft_id,file_name,file_sha256,records_processed,categories_json,imported_by,imported_at)
            VALUES(?,?,?,?,?,?,?)');
        $insert->execute([$draftId, basename($fileName), $fileHash, $recordCount, json_encode(array_values(array_unique($categoryNames))), $username, $now]);
        $db->prepare('UPDATE scoring_drafts SET updated_by=?,updated_at=? WHERE id=?')->execute([$username, $now, $draftId]);
        $db->commit();
        return $recordCount;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
