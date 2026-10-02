<?php
session_start();
require __DIR__ . '/config.php';
require_once __DIR__ . '/bracket-bootstrap.php';

if (empty($_SESSION['admin'])) { header('Location: admin-login.php'); exit; }
$db = getDb();
bracketBootstrap($db);
$squadrons = bracketSquadrons();
$error = '';
$participantCount = (int)($_POST['participant_count'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['tournament_name'] ?? '');
    $participantIds = array_values(array_unique(array_map('intval', (array)($_POST['participant_ids'] ?? []))));
    $validCounts = [4, 8, 16, 32, 64];

    if ($name === '') $error = 'Tournament name is required.';
    elseif (!in_array($participantCount, $validCounts, true)) $error = 'Invalid participant count.';
    elseif (count($participantIds) !== $participantCount) $error = 'You must select exactly ' . $participantCount . ' participants.';
    else {
        $validIds = array_map('intval', array_column($squadrons, 'id'));
        if (count(array_diff($participantIds, $validIds)) > 0) {
            $error = 'One or more selected teams are invalid.';
        } else {
            $bracketId = bin2hex(random_bytes(12));
            $now = date('c');
            $numRounds = (int)log($participantCount, 2);
            $roundNames = ['Round of ' . $participantCount, 'Quarterfinals', 'Semifinals', 'Finals'];
            $bracket = ['id'=>$bracketId,'name'=>$name,'created_date'=>$now,'participants'=>[],'rounds'=>[],'champion_id'=>null];
            foreach ($participantIds as $i => $id) $bracket['participants'][] = ['squadron_id'=>$id,'seed'=>$i+1];

            // Build all rounds first. Then wire each matchup to the next round;
            // this avoids referencing a round that has not been created yet.
            $matchCounts = [];
            for ($r = 0; $r < $numRounds; $r++) {
                $count = (int)($participantCount / (2 ** ($r + 1)));
                $round = ['round_num'=>$r+1,'round_name'=>$roundNames[$r] ?? ('Round '.($r+1)),'matchups'=>[]];
                for ($m=0; $m<$count; $m++) {
                    $round['matchups'][] = ['id'=>bin2hex(random_bytes(8)),'team1_id'=>null,'team2_id'=>null,'team1_score'=>null,'team2_score'=>null,'winner_id'=>null,'status'=>'upcoming','points'=>0,'next_match_id'=>null];
                }
                $bracket['rounds'][] = $round;
                $matchCounts[] = $count;
            }
            foreach ($participantIds as $i => $id) {
                $m = intdiv($i, 2);
                if ($i % 2 === 0) $bracket['rounds'][0]['matchups'][$m]['team1_id'] = $id;
                else $bracket['rounds'][0]['matchups'][$m]['team2_id'] = $id;
            }
            for ($r=0; $r<$numRounds-1; $r++) {
                foreach ($bracket['rounds'][$r]['matchups'] as $m => &$match) {
                    $match['next_match_id'] = $bracket['rounds'][$r+1]['matchups'][intdiv($m,2)]['id'];
                }
                unset($match);
            }

            try { bracketSave($db, $bracket); }
            catch (Throwable $e) { $error = 'Database error: '.$e->getMessage(); }
            if (!$error) { header('Location: admin-bracket.php?bracket_id='.rawurlencode($bracketId)); exit; }
        }
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Create Tournament</title>
<style>body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px}.container{max-width:900px;margin:auto}.card{background:#fff;padding:25px;border-radius:8px;margin-bottom:20px}h1{color:#002147}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px}.team{padding:10px;border:1px solid #ddd;border-radius:5px}.count{display:flex;gap:8px;flex-wrap:wrap}.count button{padding:10px 16px}.primary{background:#002147;color:white;border:0;padding:12px 18px;border-radius:4px}.error{background:#ffebee;color:#b00020;padding:10px;margin-bottom:15px}</style></head><body><div class="container"><div class="card"><h1>Create Tournament</h1><?php if($error):?><div class="error"><?php echo htmlspecialchars($error);?></div><?php endif;?><form method="post"><p><label>Tournament Name<br><input required style="width:100%;padding:10px" name="tournament_name" value="<?php echo htmlspecialchars($_POST['tournament_name']??'');?>"></label></p><h3>Teams</h3><div class="count"><?php foreach([4,8,16,32,64] as $n):?><button type="button" onclick="setCount(<?php echo $n;?>)"><?php echo $n;?> Teams</button><?php endforeach;?></div><input type="hidden" id="participant_count" name="participant_count" value="<?php echo $participantCount;?>"><p>Selected: <strong id="selected">0</strong> / <span id="required">0</span></p><div class="grid"><?php foreach($squadrons as $s):?><label class="team"><input type="checkbox" name="participant_ids[]" value="<?php echo (int)$s['id'];?>" onchange="updateCount()"> <?php echo htmlspecialchars($s['name']);?></label><?php endforeach;?></div><p><button class="primary" type="submit">Create Tournament</button> <a href="admin-panel.php">Back</a></p></form></div></div><script>function setCount(n){document.getElementById('participant_count').value=n;document.getElementById('required').textContent=n;document.querySelectorAll('input[name="participant_ids[]"]').forEach(x=>x.checked=false);updateCount()}function updateCount(){document.getElementById('selected').textContent=document.querySelectorAll('input[name="participant_ids[]"]:checked').length}document.getElementById('required').textContent=document.getElementById('participant_count').value||0;</script></body></html>
