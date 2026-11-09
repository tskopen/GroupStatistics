<?php
/**
 * Migration 018: rebuild every stored OPA snapshot using configured Military weights.
 *
 * Earlier publications stored an unweighted average of the five percentage
 * scores. The official OPA is the sum of each percentage score multiplied by
 * its configured Military weight. Rebuild the stored values so every public
 * display, history entry, comparison, and rank reads the corrected OPA field.
 */
return static function (PDO $db): void {
    $weightStmt = $db->query('SELECT category_key,military_weight FROM scoring_categories');
    $weights = [];
    foreach ($weightStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $weights[(string)$row['category_key']] = (float)$row['military_weight'];
    }

    $publicationIds = $db->query('SELECT id FROM scoring_publications ORDER BY id ASC')
        ->fetchAll(PDO::FETCH_COLUMN);
    if (!$publicationIds) return;

    // Published snapshots are immutable during normal operation. This one-time
    // migration intentionally rebuilds their derived values, then restores guards.
    foreach (['scoringpublications', 'scoringpublicationscores', 'scoringpublicationsquadrons'] as $key) {
        $db->exec('DROP TRIGGER IF EXISTS trg_' . $key . '_no_update');
        $db->exec('DROP TRIGGER IF EXISTS trg_' . $key . '_no_delete');
    }

    $previousCategoryRows = [];
    $previousSquadronRows = [];
    $updateScore = $db->prepare(
        'UPDATE scoring_publication_scores
         SET category_points=?,previous_rank=?,rank_change=?,previous_score=?,score_change=?
         WHERE id=?'
    );
    $updateSquadron = $db->prepare(
        'UPDATE scoring_publication_squadrons
         SET opa=?,overall_rank=?,previous_opa=?,opa_change=?,previous_rank=?,rank_change=?
         WHERE id=?'
    );

    foreach ($publicationIds as $publicationIdValue) {
        $publicationId = (int)$publicationIdValue;
        $stmt = $db->prepare(
            'SELECT * FROM scoring_publication_scores
             WHERE publication_id=? ORDER BY category_key,squadron_id'
        );
        $stmt->execute([$publicationId]);
        $scoreRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$scoreRows) {
            throw new RuntimeException('Publication #' . $publicationId . ' has no category records.');
        }

        $categories = [];
        $totals = [];
        $currentCategoryRows = [];
        foreach ($scoreRows as $row) {
            $key = (string)$row['category_key'];
            $sid = (int)$row['squadron_id'];
            $rank = (int)$row['category_rank'];
            $score = (float)$row['category_score'];
            if ($sid < 1 || $sid > 10 || $rank < 1 || $rank > 10 ||
                !is_finite($score) || $score < 0 || $score > 100) {
                throw new RuntimeException('Publication #' . $publicationId . ' contains invalid category data.');
            }
            if (isset($categories[$key]['ranks'][$rank])) {
                throw new RuntimeException('Publication #' . $publicationId . ', category ' . $key . ' has duplicate ranks.');
            }
            $categories[$key]['ranks'][$rank] = true;
            $categories[$key]['rows'][$sid] = $row;

            $weight = (float)($weights[$key] ?? 0);
            if ($weight > 0) {
                if (!isset($totals[$sid])) $totals[$sid] = ['weighted_total' => 0.0, 'weight_total' => 0.0];
                $totals[$sid]['weighted_total'] += $score * $weight;
                $totals[$sid]['weight_total'] += $weight;
            }
            $currentCategoryRows[$key][$sid] = ['rank' => $rank, 'score' => $score];
        }

        foreach ($categories as $key => $category) {
            if (count($category['rows']) !== 10) {
                throw new RuntimeException('Publication #' . $publicationId . ', category ' . $key . ' does not contain all ten Group 1 squadrons.');
            }
            for ($sid = 1; $sid <= 10; $sid++) {
                if (!isset($category['rows'][$sid])) {
                    throw new RuntimeException('Publication #' . $publicationId . ', category ' . $key . ' is missing Squadron ' . $sid . '.');
                }
            }
        }

        $opaRows = [];
        for ($sid = 1; $sid <= 10; $sid++) {
            if (!isset($totals[$sid]) || $totals[$sid]['weight_total'] <= 0) {
                throw new RuntimeException('Publication #' . $publicationId . ' has no weighted OPA inputs for Squadron ' . $sid . '.');
            }
            $opaRows[] = ['squadron_id' => $sid, 'opa' => $totals[$sid]['weighted_total']];
        }
        usort($opaRows, static function (array $a, array $b): int {
            $difference = (float)$b['opa'] - (float)$a['opa'];
            return abs($difference) < 0.0000001
                ? ((int)$a['squadron_id'] <=> (int)$b['squadron_id'])
                : ($difference < 0 ? -1 : 1);
        });

        $rankBySquadron = [];
        $lastOpa = null;
        $rank = 0;
        foreach ($opaRows as $index => $row) {
            if ($lastOpa === null || abs((float)$row['opa'] - (float)$lastOpa) >= 0.0000001) {
                $rank = $index + 1;
                $lastOpa = (float)$row['opa'];
            }
            $rankBySquadron[(int)$row['squadron_id']] = $rank;
        }

        foreach ($scoreRows as $row) {
            $key = (string)$row['category_key'];
            $sid = (int)$row['squadron_id'];
            $rank = (int)$row['category_rank'];
            $score = (float)$row['category_score'];
            $previous = $previousCategoryRows[$key][$sid] ?? null;
            $updateScore->execute([
                11 - $rank,
                $previous['rank'] ?? null,
                $previous !== null ? (int)$previous['rank'] - $rank : null,
                $previous['score'] ?? null,
                $previous !== null ? $score - (float)$previous['score'] : null,
                (int)$row['id'],
            ]);
        }

        $squadronStmt = $db->prepare(
            'SELECT * FROM scoring_publication_squadrons WHERE publication_id=? ORDER BY squadron_id'
        );
        $squadronStmt->execute([$publicationId]);
        $squadronRows = $squadronStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($squadronRows) !== 10) {
            throw new RuntimeException('Publication #' . $publicationId . ' must contain all ten overall squadron records.');
        }

        $currentSquadronRows = [];
        foreach ($squadronRows as $row) {
            $sid = (int)$row['squadron_id'];
            if (!isset($rankBySquadron[$sid])) {
                throw new RuntimeException('Publication #' . $publicationId . ' is missing Squadron ' . $sid . '.');
            }
            $opa = (float)$totals[$sid]['weighted_total'];
            $previous = $previousSquadronRows[$sid] ?? null;
            $updateSquadron->execute([
                $opa,
                $rankBySquadron[$sid],
                $previous['opa'] ?? null,
                $previous !== null ? $opa - (float)$previous['opa'] : null,
                $previous['rank'] ?? null,
                $previous !== null ? (int)$previous['rank'] - (int)$rankBySquadron[$sid] : null,
                (int)$row['id'],
            ]);
            $currentSquadronRows[$sid] = ['opa' => $opa, 'rank' => (int)$rankBySquadron[$sid]];
        }

        $previousCategoryRows = $currentCategoryRows;
        $previousSquadronRows = $currentSquadronRows;
    }

    $tables = [
        'scoringpublications' => 'scoring_publications',
        'scoringpublicationscores' => 'scoring_publication_scores',
        'scoringpublicationsquadrons' => 'scoring_publication_squadrons',
    ];
    foreach ($tables as $key => $table) {
        $db->exec("CREATE TRIGGER IF NOT EXISTS trg_{$key}_no_update
            BEFORE UPDATE ON {$table}
            BEGIN SELECT RAISE(ABORT, 'Published scoring snapshots are immutable'); END");
        $db->exec("CREATE TRIGGER IF NOT EXISTS trg_{$key}_no_delete
            BEFORE DELETE ON {$table}
            BEGIN SELECT RAISE(ABORT, 'Published scoring snapshots are immutable'); END");
    }
};
