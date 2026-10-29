<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['admin'])) { header('Location: admin-login.php'); exit; }
$db = getDb();
$errors = [];
$notice = isset($_GET['notice']) ? (string)$_GET['notice'] : '';
$photoDir = DATA_DIR . '/award-images';
function awardEscape($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function awardFind(PDO $db, int $id): ?array { $s=$db->prepare('SELECT * FROM engagement_awards WHERE id=?'); $s->execute([$id]); $r=$s->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
function awardStorePhoto(array $file, string $dir): ?string {
    [$ok,$ext]=validateUploadedImage($file);
    if (!$ok) throw new RuntimeException((string)$ext);
    if ($ext===null) return null;
    if (!in_array($ext,['jpg','png','webp'],true)) throw new RuntimeException('Use JPEG, PNG, or WebP photos.');
    if (!function_exists('imagecreatefromstring')) throw new RuntimeException('Server image processing is unavailable.');
    $raw=file_get_contents((string)$file['tmp_name']); $img=$raw===false?false:@imagecreatefromstring($raw);
    if (!$img) throw new RuntimeException('Could not decode the image.');
    if (!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) { imagedestroy($img); throw new RuntimeException('Could not create photo storage.'); }
    $name='award-'.bin2hex(random_bytes(16)).'.'.$ext; $path=$dir.'/'.$name;
    $saved=$ext==='jpg'?imagejpeg($img,$path,88):($ext==='png'?imagepng($img,$path,6):(function_exists('imagewebp')?imagewebp($img,$path,88):false));
    imagedestroy($img);
    if (!$saved) { if(is_file($path)) @unlink($path); throw new RuntimeException('Could not safely re-encode this photo.'); }
    @chmod($path,0640); return $name;
}
$form=['id'=>null,'title'=>'','description'=>'','award_type'=>'description','points_awarded'=>'','teams_url'=>'','status'=>'published'];
$selected=[];
$photos=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $id=(int)($_POST['award_id']??0); $action=(string)($_POST['action']??'');
    try {
        if ($action==='delete') {
            if (!$id || !($old=awardFind($db,$id))) throw new RuntimeException('Award not found.');
            $s=$db->prepare('SELECT filename FROM engagement_award_photos WHERE award_id=?'); $s->execute([$id]); $names=$s->fetchAll(PDO::FETCH_COLUMN);
            $s=$db->prepare('DELETE FROM engagement_awards WHERE id=?'); $s->execute([$id]);
            foreach($names as $n){$p=$photoDir.'/'.basename((string)$n);if(is_file($p))@unlink($p);}
            header('Location: admin-awards.php?notice=deleted'); exit;
        }
        if ($action!=='save') throw new RuntimeException('Unknown action.');
        $old=$id?awardFind($db,$id):null; if($id&&!$old) throw new RuntimeException('Award not found.');
        $title=trim((string)($_POST['title']??'')); $description=trim((string)($_POST['description']??''));
        $type=(string)($_POST['award_type']??'description'); $status=(string)($_POST['save_status']??'draft');
        $url=trim((string)($_POST['teams_url']??'')); $points=null;
        if(in_array($type,['points','both'],true)){ $raw=trim((string)($_POST['points_awarded']??'')); if($raw===''||!is_numeric($raw)||!is_finite((float)$raw)||abs((float)$raw)>1000000)throw new RuntimeException('Enter a valid points value.');$points=(float)$raw; }
        if($title===''||strlen($title)>160)throw new RuntimeException('Title is required (160 characters maximum).');
        if($description===''||strlen($description)>5000)throw new RuntimeException('Description is required (5,000 characters maximum).');
        if(!in_array($type,['points','description','both','completion'],true)||!in_array($status,['draft','published'],true))throw new RuntimeException('Choose a valid award type and status.');
        if($url!==''&&(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https'))throw new RuntimeException('Teams link must be a valid HTTPS URL.');
        $selected=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['squadron_ids']??[])))));
        $validIds=array_map('intval',$db->query('SELECT id FROM squadrons')->fetchAll(PDO::FETCH_COLUMN));
        foreach($selected as $sid)if(!in_array($sid,$validIds,true))throw new RuntimeException('Invalid squadron selected.');
        if($status==='published'&&!$selected)throw new RuntimeException('Select at least one squadron before publishing.');
        $newPhotos=[]; $uploadPaths=[];
        if(isset($_FILES['photos'])&&is_array($_FILES['photos']['name']??null)){
            if(count($_FILES['photos']['name'])>8)throw new RuntimeException('Upload no more than 8 photos at once.');
            foreach($_FILES['photos']['name'] as $i=>$unused){
                if(($_FILES['photos']['error'][$i]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;
                $file=[];foreach(['name','type','tmp_name','error','size'] as $key)$file[$key]=$_FILES['photos'][$key][$i]??null;
                $name=awardStorePhoto($file,$photoDir);if($name){$newPhotos[]=$name;$uploadPaths[]=$photoDir.'/'.$name;}
            }
        }
        $removePhotoIds=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['remove_photo_ids']??[])))));
        $filesToDelete=[];
        $now=date('c'); $db->beginTransaction();
        if($old){$s=$db->prepare('UPDATE engagement_awards SET title=?,description=?,award_type=?,points_awarded=?,teams_url=?,status=?,updated_at=? WHERE id=?');$s->execute([$title,$description,$type,$points,$url?:null,$status,$now,$id]);$awardId=$id;}
        else{$s=$db->prepare('INSERT INTO engagement_awards(title,description,award_type,points_awarded,teams_url,status,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');$s->execute([$title,$description,$type,$points,$url?:null,$status,(string)($_SESSION['username']??'admin'),$now,$now]);$awardId=(int)$db->lastInsertId();}
        $s=$db->prepare('DELETE FROM engagement_award_recipients WHERE award_id=?');$s->execute([$awardId]);
        $s=$db->prepare('INSERT INTO engagement_award_recipients(award_id,squadron_id,points_awarded,created_at) VALUES(?,?,?,?)');foreach($selected as $sid)$s->execute([$awardId,$sid,$points,$now]);
        if($removePhotoIds){
            $marks=implode(',',array_fill(0,count($removePhotoIds),'?'));
            $params=array_merge([$awardId],$removePhotoIds);
            $s=$db->prepare("SELECT filename FROM engagement_award_photos WHERE award_id=? AND id IN ($marks)");
            $s->execute($params);
            foreach($s->fetchAll(PDO::FETCH_COLUMN) as $filename)$filesToDelete[]=$photoDir.'/'.basename((string)$filename);
            $s=$db->prepare("DELETE FROM engagement_award_photos WHERE award_id=? AND id IN ($marks)");
            $s->execute($params);
        }
        $s=$db->prepare('INSERT INTO engagement_award_photos(award_id,filename,alt_text,uploaded_by,created_at) VALUES(?,?,?,?,?)');foreach($newPhotos as $name)$s->execute([$awardId,$name,trim((string)($_POST['photo_alt_text']??'')),(string)($_SESSION['username']??'admin'),$now]);
        $db->commit();
        foreach($filesToDelete as $path)if(is_file($path))@unlink($path);
        header('Location: admin-awards.php?notice='.($old?'updated':($status==='published'?'published':'draft')));exit;
    } catch(Throwable $e) {
        if($db->inTransaction())$db->rollBack();
        foreach(($uploadPaths??[]) as $p)if(is_file($p))@unlink($p);
        $errors[]=$e->getMessage();
        $form=['id'=>$id?:null,'title'=>trim((string)($_POST['title']??'')),'description'=>trim((string)($_POST['description']??'')),'award_type'=>(string)($_POST['award_type']??'description'),'points_awarded'=>(string)($_POST['points_awarded']??''),'teams_url'=>trim((string)($_POST['teams_url']??'')),'status'=>(string)($_POST['save_status']??'draft')];
        $selected=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['squadron_ids']??[])))));
    }
}
$editId=(int)($_GET['edit']??0);$editing=$editId?awardFind($db,$editId):null;
if($editing&&!$form['id']){$form=['id'=>$editing['id'],'title'=>$editing['title'],'description'=>$editing['description'],'award_type'=>$editing['award_type'],'points_awarded'=>$editing['points_awarded']??'','teams_url'=>$editing['teams_url']??'','status'=>$editing['status']];$s=$db->prepare('SELECT squadron_id FROM engagement_award_recipients WHERE award_id=?');$s->execute([$editId]);$selected=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));$s=$db->prepare('SELECT * FROM engagement_award_photos WHERE award_id=? ORDER BY id');$s->execute([$editId]);$photos=$s->fetchAll(PDO::FETCH_ASSOC);}
$squadrons=$db->query('SELECT id,name FROM squadrons ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$awards=$db->query("SELECT a.*,(SELECT COUNT(*) FROM engagement_award_recipients r WHERE r.award_id=a.id) recipient_count,(SELECT COUNT(*) FROM engagement_award_photos p WHERE p.award_id=a.id) photo_count FROM engagement_awards a ORDER BY CASE a.status WHEN 'draft' THEN 0 ELSE 1 END,a.updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Challenge Awards | GroupStatistics</title>
<style>*{box-sizing:border-box}body{margin:0;background:#f3f6fa;color:#1e293b;font:16px/1.5 Arial,sans-serif}.top{background:#002147;color:white;padding:20px}.topinner,main{max-width:1250px;margin:auto}.topinner{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.top h1{margin:0;font-size:1.5rem}.top p{margin:4px 0;color:#d7e4f2}.top a{color:white;border:1px solid #ffffff60;border-radius:8px;padding:8px 10px;text-decoration:none;margin-right:5px}main{padding:22px 14px}.layout{display:grid;grid-template-columns:minmax(300px,.9fr) minmax(0,1.1fr);gap:18px}.panel{background:white;border:1px solid #dbe3ed;border-radius:12px;padding:18px;min-width:0}.panel h2{margin-top:0;color:#002147}label{display:block;font-weight:700;margin:12px 0 5px}input[type=text],input[type=url],input[type=number],input[type=file],textarea,select{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:7px;font:inherit}textarea{min-height:110px}.hint{color:#64748b;font-size:.85rem;font-weight:400}.sqs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.sqs label{border:1px solid #dbe3ed;border-radius:7px;padding:8px;font-weight:400;margin:0}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:15px}.btn{display:inline-block;padding:9px 12px;border:0;border-radius:7px;background:#002147;color:white;text-decoration:none;font-weight:700;cursor:pointer}.secondary{background:#e8eef6;color:#002147}.danger{background:#fff0f0;color:#a21d2a}.row{border-top:1px solid #dbe3ed;padding:14px 0}.row:first-of-type{border-top:0}.meta{color:#64748b;font-size:.86rem}.status{font-size:.75rem;font-weight:bold;text-transform:uppercase;border-radius:99px;padding:4px 8px;background:#fff3d7;color:#875600}.status.published{background:#e8f7ee;color:#17633b}.alert{padding:12px;border-radius:8px;margin-bottom:14px;background:#eaf8ef;color:#155b35}.error{background:#fff0f0;color:#8d1825}.photo-list{display:flex;gap:8px;flex-wrap:wrap}.photo-list img{width:85px;height:70px;object-fit:cover;border-radius:6px}@media(max-width:900px){.layout{grid-template-columns:1fr}}@media(max-width:520px){.sqs{grid-template-columns:1fr}.panel{padding:13px}}</style><link rel="stylesheet" href="site-ui.css">
<link rel="stylesheet" href="site-components.css">
</head><body>
<?php require_once __DIR__ . '/site-header.php'; renderGroupSiteHeader(); ?>
<header class="top"><div class="topinner"><div><h1>🏅 Challenge Awards</h1><p>One award can recognize multiple squadrons, with optional points and photos.</p></div><nav><a href="awards.php">Public awards ↗</a><a href="admin-panel.php">Admin panel</a><a href="index.php">Home</a></nav></div></header><main>
<?php if($notice):?><div class="alert"><?php echo awardEscape(['published'=>'Award published.','draft'=>'Draft saved.','updated'=>'Award updated.','deleted'=>'Award deleted.'][$notice]??$notice);?></div><?php endif;?>
<?php if($errors):?><div class="alert error"><?php foreach($errors as $e):?><div><?php echo awardEscape($e);?></div><?php endforeach;?></div><?php endif;?>
<div class="layout"><section class="panel" id="editor"><h2><?php echo $form['id']?'Edit award':'Create award';?></h2><p class="hint">Every selected squadron receives the full configured points value. Awards never alter official competition standings.</p>
<form method="post" enctype="multipart/form-data" action="admin-awards.php<?php echo $form['id']?'?edit='.(int)$form['id'].'#editor':'';?>"><input type="hidden" name="action" value="save"><?php if($form['id']):?><input type="hidden" name="award_id" value="<?php echo (int)$form['id'];?>"><?php endif;?>
<label for="title">Award title</label><input id="title" type="text" name="title" maxlength="160" required value="<?php echo awardEscape($form['title']);?>">
<label for="description">Description</label><textarea id="description" name="description" maxlength="5000" required><?php echo awardEscape($form['description']);?></textarea>
<label for="award_type">Award type</label><select id="award_type" name="award_type"><option value="description" <?php echo $form['award_type']==='description'?'selected':'';?>>Description only</option><option value="points" <?php echo $form['award_type']==='points'?'selected':'';?>>Points only</option><option value="both" <?php echo $form['award_type']==='both'?'selected':'';?>>Points and description</option><option value="completion" <?php echo $form['award_type']==='completion'?'selected':'';?>>Completion, no points</option></select>
<div id="points-wrap"><label for="points">Points per squadron</label><input id="points" type="number" step="any" name="points_awarded" value="<?php echo awardEscape($form['points_awarded']);?>"></div>
<label>Recipient squadrons <span class="hint">— select multiple</span></label><div class="sqs"><?php foreach($squadrons as $sq):?><label><input type="checkbox" name="squadron_ids[]" value="<?php echo (int)$sq['id'];?>" <?php echo in_array((int)$sq['id'],$selected,true)?'checked':'';?>> <?php echo awardEscape($sq['name']);?></label><?php endforeach;?></div>
<label for="teams_url">Teams evidence channel/post (optional)</label><input id="teams_url" type="url" name="teams_url" placeholder="https://teams.microsoft.com/..." value="<?php echo awardEscape($form['teams_url']);?>">
<label for="photos">Photos (optional; JPEG, PNG, WebP; max 5 MB each)</label><input id="photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple><p class="hint">Up to 8 photos per save. Images are re-encoded to remove embedded metadata. Draft photos stay private.</p>
<?php if($photos):?><div class="photo-list"><?php foreach($photos as $p):?><div><img src="award-image.php?id=<?php echo (int)$p['id'];?>" alt="<?php echo awardEscape($p['alt_text']);?>"><label class="hint"><input type="checkbox" name="remove_photo_ids[]" value="<?php echo (int)$p['id'];?>"> Remove photo</label></div><?php endforeach;?></div><?php endif;?>
<label for="photo_alt_text">Photo alt text (optional)</label><input id="photo_alt_text" type="text" name="photo_alt_text" maxlength="240">
<div class="actions"><button class="btn" type="submit" name="save_status" value="published">Save and publish</button><button class="btn secondary" type="submit" name="save_status" value="draft">Save draft</button><?php if($form['id']):?><a class="btn secondary" href="admin-awards.php">Cancel</a><?php endif;?></div></form></section>
<section class="panel"><h2>Existing awards</h2><?php if(!$awards):?><p class="hint">No awards have been created yet.</p><?php endif;?><?php foreach($awards as $a):?><article class="row"><strong><?php echo awardEscape($a['title']);?></strong> <span class="status <?php echo awardEscape($a['status']);?>"><?php echo awardEscape($a['status']);?></span><div class="meta"><?php echo (int)$a['recipient_count'];?> squadron(s) · <?php echo (int)$a['photo_count'];?> photo(s) · <?php echo in_array($a['award_type'],['points','both'],true)?awardEscape($a['points_awarded']).' points per squadron':'No points';?></div><p><?php echo nl2br(awardEscape($a['description']));?></p><div class="actions"><a class="btn secondary" href="admin-awards.php?edit=<?php echo (int)$a['id'];?>#editor">Edit</a><a class="btn secondary" href="awards.php#award-<?php echo (int)$a['id'];?>">View</a><form method="post" onsubmit="return confirm('Delete this award and its photos?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="award_id" value="<?php echo (int)$a['id'];?>"><button class="btn danger" type="submit">Delete</button></form></div></article><?php endforeach;?></section></div></main>
<script>const t=document.getElementById('award_type'),w=document.getElementById('points-wrap'),p=document.getElementById('points');function syncPoints(){const need=t.value==='points'||t.value==='both';w.hidden=!need;p.required=need;}t.addEventListener('change',syncPoints);syncPoints();</script></body></html>
