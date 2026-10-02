<?php
session_start();
require __DIR__ . '/config.php';
require_once __DIR__ . '/bracket-bootstrap.php';
if (empty($_SESSION['admin'])) { header('Location: admin-login.php'); exit; }
$db = getDb();
bracketBootstrap($db);
$error = '';
$message = '';

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function teamName(PDO $db, $id) {
    if ($id === null) return 'TBD';
    static $c = [];
    if (isset($c[$id])) return $c[$id];
    $s = $db->prepare('SELECT name FROM squadrons WHERE id=?');
    $s->execute([(int)$id]);
    return $c[$id] = ($s->fetchColumn() ?: 'Unknown');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    $bid = (string)($_POST['bracket_id'] ?? '');
    try {
        if ($a === 'create') {
            $name = trim($_POST['name'] ?? '');
            $ids = array_values(array_unique(array_map('intval', $_POST['participant_ids'] ?? [])));
            if ($name === '') throw new Exception('Enter a bracket name.');
            if (count($ids) < 2) throw new Exception('Select at least two teams.');

            $validIds = array_map('intval', array_column($db->query('SELECT id FROM squadrons')->fetchAll(PDO::FETCH_ASSOC), 'id'));
            if (count(array_diff($ids, $validIds)) > 0) throw new Exception('One or more selected teams are invalid.');

            $db->beginTransaction();
            $now = date('c');
            $id = bin2hex(random_bytes(6));
            $db->prepare('INSERT INTO brackets(id,name,created_date,updated_at,champion_id,rounds) VALUES(?,?,?,?,?,?)')
                ->execute([$id, $name, $now, $now, null, null]);

            $p = $db->prepare('INSERT INTO bracket_participants(bracket_id,squadron_id,seed,created_at) VALUES(?,?,?,?)');
            foreach ($ids as $i => $sid) $p->execute([$id, $sid, $i + 1, $now]);

            $rc = max(1, (int)ceil(log(count($ids), 2)));
            $r = $db->prepare('INSERT INTO bracket_rounds(bracket_id,round_number,name,created_at) VALUES(?,?,?,?)');
            $roundIds = [];
            for ($x = 1; $x <= $rc; $x++) {
                $nameForRound = $x === $rc ? 'Final' : ($x === $rc - 1 ? 'Semifinal' : 'Round ' . $x);
                $r->execute([$id, $x, $nameForRound, $now]);
                $roundIds[$x] = (int)$db->lastInsertId();
            }

            $mids = [];
            for ($rno = 1; $rno <= $rc; $rno++) {
                for ($i = 0; $i < 2 ** ($rc - $rno); $i++) $mids[$rno][$i] = bin2hex(random_bytes(5));
            }

            $m = $db->prepare('INSERT INTO bracket_matchups(id,bracket_id,round_id,match_number,team1_id,team2_id,team1_source_matchup_id,team2_source_matchup_id,team1_score,team2_score,winner_id,points,status,next_matchup_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($mids as $rno => $ms) {
                foreach ($ms as $i => $mid) {
                    $t1 = $rno === 1 ? ($ids[$i * 2] ?? null) : null;
                    $t2 = $rno === 1 ? ($ids[$i * 2 + 1] ?? null) : null;
                    $src1 = $rno > 1 ? $mids[$rno - 1][$i * 2] : null;
                    $src2 = $rno > 1 ? $mids[$rno - 1][$i * 2 + 1] : null;
                    $next = $rno < $rc ? $mids[$rno + 1][intdiv($i, 2)] : null;
                    $status = ($t1 !== null && $t2 !== null) ? 'ready' : 'pending';
                    $m->execute([$mid, $id, $roundIds[$rno], $i + 1, $t1, $t2, $src1, $src2, null, null, null, 0, $status, $next, $now, $now]);
                }
            }
            $db->commit();
            header('Location: admin-brackets.php?bracket_id=' . rawurlencode($id));
            exit;
        }

        if ($a === 'assign') {
            $t1 = ($_POST['team1_id'] ?? '') !== '' ? (int)$_POST['team1_id'] : null;
            $t2 = ($_POST['team2_id'] ?? '') !== '' ? (int)$_POST['team2_id'] : null;
            if ($t1 !== null && $t1 === $t2) throw new Exception('A matchup cannot use the same team twice.');
            $m = $db->prepare('UPDATE bracket_matchups SET team1_id=?,team2_id=?,status=?,team1_score=NULL,team2_score=NULL,winner_id=NULL,points=0,updated_at=? WHERE id=? AND bracket_id=?');
            $m->execute([$t1, $t2, $t1 !== null && $t2 !== null ? 'ready' : 'pending', date('c'), $_POST['matchup_id'], $bid]);
            $message = 'Teams saved. Scores can be entered later.';
        }

        if ($a === 'score') {
            $s1 = (int)$_POST['team1_score'];
            $s2 = (int)$_POST['team2_score'];
            $points = (float)($_POST['points'] ?? 0);
            if ($s1 === $s2) throw new Exception('A completed matchup cannot be tied.');
            $q = $db->prepare('SELECT * FROM bracket_matchups WHERE id=? AND bracket_id=?');
            $q->execute([$_POST['matchup_id'], $bid]);
            $m = $q->fetch();
            if (!$m || $m['team1_id'] === null || $m['team2_id'] === null) throw new Exception('Both teams must be set before entering a score.');

            $winner = $s1 > $s2 ? (int)$m['team1_id'] : (int)$m['team2_id'];
            $db->beginTransaction();
            $db->prepare("UPDATE bracket_matchups SET team1_score=?,team2_score=?,winner_id=?,points=?,status='completed',updated_at=? WHERE id=? AND bracket_id=?")
                ->execute([$s1, $s2, $winner, $points, date('c'), $m['id'], $bid]);

            if ($m['next_matchup_id']) {
                $q = $db->prepare('SELECT * FROM bracket_matchups WHERE id=? AND bracket_id=?');
                $q->execute([$m['next_matchup_id'], $bid]);
                $n = $q->fetch();
                if ($n) {
                    $slot = ((int)$m['match_number'] % 2 === 1) ? 'team1_id' : 'team2_id';
                    $db->prepare("UPDATE bracket_matchups SET $slot=?,updated_at=? WHERE id=? AND bracket_id=?")
                        ->execute([$winner, date('c'), $n['id'], $bid]);
                    $ready = $slot === 'team1_id' ? ($n['team2_id'] !== null) : ($n['team1_id'] !== null);
                    if ($ready) {
                        $db->prepare('UPDATE bracket_matchups SET status=? WHERE id=? AND bracket_id=?')
                            ->execute(['ready', $n['id'], $bid]);
                    }
                }
            } else {
                $db->prepare('UPDATE brackets SET champion_id=?,updated_at=? WHERE id=?')->execute([$winner, date('c'), $bid]);
            }
            $db->commit();
            $message = 'Score saved and winner advanced.';
        }

        if ($a === 'delete') {
            $db->prepare('DELETE FROM brackets WHERE id=?')->execute([$bid]);
            header('Location: admin-brackets.php');
            exit;
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

$all = $db->query('SELECT id,name FROM brackets ORDER BY updated_at DESC')->fetchAll();
$bid = (string)($_GET['bracket_id'] ?? $_POST['bracket_id'] ?? ($all[0]['id'] ?? ''));
$bracket = $bid ? bracketLoad($db, $bid) : null;
$teams = $db->query('SELECT id,name FROM squadrons ORDER BY id')->fetchAll();
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Manage Brackets</title>
<style>body{font-family:Arial;background:#eef1f5;margin:0;color:#182334}.wrap{max-width:1400px;margin:auto;padding:24px}.card{background:#fff;border-radius:10px;padding:20px;margin-bottom:18px;box-shadow:0 2px 8px #0001}.layout{display:grid;grid-template-columns:300px 1fr;gap:18px}.bracket{display:block;padding:11px;border-radius:6px;text-decoration:none;color:#182334}.active{background:#e7edf6;font-weight:bold}.input,select{width:100%;padding:9px;border:1px solid #ccd2db;border-radius:5px;box-sizing:border-box}.picks{display:grid;grid-template-columns:1fr 1fr;gap:6px}.pick{padding:8px;background:#f5f6f8;border-radius:5px}.rounds{display:flex;gap:16px;overflow-x:auto}.round{min-width:290px}.match{background:#f8f9fb;border:1px solid #dce1e8;border-radius:8px;padding:12px;margin:14px 0}.btn{padding:9px 13px;border:0;border-radius:6px;background:#12355b;color:#fff;cursor:pointer}.danger{background:#a52222}.muted{color:#6b7685;font-size:.9em}.msg{color:#176b3b}.err{color:#a52222}.scores{display:flex;gap:6px;align-items:center}.score{width:65px;padding:8px}.create-link{display:inline-block;margin-bottom:12px}</style></head><body><div class="wrap"><div class="card"><h1>Manage Brackets</h1><p class="muted">Create brackets, assign teams, enter scores, and advance winners from one place.</p><?php if($error):?><p class="err"><?php echo h($error);?></p><?php endif;?><?php if($message):?><p class="msg"><?php echo h($message);?></p><?php endif;?></div><div class="layout"><aside><div class="card"><h2>Brackets</h2><?php foreach($all as $b):?><a class="bracket <?php echo $b['id']===$bid?'active':'';?>" href="?bracket_id=<?php echo h($b['id']);?>"><?php echo h($b['name']);?></a><?php endforeach;?></div><div class="card"><h2>New Bracket</h2><form method="post"><input type="hidden" name="action" value="create"><input class="input" name="name" placeholder="Bracket name" required><p class="muted">Choose the participating squadrons. Bracket size is generated automatically, with open slots shown as TBD.</p><div class="picks"><?php foreach($teams as $t):?><label class="pick"><input type="checkbox" name="participant_ids[]" value="<?php echo (int)$t['id'];?>"> <?php echo h($t['name']);?></label><?php endforeach;?></div><p><button class="btn">Create Bracket</button></p></form></div></aside><main><?php if(!$bracket):?><div class="card"><h2><?php echo $all?'Select a bracket':'Create your first bracket';?></h2></div><?php else:?><div class="card"><h2><?php echo h($bracket['name']);?></h2><?php if(!empty($bracket['champion_id'])):?><p><strong>Champion:</strong> <?php echo h(teamName($db,$bracket['champion_id']));?></p><?php endif;?><p class="muted">Set teams first. Leave scores blank until games are played.</p><div class="rounds"><?php foreach($bracket['rounds'] as $round):?><section class="round"><h3><?php echo h($round['name']);?></h3><?php foreach($round['matchups'] as $m):?><div class="match"><div class="muted">Match <?php echo (int)$m['match_number'];?> · <?php echo h($m['status']);?></div><form method="post"><input type="hidden" name="action" value="assign"><input type="hidden" name="bracket_id" value="<?php echo h($bid);?>"><input type="hidden" name="matchup_id" value="<?php echo h($m['id']);?>"><p><select name="team1_id"><option value="">TBD</option><?php foreach($teams as $t):?><option value="<?php echo (int)$t['id'];?>" <?php echo (string)$m['team1_id']===(string)$t['id']?'selected':'';?>><?php echo h($t['name']);?></option><?php endforeach;?></select></p><p><select name="team2_id"><option value="">TBD</option><?php foreach($teams as $t):?><option value="<?php echo (int)$t['id'];?>" <?php echo (string)$m['team2_id']===(string)$t['id']?'selected':'';?>><?php echo h($t['name']);?></option><?php endforeach;?></select></p><button class="btn">Save Teams</button></form><?php if($m['team1_id']!==null&&$m['team2_id']!==null):?><p><?php echo h(teamName($db,$m['team1_id']));?>: <?php echo $m['team1_score']===null?'—':(int)$m['team1_score'];?></p><p><?php echo h(teamName($db,$m['team2_id']));?>: <?php echo $m['team2_score']===null?'—':(int)$m['team2_score'];?></p><?php if($m['status']!=='completed'):?><form method="post" class="scores"><input type="hidden" name="action" value="score"><input type="hidden" name="bracket_id" value="<?php echo h($bid);?>"><input type="hidden" name="matchup_id" value="<?php echo h($m['id']);?>"><input class="score" type="number" min="0" name="team1_score" placeholder="T1" required><input class="score" type="number" min="0" name="team2_score" placeholder="T2" required><input class="score" type="number" min="0" step="0.01" name="points" value="0" title="Points awarded"><button class="btn">Save Score</button></form><?php endif;?><?php endif;?></div><?php endforeach;?></section><?php endforeach;?></div><form method="post" onsubmit="return confirm('Delete this bracket?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="bracket_id" value="<?php echo h($bid);?>"><button class="btn danger">Delete Bracket</button></form></div><?php endif;?></main></div></div></body></html>