<?php
session_start();
require __DIR__ . '/config.php';
require_once __DIR__ . '/bracket-bootstrap.php';
if (empty($_SESSION['admin'])) { header('Location: admin-login.php'); exit; }
$db=getDb(); bracketBootstrap($db);
$bracketId=(string)($_GET['bracket_id'] ?? $_POST['bracket_id'] ?? '');
$error=''; $message='';
if (!$bracketId) { $row=$db->query('SELECT id FROM brackets ORDER BY updated_at DESC LIMIT 1')->fetch(); $bracketId=$row['id']??''; }

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='result') {
    $matchId=(string)($_POST['matchup_id']??''); $s1=(int)($_POST['team1_score']??0); $s2=(int)($_POST['team2_score']??0); $points=(float)($_POST['points']??0);
    try {
        $stmt=$db->prepare('SELECT * FROM bracket_matchups WHERE id=? AND bracket_id=?'); $stmt->execute([$matchId,$bracketId]); $m=$stmt->fetch();
        if(!$m) throw new Exception('Matchup not found.');
        if($m['team1_id']===null || $m['team2_id']===null) throw new Exception('Both teams must be determined before recording a result.');
        if($s1===$s2) throw new Exception('A completed single-elimination matchup cannot be tied.');
        $winner=$s1>$s2?(int)$m['team1_id']:(int)$m['team2_id'];
        $db->beginTransaction();
        $u=$db->prepare('UPDATE bracket_matchups SET team1_score=?,team2_score=?,winner_id=?,points=?,status=\'completed\',updated_at=? WHERE id=? AND bracket_id=?');
        $u->execute([$s1,$s2,$winner,$points,date('c'),$matchId,$bracketId]);
        if($m['next_matchup_id']) {
            $next=$db->prepare('SELECT * FROM bracket_matchups WHERE id=? AND bracket_id=?'); $next->execute([$m['next_matchup_id'],$bracketId]); $nm=$next->fetch();
            if($nm) {
                $slot = ((int)$m['match_number'] % 2 === 1) ? 'team1_id' : 'team2_id';
                $db->prepare("UPDATE bracket_matchups SET $slot=?, updated_at=? WHERE id=?")->execute([$winner,date('c'),$nm['id']]);
            }
        }
        $db->commit(); $message='Result recorded and winner advanced.';
    } catch(Throwable $e){ if($db->inTransaction())$db->rollBack(); $error=$e->getMessage(); }
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete') {
    try { $stmt=$db->prepare('DELETE FROM brackets WHERE id=?'); $stmt->execute([$bracketId]); header('Location: admin-bracket.php'); exit; } catch(Throwable $e){$error=$e->getMessage();}
}
$bracket=$bracketId?bracketLoad($db,$bracketId):null;
$all=$db->query('SELECT id,name FROM brackets ORDER BY updated_at DESC')->fetchAll();
function teamName($db,$id){ if($id===null)return 'TBD'; static $cache=[]; if(isset($cache[$id]))return $cache[$id]; $s=$db->prepare('SELECT name FROM squadrons WHERE id=?');$s->execute([$id]);return $cache[$id]=$s->fetchColumn()?:'Unknown'; }
?><!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bracket Manager</title><style>body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px;color:#222}.container{max-width:1300px;margin:auto}.box{background:#fff;padding:20px;border-radius:7px;margin-bottom:20px;box-shadow:0 1px 4px #ddd}.rounds{display:flex;gap:18px;overflow-x:auto;align-items:flex-start}.round{min-width:270px;background:#f7f7f7;padding:12px;border-radius:6px}.match{background:#fff;border:1px solid #ddd;border-radius:5px;padding:12px;margin:12px 0}.team{padding:8px;background:#f1f1f1;margin:4px 0}.winner{font-weight:bold;background:#dff2e1}.form{border-top:1px solid #ddd;margin-top:10px;padding-top:10px}.form input{width:70px;padding:7px}.btn{padding:8px 12px;background:#002147;color:#fff;border:0;border-radius:4px;cursor:pointer}.danger{background:#b00020}</style></head><body><div class="container"><div class="box"><h1>Bracket Manager</h1><form method="get"><label>Bracket <select name="bracket_id" onchange="this.form.submit()"><option value="">Select bracket</option><?php foreach($all as $b):?><option value="<?php echo htmlspecialchars($b['id']);?>" <?php echo $b['id']===$bracketId?'selected':'';?>><?php echo htmlspecialchars($b['name']);?></option><?php endforeach;?></select></label></form><?php if($error):?><p style="color:#b00020"><?php echo htmlspecialchars($error);?></p><?php endif;?><?php if($message):?><p style="color:#176b1a"><?php echo htmlspecialchars($message);?></p><?php endif;?></div><?php if($bracket):?><div class="box"><h2><?php echo htmlspecialchars($bracket['name']);?></h2><div class="rounds"><?php foreach($bracket['rounds'] as $round):?><div class="round"><h3><?php echo htmlspecialchars($round['name']);?></h3><?php foreach($round['matchups'] as $m):?><div class="match"><div class="team <?php echo $m['winner_id']==$m['team1_id']?'winner':'';?>"><?php echo htmlspecialchars(teamName($db,$m['team1_id']));?> <?php echo $m['team1_score']!==null?'('.(int)$m['team1_score'].')':'';?></div><div class="team <?php echo $m['winner_id']==$m['team2_id']?'winner':'';?>"><?php echo htmlspecialchars(teamName($db,$m['team2_id']));?> <?php echo $m['team2_score']!==null?'('.(int)$m['team2_score'].')':'';?></div><?php if($m['team1_id']!==null&&$m['team2_id']!==null&&$m['status']!=='completed'):?><form class="form" method="post"><input type="hidden" name="action" value="result"><input type="hidden" name="bracket_id" value="<?php echo htmlspecialchars($bracketId);?>"><input type="hidden" name="matchup_id" value="<?php echo htmlspecialchars($m['id']);?>"><input type="number" name="team1_score" min="0" required placeholder="T1"><input type="number" name="team2_score" min="0" required placeholder="T2"><input type="number" name="points" min="0" step="0.01" value="0" placeholder="Pts"><button class="btn" type="submit">Record</button></form><?php endif;?></div><?php endforeach;?></div><?php endforeach;?></div><form method="post" style="margin-top:20px" onsubmit="return confirm('Delete this bracket?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="bracket_id" value="<?php echo htmlspecialchars($bracketId);?>"><button class="btn danger">Delete Bracket</button></form></div><?php elseif($all):?><div class="box">Select a bracket above.</div><?php else:?><div class="box">No brackets exist. <a href="admin-create-bracket.php">Create one</a>.</div><?php endif;?></div></body></html>
