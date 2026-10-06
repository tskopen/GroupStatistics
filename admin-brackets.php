<?php
session_start();
require __DIR__ . '/config.php';
require_once __DIR__ . '/bracket-bootstrap.php';
require_once __DIR__ . '/theme-loader.php';

if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

$db = getDb();
bracketBootstrap($db);
bracketEnsureTables($db);
$theme = loadTheme();

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function teamName(PDO $db, ?int $id): string {
    if ($id === null) return 'TBD';
    $stmt = $db->prepare('SELECT name FROM squadrons WHERE id=?');
    $stmt->execute([$id]);
    return $stmt->fetchColumn() ?: 'Unknown';
}

function makeId(): string {
    return bin2hex(random_bytes(6));
}

function syncBracketEvent(PDO $db, array $match, string $bracketName): void {
    $link = $db->prepare('SELECT event_id FROM bracket_score_events WHERE matchup_id=?');
    $link->execute([$match['id']]);
    $eventId = $link->fetchColumn();

    if (($match['status'] ?? '') !== 'completed' || $match['winner_id'] === null) {
        if ($eventId) {
            $db->prepare('DELETE FROM events WHERE id=?')->execute([$eventId]);
            $db->prepare('DELETE FROM bracket_score_events WHERE matchup_id=?')->execute([$match['id']]);
        }
        return;
    }

    $points = (float)($match['points'] ?? 0);
    $eventName = $bracketName . ' - Match ' . $match['match_number'] .
        ' (' . $match['team1_score'] . '-' . $match['team2_score'] . ')';

    if ($eventId) {
        $db->prepare(
            'UPDATE events SET squadron_id=?,event_type=?,event_name=?,value=?,points_awarded=?,timestamp=? WHERE id=?'
        )->execute([
            (int)$match['winner_id'], 'bracket', $eventName, $points, $points, date('c'), $eventId
        ]);
    } else {
        $db->prepare(
            'INSERT INTO events(squadron_id,event_type,event_name,value,points_awarded,timestamp,created_at)
             VALUES(?,?,?,?,?,?,?)'
        )->execute([
            (int)$match['winner_id'], 'bracket', $eventName, $points, $points, date('c'), date('c')
        ]);
        $newEventId = $db->lastInsertId();
        $db->prepare('INSERT INTO bracket_score_events(matchup_id,event_id) VALUES(?,?)')
            ->execute([$match['id'], $newEventId]);
    }
}

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $bracketId = (string)($_POST['bracket_id'] ?? '');

    try {
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $type = ($_POST['bracket_type'] ?? 'single_round') === 'multi_round' ? 'multi_round' : 'single_round';
            $ids = array_values(array_unique(array_map('intval', $_POST['participant_ids'] ?? [])));

            if ($name === '') throw new Exception('Enter a bracket name.');
            if (count($ids) < 2) throw new Exception('Select at least two teams.');

            $valid = array_map(
                'intval',
                array_column($db->query('SELECT id FROM squadrons')->fetchAll(), 'id')
            );
            if (array_diff($ids, $valid)) throw new Exception('One or more selected teams are invalid.');

            if ($type === 'multi_round') {
                $count = count($ids);
                if ($count > 64 || ($count & ($count - 1)) !== 0) {
                    throw new Exception('Multi-round brackets require 2, 4, 8, 16, 32, or 64 teams.');
                }
            }

            $db->beginTransaction();
            $now = date('c');
            $id = makeId();

            $db->prepare(
                'INSERT INTO brackets(id,name,created_date,updated_at,champion_id,rounds,bracket_type)
                 VALUES(?,?,?,?,?,?,?)'
            )->execute([$id, $name, $now, $now, null, null, $type]);

            $participantStmt = $db->prepare(
                'INSERT INTO bracket_participants(bracket_id,squadron_id,seed,created_at) VALUES(?,?,?,?)'
            );
            foreach ($ids as $i => $squadronId) {
                $participantStmt->execute([$id, $squadronId, $i + 1, $now]);
            }

            $roundStmt = $db->prepare(
                'INSERT INTO bracket_rounds(bracket_id,round_number,name,created_at) VALUES(?,?,?,?)'
            );
            $matchStmt = $db->prepare(
                'INSERT INTO bracket_matchups
                (id,bracket_id,round_id,match_number,team1_id,team2_id,
                 team1_source_matchup_id,team2_source_matchup_id,
                 team1_score,team2_score,winner_id,points,status,next_matchup_id,created_at,updated_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );

            if ($type === 'single_round') {
                $roundStmt->execute([$id, 1, 'Matchups', $now]);
                $roundId = (int)$db->lastInsertId();

                for ($i = 0, $matchNumber = 1; $i < count($ids); $i += 2, $matchNumber++) {
                    $team1 = $ids[$i] ?? null;
                    $team2 = $ids[$i + 1] ?? null;
                    $status = ($team1 !== null && $team2 !== null) ? 'ready' : 'pending';
                    $matchStmt->execute([
                        makeId(), $id, $roundId, $matchNumber,
                        $team1, $team2, null, null,
                        null, null, null, 0, $status, null, $now, $now
                    ]);
                }
            } else {
                $numRounds = (int)log(count($ids), 2);
                $roundIds = [];
                $matchIds = [];

                for ($round = 1; $round <= $numRounds; $round++) {
                    $matchesThisRound = count($ids) / (2 ** $round);
                    $roundName = $round === $numRounds ? 'Final' : (
                        $round === $numRounds - 1 ? 'Semifinals' :
                        ($round === $numRounds - 2 ? 'Quarterfinals' : 'Round ' . $round)
                    );
                    $roundStmt->execute([$id, $round, $roundName, $now]);
                    $roundIds[$round] = (int)$db->lastInsertId();
                    $matchIds[$round] = [];

                    for ($m = 1; $m <= $matchesThisRound; $m++) {
                        $matchId = makeId();
                        $matchIds[$round][$m] = $matchId;
                        $team1 = $round === 1 ? ($ids[($m - 1) * 2] ?? null) : null;
                        $team2 = $round === 1 ? ($ids[($m - 1) * 2 + 1] ?? null) : null;
                        $status = ($team1 !== null && $team2 !== null) ? 'ready' : 'pending';
                        $matchStmt->execute([
                            $matchId, $id, $roundIds[$round], $m,
                            $team1, $team2,
                            null, null, null, null, null, 0, $status, null,
                            $now, $now
                        ]);
                    }
                }

                // Wire advancement after every matchup exists.
                for ($round = 1; $round < $numRounds; $round++) {
                    $matchCount = count($matchIds[$round]);
                    for ($m = 1; $m <= $matchCount; $m++) {
                        $nextId = $matchIds[$round + 1][intdiv($m - 1, 2) + 1];
                        $db->prepare('UPDATE bracket_matchups SET next_matchup_id=? WHERE id=?')
                            ->execute([$nextId, $matchIds[$round][$m]]);
                    }
                }
            }

            $db->commit();
            header('Location: admin-brackets.php?bracket_id=' . rawurlencode($id));
            exit;
        }

        if ($action === 'assign') {
            $team1 = ($_POST['team1_id'] ?? '') !== '' ? (int)$_POST['team1_id'] : null;
            $team2 = ($_POST['team2_id'] ?? '') !== '' ? (int)$_POST['team2_id'] : null;
            $matchupId = (string)($_POST['matchup_id'] ?? '');

            if ($team1 !== null && $team1 === $team2) {
                throw new Exception('A matchup cannot use the same team twice.');
            }

            $old = $db->prepare('SELECT * FROM bracket_matchups WHERE id=? AND bracket_id=?');
            $old->execute([$matchupId, $bracketId]);
            $existing = $old->fetch(PDO::FETCH_ASSOC);
            if (!$existing) throw new Exception('Matchup not found.');

            // Do not silently erase an already-posted result when teams are changed.
            if (($existing['status'] ?? '') === 'completed') {
                throw new Exception('Completed matchups cannot have their teams changed.');
            }

            $status = ($team1 !== null && $team2 !== null) ? 'ready' : 'pending';

            $db->prepare(
                'UPDATE bracket_matchups
                 SET team1_id=?,team2_id=?,status=?,team1_score=NULL,team2_score=NULL,
                     winner_id=NULL,points=0,updated_at=?
                 WHERE id=? AND bracket_id=?'
            )->execute([$team1, $team2, $status, date('c'), $matchupId, $bracketId]);

            $db->prepare('UPDATE brackets SET updated_at=? WHERE id=?')
                ->execute([date('c'), $bracketId]);

            $message = 'Teams saved. The bracket can be posted before scores are entered.';
        }

        if ($action === 'score') {
            $score1 = (int)($_POST['team1_score'] ?? 0);
            $score2 = (int)($_POST['team2_score'] ?? 0);
            $points = (float)($_POST['points'] ?? 0);
            $matchupId = (string)($_POST['matchup_id'] ?? '');

            if ($score1 === $score2) throw new Exception('A completed matchup cannot be tied.');
            if ($points < 0) throw new Exception('Points cannot be negative.');

            $stmt = $db->prepare(
                'SELECT m.*,b.name AS bracket_name
                 FROM bracket_matchups m
                 JOIN brackets b ON b.id=m.bracket_id
                 WHERE m.id=? AND m.bracket_id=?'
            );
            $stmt->execute([$matchupId, $bracketId]);
            $match = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$match || $match['team1_id'] === null || $match['team2_id'] === null) {
                throw new Exception('Both teams must be set before entering a score.');
            }

            $winner = $score1 > $score2 ? (int)$match['team1_id'] : (int)$match['team2_id'];

            $db->beginTransaction();
            $db->prepare(
                "UPDATE bracket_matchups
                 SET team1_score=?,team2_score=?,winner_id=?,points=?,status='completed',updated_at=?
                 WHERE id=? AND bracket_id=?"
            )->execute([
                $score1, $score2, $winner, $points, date('c'), $matchupId, $bracketId
            ]);

            $match['team1_score'] = $score1;
            $match['team2_score'] = $score2;
            $match['winner_id'] = $winner;
            $match['points'] = $points;
            $match['status'] = 'completed';
            syncBracketEvent($db, $match, $match['bracket_name']);

            if (!empty($match['next_matchup_id'])) {
                $next = $db->prepare('SELECT * FROM bracket_matchups WHERE id=? AND bracket_id=?');
                $next->execute([$match['next_matchup_id'], $bracketId]);
                $nextMatch = $next->fetch(PDO::FETCH_ASSOC);
                if ($nextMatch) {
                    $slot = ((int)$match['match_number'] % 2 === 1) ? 'team1_id' : 'team2_id';
                    $db->prepare("UPDATE bracket_matchups SET $slot=?, status=CASE WHEN team1_id IS NOT NULL AND team2_id IS NOT NULL THEN 'ready' ELSE status END, updated_at=? WHERE id=?")
                        ->execute([$winner, date('c'), $nextMatch['id']]);
                }
            } else {
                $db->prepare('UPDATE brackets SET champion_id=?,updated_at=? WHERE id=?')
                    ->execute([$winner, date('c'), $bracketId]);
            }

            $db->prepare('UPDATE brackets SET updated_at=? WHERE id=?')
                ->execute([date('c'), $bracketId]);

            $db->commit();
            $message = 'Score saved. ' . teamName($db, $winner) . ' receives ' . $points . ' bracket points.';
        }

        if ($action === 'delete') {
            $eventIds = $db->prepare(
                'SELECT event_id FROM bracket_score_events
                 WHERE matchup_id IN (SELECT id FROM bracket_matchups WHERE bracket_id=?)'
            );
            $eventIds->execute([$bracketId]);

            foreach ($eventIds->fetchAll(PDO::FETCH_COLUMN) as $eventId) {
                $db->prepare('DELETE FROM events WHERE id=?')->execute([$eventId]);
            }

            $db->prepare('DELETE FROM brackets WHERE id=?')->execute([$bracketId]);
            header('Location: admin-brackets.php');
            exit;
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

$all = $db->query(
    'SELECT id,name,updated_at FROM brackets ORDER BY updated_at DESC'
)->fetchAll(PDO::FETCH_ASSOC);

$bracketId = (string)($_GET['bracket_id'] ?? $_POST['bracket_id'] ?? ($all[0]['id'] ?? ''));
$bracket = $bracketId ? bracketLoad($db, $bracketId) : null;
$teams = $db->query('SELECT id,name,icon_filename FROM squadrons ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Manage Brackets</title>
<style>
:root {
    --primary: <?php echo h($theme['primary_color']); ?>;
    --secondary: <?php echo h($theme['secondary_color']); ?>;
    --accent: <?php echo h($theme['accent_color']); ?>;
    --background: <?php echo h($theme['background_color']); ?>;
    --text: <?php echo h($theme['text_color']); ?>;
}
* { box-sizing: border-box; }
body { margin:0; padding:24px; background:var(--background); color:var(--text); font-family:Arial,sans-serif; }
.container { max-width:1200px; margin:0 auto; }
.card { background:#fff; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,.1); padding:20px; margin-bottom:18px; }
h1,h2,h3 { color:var(--primary); }
.header { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
.subtitle { color:#68727e; margin-top:4px; }
.layout { display:grid; grid-template-columns:280px 1fr; gap:18px; align-items:start; }
.bracket-list a { display:block; padding:11px 12px; border-radius:6px; color:var(--text); text-decoration:none; margin-bottom:4px; }
.bracket-list a:hover,.bracket-list a.active { background:#eef3f8; font-weight:700; }
.input,select { width:100%; padding:10px 11px; border:1px solid #ccd3dc; border-radius:6px; background:#fff; }
.team-grid { display:grid; grid-template-columns:1fr 1fr; gap:7px; margin-top:12px; }
.team-option { padding:9px 10px; background:#f5f7f9; border-radius:6px; }
.btn { display:inline-block; border:0; border-radius:6px; padding:9px 13px; background:var(--primary); color:#fff; cursor:pointer; font-weight:700; }
.btn.secondary { background:var(--secondary); }
.btn.danger { background:#a52222; }
.notice { padding:10px 12px; border-radius:6px; margin:12px 0; }
.notice.success { background:#e8f6ec; color:#176b3b; }
.notice.error { background:#fdebec; color:#a52222; }
.round { overflow-x:auto; }
.match { background:#f8f9fb; border:1px solid #dce1e8; border-radius:8px; padding:14px; margin:12px 0; }
.match-header { display:flex; justify-content:space-between; gap:12px; color:#6b7685; font-size:.85em; margin-bottom:10px; }
.teams { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.team-box { background:#fff; border:1px solid #e0e4e9; border-radius:6px; padding:10px; }
.team-name { font-weight:700; }
.score-row { display:flex; gap:7px; align-items:center; margin-top:12px; flex-wrap:wrap; }
.score { width:72px; padding:9px; border:1px solid #ccd3dc; border-radius:6px; }
.status { font-size:.8em; text-transform:uppercase; color:#68727e; font-weight:700; }
.completed { border-color:#b7d8c0; background:#f3faf5; }
.muted { color:#68727e; font-size:.9em; }
@media (max-width:800px) {
    body { padding:14px; }
    .layout { grid-template-columns:1fr; }
}
@media (max-width:520px) {
    .teams,.team-grid { grid-template-columns:1fr; }
}
</style>
</head>
<body>
<div class="container">

<div class="card">
    <div class="header">
        <div>
            <h1 style="margin:0;">Bracket Manager</h1>
            <div class="subtitle">Create one simple matchup bracket, post the teams, then add scores as games are played.</div>
        </div>
        <a class="btn secondary" href="index.php">View Public Tracker</a>
    </div>
    <?php if ($error): ?><div class="notice error"><?php echo h($error); ?></div><?php endif; ?>
    <?php if ($message): ?><div class="notice success"><?php echo h($message); ?></div><?php endif; ?>
</div>

<div class="layout">
    <aside>
        <div class="card">
            <h2 style="margin-top:0;">Brackets</h2>
            <?php if ($all): ?>
                <div class="bracket-list">
                <?php foreach ($all as $item): ?>
                    <a class="<?php echo $item['id'] === $bracketId ? 'active' : ''; ?>"
                       href="?bracket_id=<?php echo h($item['id']); ?>">
                        <?php echo h($item['name']); ?>
                    </a>
                <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="muted">No brackets created yet.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">New Bracket</h2>
            <p class="muted">Select the teams now. Their matchups can be displayed publicly before any scores exist.</p>
            <form method="post">
                <input type="hidden" name="action" value="create">
                <label>
                    Bracket name
                    <input class="input" name="name" placeholder="e.g. Flag Football" required>
                </label>
                <label>
                    Bracket type
                    <select class="input" name="bracket_type" id="bracket-type">
                        <option value="single_round">Single Round — independent matchups</option>
                        <option value="multi_round">Multi-Round — winners advance to the next round</option>
                    </select>
                </label>
                <p class="muted" id="bracket-type-help">Single Round keeps every matchup independent.</p>
                <div class="team-grid">
                    <?php foreach ($teams as $team): ?>
                        <label class="team-option">
                            <input type="checkbox" name="participant_ids[]" value="<?php echo (int)$team['id']; ?>">
                            <?php echo h($team['name']); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p><button class="btn" type="submit">Create Bracket</button></p>
            </form>
        </div>
    </aside>

    <main>
    <?php if (!$bracket): ?>
        <div class="card">
            <h2 style="margin-top:0;"><?php echo $all ? 'Select a bracket' : 'Create your first bracket'; ?></h2>
            <p class="muted">Brackets are intentionally simple: one round, fixed matchups, teams first, scores later.</p>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="header">
                <div>
                    <h2 style="margin:0;"><?php echo h($bracket['name']); ?></h2>
                    <p class="subtitle">
                        <?php echo $bracket['bracket_type'] === 'multi_round'
                            ? 'Multi-Round Elimination · winners advance automatically.'
                            : 'Single Round · independent matchups.'; ?>
                    </p>
                </div>
                <span class="status"><?php echo count($bracket['participants'] ?? []); ?> teams</span>
            </div>

            <?php foreach ($bracket['rounds'] as $round): ?>
                <section class="round">
                    <?php foreach ($round['matchups'] as $match): ?>
                        <div class="match <?php echo $match['status'] === 'completed' ? 'completed' : ''; ?>">
                            <div class="match-header">
                                <strong>Match <?php echo (int)$match['match_number']; ?></strong>
                                <span class="status"><?php echo h($match['status']); ?></span>
                            </div>

                            <?php if ($match['status'] !== 'completed'): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="assign">
                                    <input type="hidden" name="bracket_id" value="<?php echo h($bracketId); ?>">
                                    <input type="hidden" name="matchup_id" value="<?php echo h($match['id']); ?>">
                                    <div class="teams">
                                        <div class="team-box">
                                            <label class="muted">Team 1</label>
                                            <select name="team1_id">
                                                <option value="">TBD</option>
                                                <?php foreach ($teams as $team): ?>
                                                    <option value="<?php echo (int)$team['id']; ?>" <?php echo (string)$match['team1_id'] === (string)$team['id'] ? 'selected' : ''; ?>>
                                                        <?php echo h($team['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="team-box">
                                            <label class="muted">Team 2</label>
                                            <select name="team2_id">
                                                <option value="">TBD</option>
                                                <?php foreach ($teams as $team): ?>
                                                    <option value="<?php echo (int)$team['id']; ?>" <?php echo (string)$match['team2_id'] === (string)$team['id'] ? 'selected' : ''; ?>>
                                                        <?php echo h($team['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <p><button class="btn secondary" type="submit">Save Teams</button></p>
                                </form>
                            <?php else: ?>
                                <div class="teams">
                                    <div class="team-box">
                                        <div class="team-name"><?php echo h(teamName($db, $match['team1_id'])); ?></div>
                                        <strong><?php echo (int)$match['team1_score']; ?></strong>
                                    </div>
                                    <div class="team-box">
                                        <div class="team-name"><?php echo h(teamName($db, $match['team2_id'])); ?></div>
                                        <strong><?php echo (int)$match['team2_score']; ?></strong>
                                    </div>
                                </div>
                                <p class="muted"><strong><?php echo h(teamName($db, $match['winner_id'])); ?></strong> · +<?php echo h($match['points']); ?> pts</p>
                            <?php endif; ?>

                            <?php if ($match['status'] === 'ready'): ?>
                                <form method="post" class="score-row">
                                    <input type="hidden" name="action" value="score">
                                    <input type="hidden" name="bracket_id" value="<?php echo h($bracketId); ?>">
                                    <input type="hidden" name="matchup_id" value="<?php echo h($match['id']); ?>">
                                    <input class="score" type="number" min="0" name="team1_score" placeholder="Team 1" required>
                                    <span>–</span>
                                    <input class="score" type="number" min="0" name="team2_score" placeholder="Team 2" required>
                                    <input class="score" type="number" min="0" step="0.01" name="points" value="0" title="Points awarded to the winner">
                                    <button class="btn" type="submit">Save Score</button>
                                </form>
                            <?php elseif ($match['status'] === 'pending'): ?>
                                <p class="muted">Add both teams to enable score entry.</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>

            <form method="post" onsubmit="return confirm('Delete this bracket and its bracket score events?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="bracket_id" value="<?php echo h($bracketId); ?>">
                <button class="btn danger" type="submit">Delete Bracket</button>
            </form>
        </div>
    <?php endif; ?>
    </main>
</div>
</div>
<script>
(function () {
    var type = document.getElementById('bracket-type');
    var help = document.getElementById('bracket-type-help');
    if (!type || !help) return;
    type.addEventListener('change', function () {
        help.textContent = this.value === 'multi_round'
            ? 'Multi-Round requires a power-of-two number of teams (2, 4, 8, 16, 32, or 64).'
            : 'Single Round keeps every matchup independent.';
    });
})();
</script>
</body>
</html>
