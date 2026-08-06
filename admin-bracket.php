<?php
session_start();
require __DIR__ . '/config.php';
if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

$squadrons = readJson(DATA_DIR . '/squadrons.json');
$squadronMap = [];
foreach ($squadrons as $s) $squadronMap[$s['id']] = $s;

$brackets = readJson(DATA_DIR . '/brackets.json');
$selectedBracketId = $_GET['bracket_id'] ?? null;
$currentBracket = null;

if ($selectedBracketId) {
    foreach ($brackets as &$b) {
        if ($b['id'] === $selectedBracketId) {
            $currentBracket = &$b;
            break;
        }
    }
}

if ($_POST && isset($_POST['create_tournament'])) {
    $name = trim($_POST['tournament_name'] ?? '');
    if ($name) {
        $newTourney = ['id' => bin2hex(random_bytes(8)), 'name' => $name, 'created_date' => date('c'), 'rounds' => [], 'champion_id' => null];
        $brackets[] = $newTourney;
        writeJson(DATA_DIR . '/brackets.json', $brackets);
        header('Location: admin-bracket.php?bracket_id=' . $newTourney['id']);
        exit;
    }
}

if ($_POST && isset($_POST['add_matchup']) && $currentBracket) {
    $team1 = (int)($_POST['team1_id'] ?? 0);
    $team2 = (int)($_POST['team2_id'] ?? 0);
    $round = (int)($_POST['round_num'] ?? 1);
    if ($team1 && $team2 && $team1 !== $team2) {
        if (!isset($currentBracket['rounds'][$round - 1])) {
            $currentBracket['rounds'][$round - 1] = ['round_num' => $round, 'matchups' => []];
        }
        $currentBracket['rounds'][$round - 1]['matchups'][] = ['id' => bin2hex(random_bytes(8)), 'team1_id' => $team1, 'team2_id' => $team2, 'team1_score' => null, 'team2_score' => null, 'winner_id' => null];
        foreach ($brackets as &$b) { if ($b['id'] === $currentBracket['id']) { $b = $currentBracket; break; } }
        writeJson(DATA_DIR . '/brackets.json', $brackets);
        header('Location: admin-bracket.php?bracket_id=' . $selectedBracketId);
        exit;
    }
}

if ($_POST && isset($_POST['record_result']) && $currentBracket) {
    $roundNum = (int)($_POST['round_num'] ?? 0);
    $matchupId = $_POST['matchup_id'] ?? '';
    $winnerId = (int)($_POST['winner_id'] ?? 0);
    $score1 = (int)($_POST['team1_score'] ?? 0);
    $score2 = (int)($_POST['team2_score'] ?? 0);
    if ($roundNum > 0 && $matchupId && $winnerId) {
        if (isset($currentBracket['rounds'][$roundNum - 1])) {
            $round = &$currentBracket['rounds'][$roundNum - 1];
            foreach ($round['matchups'] as &$matchup) {
                if ($matchup['id'] === $matchupId) {
                    $matchup['team1_score'] = $score1;
                    $matchup['team2_score'] = $score2;
                    $matchup['winner_id'] = $winnerId;
                    $scores = readJson(DATA_DIR . '/scores.json');
                    $loser = $matchup['team1_id'] === $winnerId ? $matchup['team2_id'] : $matchup['team1_id'];
                    $scores[] = ['squadron_id' => $winnerId, 'event_type' => 'bracket', 'tournament_name' => $currentBracket['name'], 'opponent_id' => $loser, 'team1_score' => $matchup['team1_id'] === $winnerId ? $score1 : $score2, 'team2_score' => $matchup['team2_id'] === $winnerId ? $score2 : $score1, 'winner_id' => $winnerId, 'timestamp' => date('c')];
                    writeJson(DATA_DIR . '/scores.json', $scores);
                    break;
                }
            }
        }
        foreach ($brackets as &$b) { if ($b['id'] === $currentBracket['id']) { $b = $currentBracket; break; } }
        writeJson(DATA_DIR . '/brackets.json', $brackets);
        header('Location: admin-bracket.php?bracket_id=' . $selectedBracketId);
        exit;
    }
}

