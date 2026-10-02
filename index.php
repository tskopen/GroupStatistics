<?php

header('Cache-Control: no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require __DIR__ . '/config.php';
require __DIR__ . '/theme-loader.php';
require_once __DIR__ . '/bracket-bootstrap.php';
$theme = loadTheme();

$db = getDb();
bracketBootstrap($db);
bracketEnsureTables($db);

$stmt = $db->prepare("SELECT * FROM squadrons ORDER BY id");
$stmt->execute();
$squadrons = $stmt->fetchAll();

$stmt = $db->prepare("SELECT * FROM events ORDER BY timestamp DESC");
$stmt->execute();
$scores = $stmt->fetchAll();
foreach ($scores as &$scoreRow) $scoreRow['event_type'] = normalizeEventType($scoreRow['event_type'] ?? 'other');
unset($scoreRow);

$squadronMap = [];
foreach ($squadrons as $s) $squadronMap[$s['id']] = $s;

$squadronRankings = getSquadronRankings();
$ranked = [];
foreach ($squadronRankings as $row) {
    $squadron = $squadronMap[$row['squadron_id']] ?? null;
    if ($squadron !== null) $ranked[] = ['squadron' => $squadron, 'total' => $row['total']];
}

// Brackets are loaded from the relational bracket tables. The existing
// tournament-card markup below is retained, so old homepage presentation
// continues to work while SQLite remains the source of truth.
$bracketsByTournament = [];
$bracketRows = $db->query("SELECT id,name,created_date,updated_at,champion_id,bracket_type FROM brackets ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($bracketRows as $bracketRow) {
    $bracket = bracketLoad($db, (string)$bracketRow['id']);
    if (!$bracket) continue;
    $tournament = [
        'tournament_name' => $bracket['name'] ?? 'Bracket',
        'bracket_type' => $bracket['bracket_type'] ?? 'multi_round',
        'champion_id' => $bracket['champion_id'] ?? null,
        'matches' => [],
        'latest_timestamp' => $bracket['updated_at'] ?? $bracket['created_date'] ?? 0,
    ];
    foreach (($bracket['rounds'] ?? []) as $round) {
        foreach (($round['matchups'] ?? []) as $matchup) {
            $tournament['matches'][] = [
                'round_name' => $round['name'] ?? 'Round',
                'match_number' => $matchup['match_number'] ?? null,
                'squadron_id' => $matchup['team1_id'] ?? null,
                'opponent_id' => $matchup['team2_id'] ?? null,
                'team1_score' => $matchup['team1_score'] ?? '-',
                'team2_score' => $matchup['team2_score'] ?? '-',
                'winner_id' => $matchup['winner_id'] ?? null,
                'status' => $matchup['status'] ?? 'pending',
                'value' => $matchup['points'] ?? 0,
            ];
        }
    }
    if (!empty($tournament['matches'])) $bracketsByTournament[] = $tournament;
}

$regularEvents = array_values(array_filter($scores, fn($event) => ($event['event_type'] ?? 'other') !== 'bracket'));

$samisByEvent = [];
$samisGroups = [];
foreach ($regularEvents as $event) {
    if (($event['event_type'] ?? '') !== 'samis') continue;
    $eventName = $event['event_name'] ?? 'Samis';
    if (!isset($samisGroups[$eventName])) $samisGroups[$eventName] = ['event_name'=>$eventName,'results'=>[],'latest_timestamp'=>0];
    $samisGroups[$eventName]['results'][] = ['squadron_id'=>$event['squadron_id']??null,'value'=>$event['value']??null,'timestamp'=>$event['timestamp']??null];
    $ts = strtotime($event['timestamp'] ?? '') ?: 0;
    if ($ts > $samisGroups[$eventName]['latest_timestamp']) $samisGroups[$eventName]['latest_timestamp'] = $ts;
}
$samisByEvent = array_values($samisGroups);

$pftByEvent = [];
$pftGroups = [];
foreach ($regularEvents as $event) {
    if (($event['event_type'] ?? '') !== 'pft') continue;
    $eventName = $event['event_name'] ?? 'PFT';
    if (!isset($pftGroups[$eventName])) $pftGroups[$eventName] = ['event_name'=>$eventName,'event_type'=>'pft','results'=>[],'latest_timestamp'=>0];
    $pftGroups[$eventName]['results'][] = ['squadron_id'=>$event['squadron_id']??null,'value'=>$event['value']??null,'timestamp'=>$event['timestamp']??null];
    $ts = strtotime($event['timestamp'] ?? '') ?: 0;
    if ($ts > $pftGroups[$eventName]['latest_timestamp']) $pftGroups[$eventName]['latest_timestamp'] = $ts;
}
$pftByEvent = array_values($pftGroups);

$otherByEvent = [];
$otherGroups = [];
foreach ($regularEvents as $event) {
    if (($event['event_type'] ?? '') !== 'other') continue;
    $eventName = $event['event_name'] ?? 'Other Event';
    if (!isset($otherGroups[$eventName])) $otherGroups[$eventName] = ['event_name'=>$eventName,'event_type'=>'other','results'=>[],'latest_timestamp'=>0];
    $otherGroups[$eventName]['results'][] = ['squadron_id'=>$event['squadron_id']??null,'value'=>$event['value']??null,'timestamp'=>$event['timestamp']??null];
    $ts = strtotime($event['timestamp'] ?? '') ?: 0;
    if ($ts > $otherGroups[$eventName]['latest_timestamp']) $otherGroups[$eventName]['latest_timestamp'] = $ts;
}
$otherByEvent = array_values($otherGroups);

$regularEvents = array_values(array_filter($regularEvents, fn($event) => !in_array($event['event_type'] ?? '', ['pft','other','samis'], true)));

usort($bracketsByTournament, fn($a,$b) => (strtotime($b['latest_timestamp'] ?? '') ?: 0) <=> (strtotime($a['latest_timestamp'] ?? '') ?: 0));
usort($samisByEvent, fn($a,$b) => ($b['latest_timestamp']??0) <=> ($a['latest_timestamp']??0));
usort($pftByEvent, fn($a,$b) => ($b['latest_timestamp']??0) <=> ($a['latest_timestamp']??0));
usort($otherByEvent, fn($a,$b) => ($b['latest_timestamp']??0) <=> ($a['latest_timestamp']??0));
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>USAFA Group 1 Squadron Tracker</title>
<link rel="manifest" href="manifest.json"><meta name="theme-color" content="#002147"><meta name="description" content="Track USAFA Group 1 squadron competition scores and tournament brackets">
<meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><meta name="apple-mobile-web-app-title" content="Squadron Tracker"><link rel="apple-touch-icon" href="pwa-icon.php?size=192">
<style>
:root{--primary-color:<?php echo htmlspecialchars($theme['primary_color']);?>;--secondary-color:<?php echo htmlspecialchars($theme['secondary_color']);?>;--accent-color:<?php echo htmlspecialchars($theme['accent_color']);?>;--background-color:<?php echo htmlspecialchars($theme['background_color']);?>;--text-color:<?php echo htmlspecialchars($theme['text_color']);?>}
*{box-sizing:border-box}body{font-family:Arial,sans-serif;background:var(--background-color);margin:0;padding:20px;color:var(--text-color)}.container{max-width:1200px;margin:0 auto}h1{text-align:center;color:var(--primary-color);margin-bottom:30px}h2{color:var(--primary-color);border-bottom:2px solid var(--primary-color);padding-bottom:8px;margin-top:30px}.rankings-table{width:100%;border-collapse:collapse;background:#fff;box-shadow:0 2px 4px rgba(0,0,0,.1);margin-bottom:30px}.rankings-table th{background:var(--primary-color);color:#fff;padding:12px;text-align:left}.rankings-table td{padding:12px;border-bottom:1px solid #ddd}.rankings-table tr:nth-child(2){background:#fff8dc;font-weight:bold}.icon{width:40px;height:40px;border-radius:4px;object-fit:cover;margin-right:10px;vertical-align:middle}.icon-placeholder{width:40px;height:40px;border-radius:4px;background:#ccc;display:inline-block;margin-right:10px;vertical-align:middle}.details-btn{padding:6px 12px;background:var(--primary-color);color:#fff;border:0;border-radius:4px;cursor:pointer}.events-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:20px;margin-bottom:30px}@media(max-width:768px){.events-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:480px){.events-grid{grid-template-columns:1fr}}.tournament-card{background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1);overflow:hidden;grid-column:span 2}.tournament-header{background:var(--primary-color);color:#fff;padding:16px;font-weight:bold;font-size:1.3em;text-align:center}.tournament-body{padding:15px}.tournament-match{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px;margin-bottom:10px;border-radius:6px;background:#f9f9f9}.match-team{flex:1;display:flex;align-items:center;gap:10px}.match-team.team-right{flex-direction:row-reverse;text-align:right}.match-team-icon{width:45px;height:45px;border-radius:4px;object-fit:cover;flex-shrink:0}.match-team-name{font-weight:bold;font-size:.95em}.match-score-block{display:flex;align-items:center;gap:8px;font-size:1.3em;font-weight:bold;color:#002147;padding:0 15px}.match-vs-label{font-weight:bold;color:#999;font-size:.9em}.match-points{font-size:.75em;color:#666;margin-top:4px;text-align:center}.match-winner{background:var(--accent-color)}.match-winner-check{color:#28a745;font-weight:bold;margin-left:6px}.bracket-meta{text-align:center;color:#667;font-size:.85em;margin-bottom:12px}.event-card{background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1);overflow:hidden}.event-header{background:var(--secondary-color);color:#fff;padding:12px;font-weight:bold;font-size:.9em;text-align:center}.event-body{padding:15px}.sami-card{background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1);overflow:hidden;grid-column:span 2}.sami-header{background:var(--secondary-color);color:#fff;padding:16px;font-weight:bold;font-size:1.3em;text-align:center}.sami-body{padding:15px}.sami-result{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px;margin-bottom:10px;border-radius:6px;background:#f9f9f9}.sami-result-icon{width:45px;height:45px;border-radius:4px;object-fit:cover}.regular-event{text-align:center}.regular-event-icon{width:60px;height:60px;margin:0 auto 10px;border-radius:4px;object-fit:cover}.regular-event-name{font-weight:bold;margin:5px 0}.regular-event-type{display:inline-block;background:#e3f2fd;color:#003366;padding:4px 8px;border-radius:3px;font-size:.75em;margin-bottom:8px}.regular-event-score{font-size:1.5em;font-weight:bold;color:#28a745}.footer{text-align:center;margin-top:40px}.footer a{color:#002147;text-decoration:none;margin:0 15px}
</style></head><body><div class="container">
<h1>⚔️ USAFA Group 1 Squadron Tracker</h1>
<h2>Overall Rankings</h2><table class="rankings-table"><tr><th>Rank</th><th>Squadron</th><th>Total Score</th><th>Change</th><th>Details</th></tr><?php $rank=1;foreach($ranked as $entry):$s=$entry['squadron'];?><tr><td>#<?php echo $rank;?></td><td><?php if(!empty($s['icon_filename'])):?><img src="<?php echo htmlspecialchars(iconUrl($s['icon_filename']));?>" alt="icon" class="icon"><?php else:?><span class="icon-placeholder"></span><?php endif;?><?php echo htmlspecialchars($s['name']);?></td><td><?php echo $entry['total'];?></td><td class="movement-cell">—</td><td><button type="button" class="details-btn">Details</button></td></tr><?php $rank++;endforeach;?></table>
<?php $stmt=$db->prepare("SELECT COUNT(*) as cnt FROM intramural_wl_records");$stmt->execute();$hasIntramurals=$stmt->fetch()['cnt']>0;?>
<?php if($hasIntramurals):?><h2>🏅 Intramural Standings</h2><p style="text-align:center;margin-bottom:20px"><a href="intramural-standings.php" style="color:var(--primary-color);text-decoration:none;font-weight:bold">View Full Intramural Standings →</a></p><?php endif;?>
<?php if($bracketsByTournament||$samisByEvent||$pftByEvent||$otherByEvent||$regularEvents):?><h2>🔥 Recent Events &amp; Results</h2><div class="events-grid">
<?php foreach($bracketsByTournament as $tournament):?><div class="tournament-card"><div class="tournament-header">🏆 <?php echo htmlspecialchars($tournament['tournament_name']);?></div><div class="tournament-body"><div class="bracket-meta"><?php echo $tournament['bracket_type']==='single_round'?'Single Round':'Multi-Round Elimination';?><?php if($tournament['champion_id']!==null):?> · Champion: <?php echo htmlspecialchars($squadronMap[$tournament['champion_id']]['name']??'Unknown');?><?php endif;?></div><?php foreach($tournament['matches'] as $match):$t1=$squadronMap[$match['squadron_id']]??null;$t2=$squadronMap[$match['opponent_id']]??null;$winnerId=$match['winner_id'];$t1IsWinner=$winnerId!==null&&$winnerId===$match['squadron_id'];$t2IsWinner=$winnerId!==null&&$winnerId===$match['opponent_id'];?><div class="tournament-match"><div class="match-team <?php echo $t1IsWinner?'match-winner':'';?>"><?php if($t1&&!empty($t1['icon_filename'])):?><img class="match-team-icon" src="<?php echo htmlspecialchars(iconUrl($t1['icon_filename']));?>" alt=""><?php endif;?><div class="match-team-name"><?php echo htmlspecialchars($t1['name']??'TBD');?><?php echo $t1IsWinner?'<span class="match-winner-check">✓</span>':'';?></div></div><div class="match-score-block"><span><?php echo htmlspecialchars((string)$match['team1_score']);?></span><span class="match-vs-label">vs</span><span><?php echo htmlspecialchars((string)$match['team2_score']);?></span><div class="match-points">+<?php echo htmlspecialchars((string)$match['value']);?> pts</div></div><div class="match-team team-right <?php echo $t2IsWinner?'match-winner':'';?>"><?php if($t2&&!empty($t2['icon_filename'])):?><img class="match-team-icon" src="<?php echo htmlspecialchars(iconUrl($t2['icon_filename']));?>" alt=""><?php endif;?><div class="match-team-name"><?php echo htmlspecialchars($t2['name']??'TBD');?><?php echo $t2IsWinner?'<span class="match-winner-check">✓</span>':'';?></div></div></div><?php endforeach;?></div></div><?php endforeach;?>
<?php foreach($samisByEvent as $group):?><div class="sami-card"><div class="sami-header">⚔️ <?php echo htmlspecialchars($group['event_name']);?></div><div class="sami-body"><?php foreach($group['results'] as $result):$s=$squadronMap[$result['squadron_id']]??null;?><div class="sami-result"><div style="display:flex;align-items:center;gap:10px"><?php if($s&&!empty($s['icon_filename'])):?><img class="sami-result-icon" src="<?php echo htmlspecialchars(iconUrl($s['icon_filename']));?>" alt=""><?php endif;?><strong><?php echo htmlspecialchars($s['name']??'Unknown');?></strong></div><strong><?php echo htmlspecialchars((string)$result['value']);?></strong></div><?php endforeach;?></div></div><?php endforeach;?>
<?php foreach($pftByEvent as $group):?><div class="event-card"><div class="event-header"><?php echo htmlspecialchars($group['event_name']);?></div><div class="event-body"><?php foreach($group['results'] as $result):$s=$squadronMap[$result['squadron_id']]??null;?><div style="display:flex;justify-content:space-between;padding:6px 0"><span><?php echo htmlspecialchars($s['name']??'Unknown');?></span><strong><?php echo htmlspecialchars((string)$result['value']);?></strong></div><?php endforeach;?></div></div><?php endforeach;?>
<?php foreach($otherByEvent as $group):?><div class="event-card"><div class="event-header"><?php echo htmlspecialchars($group['event_name']);?></div><div class="event-body"><?php foreach($group['results'] as $result):$s=$squadronMap[$result['squadron_id']]??null;?><div style="display:flex;justify-content:space-between;padding:6px 0"><span><?php echo htmlspecialchars($s['name']??'Unknown');?></span><strong><?php echo htmlspecialchars((string)$result['value']);?></strong></div><?php endforeach;?></div></div><?php endforeach;?>
<?php foreach($regularEvents as $event):$s=$squadronMap[$event['squadron_id']]??null;?><div class="event-card regular-event"><div class="event-header"><?php echo htmlspecialchars($event['event_name']??'Event');?></div><div class="event-body"><div class="regular-event-name"><?php echo htmlspecialchars($s['name']??'Unknown');?></div><div class="regular-event-type"><?php echo htmlspecialchars($event['event_type']??'other');?></div><div class="regular-event-score"><?php echo htmlspecialchars((string)($event['value']??$event['points_awarded']??0));?></div></div></div><?php endforeach;?>
</div><?php endif;?>
<div class="footer"><a href="admin-panel.php">Admin</a></div></div></body></html>
