<?php
session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/theme-loader.php';
$theme = loadTheme();

if (empty($_SESSION['admin'])) { header('Location: admin-login.php'); exit; }

$db=getDb(); $uploadsDir=IMAGES_DIR; $success=''; $error='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['group_icon_upload'])) {
    [$valid,$extOrError]=validateUploadedImage($_FILES['group_icon']??[]);
    if (!$valid || !$extOrError) $error=$valid ? 'Please choose an image to upload.' : $extOrError;
    else {
        if (!is_dir($uploadsDir)) mkdir($uploadsDir,0755,true);
        $filename='group-icon-'.safeImageFilename($extOrError);
        $destination=$uploadsDir.'/'.$filename;
        if (move_uploaded_file($_FILES['group_icon']['tmp_name'],$destination)) {
            $settingsFile=DATA_DIR.'/group-icon.json';
            $previous=readJson($settingsFile);
            if (writeJson($settingsFile,['filename'=>$filename])) {
                if (!empty($previous['filename']) && $previous['filename']!==$filename) {
                    $oldPath=$uploadsDir.'/'.basename((string)$previous['filename']);
                    if (is_file($oldPath)) @unlink($oldPath);
                }
                $success='Group icon uploaded successfully.';
            } else {
                @unlink($destination);
                $error='Unable to save the group icon setting.';
            }
        } else $error='Failed to move uploaded file.';
    }
} elseif ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['squadron_id'])) {
    $squadronId=(int)$_POST['squadron_id'];
    [$valid,$extOrError]=validateUploadedImage($_FILES['icon']??[]);
    if (!$valid) $error=$extOrError;
    else {
        $filename='squadron-'.$squadronId.'-'.safeImageFilename($extOrError);
        if (!is_dir($uploadsDir)) mkdir($uploadsDir,0755,true);
        $destination=$uploadsDir.'/'.$filename;
        if (move_uploaded_file($_FILES['icon']['tmp_name'],$destination)) {
            try {
                $stmt=$db->prepare('UPDATE squadrons SET icon_filename=? WHERE id=?');
                $stmt->execute([$filename,$squadronId]);
                $success='Icon uploaded successfully.';
            } catch (Throwable $e) {
                @unlink($destination); error_log($e); $error='Unable to save the icon.';
            }
        } else $error='Failed to move uploaded file.';
    }
}
$squadrons=$db->query('SELECT * FROM squadrons ORDER BY id')->fetchAll();
$groupIconSettings=readJson(DATA_DIR.'/group-icon.json');
$groupIconFilename=$groupIconSettings['filename']??null;
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Manage Squadrons - Squadron Tracker</title>
<style>:root{--primary-color:<?php echo htmlspecialchars($theme['primary_color']??'#002147',ENT_QUOTES,'UTF-8'); ?>;--secondary-color:<?php echo htmlspecialchars($theme['secondary_color']??'#003366',ENT_QUOTES,'UTF-8'); ?>;--background-color:<?php echo htmlspecialchars($theme['background_color']??'#f4f4f4',ENT_QUOTES,'UTF-8'); ?>;--text-color:<?php echo htmlspecialchars($theme['text_color']??'#222',ENT_QUOTES,'UTF-8'); ?>;--surface-color:color-mix(in srgb,var(--background-color),white 92%)}body{font-family:Arial,sans-serif;background:var(--background-color);margin:0;padding:20px;color:var(--text-color)}.container{max-width:760px;margin:0 auto}h1{color:var(--primary-color);text-align:center}.squadron-card{background:var(--surface-color);border:1px solid color-mix(in srgb,var(--primary-color),var(--background-color) 80%);border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.12);padding:20px;margin-bottom:16px}.squadron-card h2{margin:0 0 6px;color:var(--primary-color);font-size:1.1em}.squadron-card p{margin:0 0 12px;color:var(--text-color)}.squadron-card img{height:40px;vertical-align:middle;margin-right:10px}form.upload-form{display:flex;gap:10px;align-items:center;flex-wrap:wrap}input[type=file]{flex:1;min-width:0}button{padding:8px 14px;background:var(--primary-color);color:#fff;border:0;border-radius:4px;cursor:pointer}button:hover{background:var(--secondary-color)}.success{color:#1a7a1a;text-align:center;font-weight:bold}.error{color:#b00020;text-align:center;font-weight:bold}.nav-link{display:block;text-align:center;margin-top:20px;font-size:.9em}.nav-link a{color:var(--primary-color)}.group-icon-preview{width:88px;height:88px;object-fit:contain;background:var(--background-color);border:1px solid color-mix(in srgb,var(--primary-color),var(--background-color) 70%);border-radius:12px;padding:6px;display:block;margin:10px auto}</style></head>
<body><div class="container"><h1>Manage Squadrons</h1>
<?php if($success): ?><p class="success"><?php echo htmlspecialchars($success); ?></p><?php endif; ?>
<?php if($error): ?><p class="error"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>
<div class="squadron-card">
<h2>Group Icon</h2>
<p>Upload the Group 1 logo displayed in the top-left spot of the homepage. This is separate from each squadron's individual icon.</p>
<?php if($groupIconFilename): ?><img class="group-icon-preview" src="<?php echo htmlspecialchars(iconUrl($groupIconFilename)); ?>" alt="Current Group 1 icon"><?php else: ?><p style="text-align:center">No group icon uploaded yet.</p><?php endif; ?>
<form class="upload-form" method="post" action="admin-squadrons.php" enctype="multipart/form-data"><input type="hidden" name="group_icon_upload" value="1"><input type="file" name="group_icon" accept="image/png,image/jpeg,image/gif,image/webp" required><button type="submit">Upload Group Icon</button></form>
</div>
<?php foreach($squadrons as $squadron): ?><div class="squadron-card"><h2><?php if(!empty($squadron['icon_filename'])): ?><img src="<?php echo htmlspecialchars(iconUrl($squadron['icon_filename'])); ?>" alt="icon"><?php endif; ?>Squadron <?php echo htmlspecialchars((string)$squadron['id']); ?>: <?php echo htmlspecialchars($squadron['name']); ?></h2><p><?php echo htmlspecialchars($squadron['description']??''); ?></p>
<form class="upload-form" method="post" action="admin-squadrons.php" enctype="multipart/form-data"><input type="hidden" name="squadron_id" value="<?php echo htmlspecialchars((string)$squadron['id']); ?>"><input type="file" name="icon" accept="image/png,image/jpeg,image/gif,image/webp" required><button type="submit">Upload Icon</button></form></div><?php endforeach; ?>
<div class="nav-link"><a href="admin-panel.php">&larr; Back to Admin Panel</a></div></div></body></html>