if (isset($_GET['delete']) && $_GET['delete'] === 'true') {
    $brackets = array_filter($brackets, fn($b) => $b['id'] !== $selectedBracketId);
    writeJson(DATA_DIR . '/brackets.json', array_values($brackets));
    header('Location: admin-bracket.php');
    exit;
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Bracket Manager</title><style>body{font-family:Arial;background:#f4f4f4;margin:0;padding:20px}.container{max-width:1000px;margin:0 auto}h1{color:#002147}h2{color:#003366;margin-top:30px}.box{background:#fff;padding:20px;border-radius:6px;box-shadow:0 2px 4px rgba(0,0,0,0.1);margin-bottom:20px}select,input,button{padding:10px;border:1px solid #ddd;border-radius:3px;margin:5px 0;width:100%}button{background:#002147;color:#fff;cursor:pointer;font-weight:bold;width:auto}button:hover{background:#003366}.matchup{background:#f5f5f5;padding:15px;border-radius:4px;margin:10px 0;border-left:4px solid #003366}.team-row{padding:8px;background:#fff;border-radius:3px;margin:5px 0}.winner{background:#d4edda!important;font-weight:bold;color:#155724}.nav{text-align:center;margin-top:30px}.nav a{color:#002147;text-decoration:none;margin:0 15px}</style></head><body><div class="container"><h1>🏆 Bracket Manager</h1><div class="box"><h2>Tournament</h2><select onchange="if(this.value)window.location='admin-bracket.php?bracket_id='+this.value"><option value="">-- Select --</option><?php foreach($brackets as $b):?><option value="<?php echo $b['id'];?>" <?php echo $selectedBracketId===$b['id']?'selected':'';?>><?php echo htmlspecialchars($b['name']);?></option><?php endforeach;?></select><h3>Create New</h3><form method="POST" style="display:flex;gap:10px"><input type="text" name="tournament_name" placeholder="Name" required><button type="submit" name="create_tournament">Create</button></form></div><?php if($currentBracket):?><div class="box"><div style="display:flex;justify-content:space-between"><h2><?php echo htmlspecialchars($currentBracket['name']);?></h2><a href="?bracket_id=<?php echo $currentBracket['id'];?>&delete=true" onclick="return confirm('Delete?')" style="color:#d32f2f">🗑️</a></div><h3>Add Matchup</h3><form method="POST" style="display:flex;gap:10px;flex-wrap:wrap"><select name="team1_id" required style="flex:1"><option value="">Team 1</option><?php foreach($squadrons as $s):?><option value="<?php echo $s['id'];?>"><?php echo htmlspecialchars($s['name']);?></option><?php endforeach;?></select><select name="team2_id" required style="flex:1"><option value="">Team 2</option><?php foreach($squadrons as $s):?><option value="<?php echo $s['id'];?>"><?php echo htmlspecialchars($s['name']);?></option><?php endforeach;?></select><input type="number" name="round_num" value="1" min="1" required style="width:100px"><button type="submit" name="add_matchup">Add</button></form><?php if($currentBracket['rounds']):?><?php foreach($currentBracket['rounds'] as $round):?><h3 style="margin-top:30px">Round <?php echo $round['round_num'];?></h3><?php foreach($round['matchups'] as $matchup):$t1=$squadronMap[$matchup['team1_id']]??null;$t2=$squadronMap[$matchup['team2_id']]??null;$winner=$matchup['winner_id'];?><div class="matchup"><p style="margin:0 0 10px 0;font-weight:bold">Match <?php echo substr($matchup['id'],0,6);?></p><form method="POST"><input type="hidden" name="round_num" value="<?php echo $round['round_num'];?>"><input type="hidden" name="matchup_id" value="<?php echo $matchup['id'];?>"><div class="team-row <?php echo $winner===$matchup['team1_id']?'winner':'';?>"><?php echo htmlspecialchars($t1['name']??'TBD');?></div><input type="number" name="team1_score" value="<?php echo $matchup['team1_score']??'';?>" placeholder="Score"><div class="team-row <?php echo $winner===$matchup['team2_id']?'winner':'';?>"><?php echo htmlspecialchars($t2['name']??'TBD');?></div><input type="number" name="team2_score" value="<?php echo $matchup['team2_score']??'';?>" placeholder="Score"><?php if(!$winner):?><div style="margin-top:8px"><label><input type="radio" name="winner_id" value="<?php echo $matchup['team1_id'];?>"> <?php echo substr($t1['name']??'TBD',0,3);?></label><label style="margin-left:15px"><input type="radio" name="winner_id" value="<?php echo $matchup['team2_id'];?>"> <?php echo substr($t2['name']??'TBD',0,3);?></label><button type="submit" name="record_result" style="width:auto;margin-left:15px">Set</button></div><?php else:?><div style="margin-top:8px;color:#28a745;font-weight:bold">✓</div><?php endif;?></form></div><?php endforeach;?><?php endforeach;?><?php endif;?></div><?php endif;?><div class="nav"><a href="admin-panel.php">← Admin</a></div></div></body></html>
