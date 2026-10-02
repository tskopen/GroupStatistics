<?php
require_once __DIR__ . '/bracket-schema-migration.php';
require_once __DIR__ . '/bracket-migrate.php';

function bracketBootstrap(PDO $db): void
{
    $db->exec('PRAGMA foreign_keys = ON');
    initBracketTables($db);
    $json = DATA_DIR . '/brackets.json';
    migrateLegacyBrackets($db, $json);

    // Keep bracket-generated scoring events synchronized with the relational
    // matchup state. This is deliberately idempotent so it can repair test
    // data after an admin edits/resets/deletes a matchup or its event.
    bracketReconcileScoreEvents($db);

    // The homepage currently renders one tournament card per bracket. The
    // round-section output pass is retained for compatibility with the active
    // homepage presentation until the card renderer is moved to structured PHP.
    static $roundRendererRegistered = false;
    if (!$roundRendererRegistered) {
        ob_start('bracketRenderRoundSections');
        $roundRendererRegistered = true;
    }
}

function bracketReconcileScoreEvents(PDO $db): void
{
    try {
        $db->beginTransaction();

        $rows = $db->query("SELECT m.*, b.name AS bracket_name
            FROM bracket_matchups m
            JOIN brackets b ON b.id=m.bracket_id
            ORDER BY m.id")->fetchAll(PDO::FETCH_ASSOC);

        $find = $db->prepare('SELECT event_id FROM bracket_score_events WHERE matchup_id=?');
        $deleteEvent = $db->prepare('DELETE FROM events WHERE id=?');
        $deleteLink = $db->prepare('DELETE FROM bracket_score_events WHERE matchup_id=?');
        $insertEvent = $db->prepare('INSERT INTO events(squadron_id,event_type,event_name,value,points_awarded,timestamp,created_at) VALUES(?,?,?,?,?,?,?)');
        $insertLink = $db->prepare('INSERT INTO bracket_score_events(matchup_id,event_id) VALUES(?,?)');
        $updateEvent = $db->prepare('UPDATE events SET squadron_id=?,event_type=?,event_name=?,value=?,points_awarded=?,timestamp=? WHERE id=?');

        foreach ($rows as $m) {
            $find->execute([$m['id']]);
            $eventId = $find->fetchColumn();
            $isScoring = $m['status'] === 'completed' && $m['winner_id'] !== null;

            if (!$isScoring) {
                if ($eventId) $deleteEvent->execute([(int)$eventId]);
                $deleteLink->execute([$m['id']]);
                continue;
            }

            $points = (float)($m['points'] ?? 0);
            $scoreLabel = ($m['team1_score'] !== null && $m['team2_score'] !== null)
                ? ' (' . $m['team1_score'] . '-' . $m['team2_score'] . ')'
                : '';
            $name = $m['bracket_name'] . ' - Match ' . $m['match_number'] . $scoreLabel;
            $timestamp = $m['updated_at'] ?: date('c');

            if ($eventId) {
                $updateEvent->execute([
                    (int)$m['winner_id'], 'bracket', $name, $points, $points,
                    $timestamp, (int)$eventId
                ]);
            } else {
                $insertEvent->execute([
                    (int)$m['winner_id'], 'bracket', $name, $points, $points,
                    $timestamp, date('c')
                ]);
                $insertLink->execute([$m['id'], $db->lastInsertId()]);
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Bracket score-event reconciliation failed: ' . $e->getMessage());
    }
}

function bracketRenderRoundSections(string $html): string
{
    if (strpos($html, 'class="tournament-card"') === false || strpos($html, 'class="tournament-match"') === false) {
        return $html;
    }

    try {
        $db = getDb();
        $rows = $db->query("SELECT id FROM brackets ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        $roundQueues = [];
        foreach ($rows as $row) {
            $roundStmt = $db->prepare('SELECT id,name FROM bracket_rounds WHERE bracket_id=? ORDER BY round_number');
            $roundStmt->execute([(string)$row['id']]);
            $queue = [];
            foreach ($roundStmt->fetchAll(PDO::FETCH_ASSOC) as $round) {
                $matchStmt = $db->prepare('SELECT COUNT(*) FROM bracket_matchups WHERE round_id=?');
                $matchStmt->execute([(int)$round['id']]);
                $count = (int)$matchStmt->fetchColumn();
                if ($count > 0) $queue[] = ['name'=>$round['name'], 'count'=>$count];
            }
            if ($queue) $roundQueues[] = $queue;
        }
        if (!$roundQueues) return $html;

        $queueIndex = 0;
        $roundIndex = 0;
        $matchInRound = 0;
        $matchNumber = 0;

        $replacement = preg_replace_callback('/<div class="tournament-match">/', function () use (&$queueIndex,&$roundIndex,&$matchInRound,&$matchNumber,$roundQueues) {
            while ($queueIndex < count($roundQueues) && $roundIndex >= count($roundQueues[$queueIndex])) {
                $queueIndex++;
                $roundIndex = 0;
                $matchInRound = 0;
            }
            $round = $roundQueues[$queueIndex][$roundIndex] ?? null;
            if (!$round) return '<div class="tournament-match">';

            $prefix = '';
            if ($matchInRound === 0) {
                $prefix = '<div class="bracket-round-section" style="margin:0 0 18px;padding:0 0 4px;border:1px solid #e2e6ea;border-radius:8px;background:#fff"><div class="bracket-round-title" style="padding:10px 12px;margin:0 0 10px;background:#f1f4f7;color:#002147;font-size:.9em;font-weight:700;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #e2e6ea">' . htmlspecialchars((string)$round['name'], ENT_QUOTES, 'UTF-8') . '</div>';
            }
            $matchInRound++;
            $matchNumber++;
            if ($matchInRound >= (int)$round['count']) {
                $roundIndex++;
                $matchInRound = 0;
            }
            return $prefix . '<div class="tournament-match">';
        }, $html);

        if ($replacement === null) return $html;
        return $replacement;
    } catch (Throwable $e) {
        error_log('Bracket round-section renderer failed: ' . $e->getMessage());
        return $html;
    }
}

function bracketSquadrons(): array
{
    $db = getDb();
    $rows = $db->query('SELECT id,name,icon_filename FROM squadrons ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) return $rows;
    $legacy = readJson(DATA_DIR . '/squadrons.json');
    return is_array($legacy) ? $legacy : [];
}
