<?php
/** Relational bracket storage and scoring support. */
function initBracketTables(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS bracket_participants (
        id INTEGER PRIMARY KEY AUTOINCREMENT, bracket_id TEXT NOT NULL, squadron_id INTEGER NOT NULL,
        seed INTEGER, created_at DATETIME NOT NULL, UNIQUE(bracket_id, squadron_id),
        FOREIGN KEY (bracket_id) REFERENCES brackets(id) ON DELETE CASCADE,
        FOREIGN KEY (squadron_id) REFERENCES squadrons(id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS bracket_rounds (
        id INTEGER PRIMARY KEY AUTOINCREMENT, bracket_id TEXT NOT NULL, round_number INTEGER NOT NULL,
        name TEXT NOT NULL, created_at DATETIME NOT NULL, UNIQUE(bracket_id, round_number),
        FOREIGN KEY (bracket_id) REFERENCES brackets(id) ON DELETE CASCADE
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS bracket_matchups (
        id TEXT PRIMARY KEY, bracket_id TEXT NOT NULL, round_id INTEGER NOT NULL, match_number INTEGER NOT NULL,
        team1_id INTEGER, team2_id INTEGER, team1_source_matchup_id TEXT, team2_source_matchup_id TEXT,
        team1_score INTEGER, team2_score INTEGER, winner_id INTEGER, points REAL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'pending', next_matchup_id TEXT, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        UNIQUE(round_id, match_number), FOREIGN KEY (bracket_id) REFERENCES brackets(id) ON DELETE CASCADE,
        FOREIGN KEY (round_id) REFERENCES bracket_rounds(id) ON DELETE CASCADE,
        FOREIGN KEY (team1_id) REFERENCES squadrons(id), FOREIGN KEY (team2_id) REFERENCES squadrons(id),
        FOREIGN KEY (winner_id) REFERENCES squadrons(id)
    )");

    $columns = $db->query('PRAGMA table_info(brackets)')->fetchAll(PDO::FETCH_ASSOC);
    $hasType = false;
    foreach ($columns as $column) {
        if ($column['name'] === 'bracket_type') { $hasType = true; break; }
    }
    if (!$hasType) $db->exec("ALTER TABLE brackets ADD COLUMN bracket_type TEXT NOT NULL DEFAULT 'multi_round'");

    $db->exec("CREATE TABLE IF NOT EXISTS bracket_score_events (
        matchup_id TEXT PRIMARY KEY, event_id INTEGER NOT NULL UNIQUE,
        FOREIGN KEY (matchup_id) REFERENCES bracket_matchups(id) ON DELETE CASCADE,
        FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_bracket_participants_bracket ON bracket_participants(bracket_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_bracket_rounds_bracket ON bracket_rounds(bracket_id, round_number)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_bracket_matchups_bracket ON bracket_matchups(bracket_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_bracket_matchups_round ON bracket_matchups(round_id, match_number)');
}

function bracketEnsureTables(PDO $db): void { initBracketTables($db); }

/**
 * Read a relational bracket without modifying any data.
 *
 * The relational tables are the runtime source of truth. Legacy JSON is no
 * longer regenerated as a side effect of reads; this prevents a partially
 * migrated database from silently overwriting compatibility data on every
 * request.
 */
function bracketLoad(PDO $db, string $bracketId): ?array
{
    $stmt = $db->prepare('SELECT id,name,created_date,updated_at,champion_id,bracket_type FROM brackets WHERE id=?');
    $stmt->execute([$bracketId]);
    $bracket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bracket) return null;

    $bracket['bracket_type'] = $bracket['bracket_type'] ?: 'multi_round';

    $stmt = $db->prepare('SELECT squadron_id,seed FROM bracket_participants WHERE bracket_id=? ORDER BY COALESCE(seed,999999),id');
    $stmt->execute([$bracketId]);
    $bracket['participants'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare('SELECT * FROM bracket_rounds WHERE bracket_id=? ORDER BY round_number');
    $stmt->execute([$bracketId]);
    $rounds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rounds as &$round) {
        $m = $db->prepare('SELECT * FROM bracket_matchups WHERE round_id=? ORDER BY match_number');
        $m->execute([(int)$round['id']]);
        $round['matchups'] = $m->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($round);
    $bracket['rounds'] = $rounds;
    return $bracket;
}

/**
 * Compatibility helper for one-time migration/export only.
 * Runtime reads should use bracketLoad() and must not call this function.
 */
function bracketSyncLegacyJson(PDO $db, string $bracketId): void
{
    $stmt = $db->prepare('SELECT id,updated_at FROM brackets WHERE id=?');
    $stmt->execute([$bracketId]);
    $bracket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bracket) return;

    $roundStmt = $db->prepare('SELECT id,name FROM bracket_rounds WHERE bracket_id=? ORDER BY round_number');
    $roundStmt->execute([$bracketId]);
    $rounds = [];
    foreach ($roundStmt->fetchAll(PDO::FETCH_ASSOC) as $round) {
        $m = $db->prepare('SELECT id,match_number,team1_id,team2_id,team1_source_matchup_id,team2_source_matchup_id,team1_score,team2_score,winner_id,points,status,next_matchup_id FROM bracket_matchups WHERE round_id=? ORDER BY match_number');
        $m->execute([(int)$round['id']]);
        $matches = [];
        foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $match) {
            $match['next_match_id'] = $match['next_matchup_id'];
            unset($match['next_matchup_id']);
            $matches[] = $match;
        }
        $rounds[] = ['name' => $round['name'], 'matchups' => $matches];
    }

    $json = json_encode($rounds, JSON_UNESCAPED_SLASHES);
    $update = $db->prepare('UPDATE brackets SET rounds=? WHERE id=?');
    $update->execute([$json, $bracketId]);
}

function bracketSave(PDO $db, array $bracket): void
{
    bracketEnsureTables($db);
    $db->beginTransaction();
    try {
        $id = (string)$bracket['id'];
        $now = date('c');
        $created = $bracket['created_date'] ?? $now;

        // Do not use INSERT OR REPLACE here. SQLite REPLACE deletes the old
        // parent row first, which can cascade-delete relational children.
        $exists = $db->prepare('SELECT 1 FROM brackets WHERE id=?');
        $exists->execute([$id]);
        if ($exists->fetchColumn()) {
            $stmt = $db->prepare('UPDATE brackets SET name=?,created_date=?,updated_at=?,champion_id=?,bracket_type=? WHERE id=?');
            $stmt->execute([$bracket['name'], $created, $now, $bracket['champion_id'] ?? null, $bracket['bracket_type'] ?? 'multi_round', $id]);
        } else {
            $stmt = $db->prepare('INSERT INTO brackets(id,name,created_date,updated_at,champion_id,rounds,bracket_type) VALUES(?,?,?,?,?,?,?)');
            $stmt->execute([$id, $bracket['name'], $created, $now, $bracket['champion_id'] ?? null, null, $bracket['bracket_type'] ?? 'multi_round']);
        }

        $db->prepare('DELETE FROM bracket_matchups WHERE bracket_id=?')->execute([$id]);
        $db->prepare('DELETE FROM bracket_rounds WHERE bracket_id=?')->execute([$id]);
        $db->prepare('DELETE FROM bracket_participants WHERE bracket_id=?')->execute([$id]);

        $p = $db->prepare('INSERT INTO bracket_participants(bracket_id,squadron_id,seed,created_at) VALUES(?,?,?,?)');
        foreach (($bracket['participants'] ?? []) as $i => $participant) {
            $p->execute([$id, (int)$participant['squadron_id'], $participant['seed'] ?? $i + 1, $now]);
        }

        $roundStmt = $db->prepare('INSERT INTO bracket_rounds(bracket_id,round_number,name,created_at) VALUES(?,?,?,?)');
        $matchStmt = $db->prepare('INSERT INTO bracket_matchups(id,bracket_id,round_id,match_number,team1_id,team2_id,team1_source_matchup_id,team2_source_matchup_id,team1_score,team2_score,winner_id,points,status,next_matchup_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $roundIds = [];
        foreach (($bracket['rounds'] ?? []) as $i => $round) {
            $roundStmt->execute([$id, $i + 1, $round['name'] ?? ('Round ' . ($i + 1)), $now]);
            $roundIds[$i] = (int)$db->lastInsertId();
        }
        foreach (($bracket['rounds'] ?? []) as $ri => $round) {
            foreach (($round['matchups'] ?? []) as $mi => $match) {
                $matchStmt->execute([
                    (string)$match['id'], $id, $roundIds[$ri], $mi + 1,
                    $match['team1_id'] ?? null, $match['team2_id'] ?? null,
                    $match['team1_source_matchup_id'] ?? null, $match['team2_source_matchup_id'] ?? null,
                    $match['team1_score'] ?? null, $match['team2_score'] ?? null,
                    $match['winner_id'] ?? null, $match['points'] ?? 0,
                    $match['status'] ?? 'pending', $match['next_match_id'] ?? null,
                    $now, $now
                ]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
