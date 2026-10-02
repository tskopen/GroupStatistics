<?php
/** Migration 004: import legacy JSON once, then archive the files. */
return static function (PDO $db): void {
    $dataDir = defined('DATA_DIR') ? DATA_DIR : '/data';

    $squadrons = $dataDir . '/squadrons.json';
    if (is_file($squadrons) && !is_file($squadrons . '.migrated')) {
        $rows = json_decode(file_get_contents($squadrons) ?: '', true);
        if (is_array($rows)) {
            $stmt = $db->prepare(
                'INSERT OR IGNORE INTO squadrons(id,name,description,icon_filename,created_at) VALUES(?,?,?,?,?)'
            );
            foreach ($rows as $row) {
                if (!isset($row['id'], $row['name'])) {
                    continue;
                }
                $stmt->execute([
                    (int) $row['id'],
                    $row['name'],
                    $row['description'] ?? null,
                    $row['icon'] ?? $row['icon_filename'] ?? null,
                    $row['created_at'] ?? date('c'),
                ]);
            }
        }
        if (@rename($squadrons, $squadrons . '.legacy.bak')) {
            @file_put_contents($squadrons . '.migrated', date('c'));
        }
    }

    $scores = $dataDir . '/scores.json';
    if (is_file($scores) && !is_file($scores . '.migrated')) {
        $rows = json_decode(file_get_contents($scores) ?: '', true);
        if (is_array($rows)) {
            $stmt = $db->prepare(
                'INSERT INTO events(squadron_id,event_type,event_name,value,points_awarded,timestamp,created_at) VALUES(?,?,?,?,?,?,?)'
            );
            foreach ($rows as $row) {
                if (!isset($row['squadron_id'])) {
                    continue;
                }
                $value = (float) ($row['value'] ?? 0);
                $stmt->execute([
                    (int) $row['squadron_id'],
                    strtolower(trim((string) ($row['event_type'] ?? 'other'))),
                    $row['event_name'] ?? $row['tournament_name'] ?? 'Event',
                    $value,
                    (float) ($row['points_awarded'] ?? $value),
                    $row['timestamp'] ?? date('c'),
                    date('c'),
                ]);
            }
        }
        if (@rename($scores, $scores . '.legacy.bak')) {
            @file_put_contents($scores . '.migrated', date('c'));
        }
    }

    $brackets = $dataDir . '/brackets.json';
    if (is_file($brackets) && !is_file($brackets . '.migrated')) {
        $rows = json_decode(file_get_contents($brackets) ?: '', true);
        if (is_array($rows)) {
            $insertBracket = $db->prepare(
                'INSERT OR IGNORE INTO brackets(id,name,created_date,updated_at,champion_id,rounds,bracket_type) VALUES(?,?,?,?,?,?,?)'
            );
            $participant = $db->prepare(
                'INSERT OR IGNORE INTO bracket_participants(bracket_id,squadron_id,seed,created_at) VALUES(?,?,?,?)'
            );
            $findRound = $db->prepare(
                'SELECT id FROM bracket_rounds WHERE bracket_id=? AND round_number=?'
            );
            $roundStmt = $db->prepare(
                'INSERT INTO bracket_rounds(bracket_id,round_number,name,created_at) VALUES(?,?,?,?)'
            );
            $matchStmt = $db->prepare(
                'INSERT OR IGNORE INTO bracket_matchups(id,bracket_id,round_id,match_number,team1_id,team2_id,team1_source_matchup_id,team2_source_matchup_id,team1_score,team2_score,winner_id,points,status,next_matchup_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );

            foreach ($rows as $bracket) {
                if (empty($bracket['id'])) {
                    continue;
                }

                $id = (string) $bracket['id'];
                $now = date('c');
                $insertBracket->execute([
                    $id,
                    $bracket['name'] ?? 'Untitled Bracket',
                    $bracket['created_date'] ?? $bracket['created_at'] ?? $now,
                    $bracket['updated_at'] ?? $now,
                    $bracket['champion_id'] ?? null,
                    json_encode($bracket['rounds'] ?? []),
                    $bracket['bracket_type'] ?? 'multi_round',
                ]);

                foreach (($bracket['participants'] ?? []) as $i => $p) {
                    $sid = $p['squadron_id'] ?? $p['id'] ?? null;
                    if ($sid !== null) {
                        $participant->execute([$id, (int) $sid, $i + 1, $now]);
                    }
                }

                foreach (($bracket['rounds'] ?? []) as $ri => $round) {
                    $roundNumber = $ri + 1;
                    $findRound->execute([$id, $roundNumber]);
                    $roundId = $findRound->fetchColumn();

                    if ($roundId === false) {
                        $roundStmt->execute([
                            $id,
                            $roundNumber,
                            $round['round_name'] ?? $round['name'] ?? ('Round ' . $roundNumber),
                            $now,
                        ]);
                        $roundId = $db->lastInsertId();
                    }

                    foreach (($round['matchups'] ?? []) as $mi => $m) {
                        $matchId = (string) ($m['id'] ?? bin2hex(random_bytes(8)));
                        $matchStmt->execute([
                            $matchId,
                            $id,
                            (int) $roundId,
                            $mi + 1,
                            $m['team1_id'] ?? null,
                            $m['team2_id'] ?? null,
                            $m['team1_source_matchup_id'] ?? null,
                            $m['team2_source_matchup_id'] ?? null,
                            $m['team1_score'] ?? null,
                            $m['team2_score'] ?? null,
                            $m['winner_id'] ?? null,
                            $m['points'] ?? 0,
                            $m['status'] ?? 'upcoming',
                            $m['next_match_id'] ?? null,
                            $now,
                            $now,
                        ]);
                    }
                }
            }
        }

        if (@rename($brackets, $brackets . '.legacy.bak')) {
            @file_put_contents($brackets . '.migrated', date('c'));
        }
    }
};
