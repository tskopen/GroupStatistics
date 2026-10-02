<?php
function normalizeEventType($eventType) { $eventType=strtolower(trim((string)$eventType)); return $eventType!==''?$eventType:'other'; }

function getSquadronRankings() {
    $db=getDb(); $squadrons=$db->query('SELECT id,name FROM squadrons ORDER BY id')->fetchAll(); $totals=[];
    foreach($squadrons as $s)$totals[$s['id']]=0.0;
    foreach($db->query('SELECT squadron_id,COALESCE(SUM(COALESCE(value,points_awarded,0)),0) total FROM events GROUP BY squadron_id')->fetchAll() as $row) if($row['squadron_id']!==null&&array_key_exists($row['squadron_id'],$totals))$totals[$row['squadron_id']]+=(float)$row['total'];
    foreach($db->query('SELECT squadron_id,COALESCE(SUM(points_awarded),0) total FROM intramural_wl_records GROUP BY squadron_id')->fetchAll() as $row) if($row['squadron_id']!==null&&array_key_exists($row['squadron_id'],$totals))$totals[$row['squadron_id']]+=(float)$row['total'];
    $ranked=[]; foreach($squadrons as $s)$ranked[]=['squadron_id'=>$s['id'],'name'=>$s['name'],'total'=>$totals[$s['id']]??0.0];
    usort($ranked,fn($a,$b)=>$b['total']<=>$a['total']); foreach($ranked as $i=>&$row)$row['rank']=$i+1; unset($row); return $ranked;
}

function recordLeaderboardSnapshot() {
    $db=getDb(); $ranked=getSquadronRankings(); $date=date('c'); $stmt=$db->prepare('INSERT INTO leaderboard_snapshots(snapshot_date,squadron_id,rank,total_points) VALUES(?,?,?,?)');
    foreach($ranked as $row)$stmt->execute([$date,$row['squadron_id'],$row['rank'],$row['total']]); return ['snapshot_date'=>$date,'rankings'=>$ranked];
}

function getLeaderboardMovement($squadronId) {
    $current=null; foreach(getSquadronRankings() as $row)if((int)$row['squadron_id']===(int)$squadronId){$current=(int)$row['rank'];break;}
    $stmt=getDb()->prepare('SELECT rank FROM leaderboard_snapshots WHERE squadron_id=? ORDER BY snapshot_date DESC LIMIT 1'); $stmt->execute([$squadronId]); $previous=$stmt->fetchColumn();
    if($previous===false)return ['current_rank'=>$current,'previous_rank'=>null,'movement'=>'new']; $diff=(int)$previous-(int)$current;
    return ['current_rank'=>$current,'previous_rank'=>(int)$previous,'movement'=>$diff>0?'up '.$diff:($diff<0?'down '.abs($diff):'same')];
}
