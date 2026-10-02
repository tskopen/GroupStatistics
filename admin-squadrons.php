<?php
session_start();
require __DIR__ . '/config.php';

if (empty($_SESSION['admin'])) { header('Location: admin-login.php'); exit; }

$db=getDb(); $uploadsDir=IMAGES_DIR; $success=''; $error='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['squadron_id'])) {
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
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Manage Squadrons - Squadron Tracker</title>
<style>body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px;color:#222}.container{max-width:700px;margin:0 auto}h1{color:#002147;text-align:center}.squadron-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.15);padding:20px;margin-bottom:16px}.squadron-card h2{margin:0 0 6px;color:#002147;font-size:1.1em}.squadron-card p{margin:0 0 12px;color:#555}.squadron-card img{height:40px;vertical-align:middle;margin-right:10px}form.upload-form{display:flex;gap:10px;align-items:center;flex-wrap:wrap}input[type=file]{flex:1}button{padding:8px 14px;background:#002147;color:#fff;border:0;border-radius:4px;cursor:pointer}.success{color:#1a7a1a;text-align:center;font-weight:bold}.error{color:#b00020;text-align:center}.nav-link{display:block;text-align:center;margin-top:20px;font-size:.9em}.nav-link a{color:#002147}</style></head>
<body><div class="container"><h1>Manage Squadrons</h1>
<?php if($success): ?><p class="success"><?php echo htmlspecialchars($success); ?></p><?php endif; ?>
<?php if($error): ?><p class="error"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>
<?php foreach($squadrons as $squadron): ?><div class="squadron-card"><h2><?php if(!empty($squadron['icon_filename'])): ?><img src="<?php echo htmlspecialchars(iconUrl($squadron['icon_filename'])); ?>" alt="icon"><?php endif; ?>Squadron <?php echo htmlspecialchars((string)$squadron['id']); ?>: <?php echo htmlspecialchars($squadron['name']); ?></h2><p><?php echo htmlspecialchars($squadron['description']??''); ?></p>
<form class="upload-form" method="post" action="admin-squadrons.php" enctype="multipart/form-data"><input type="hidden" name="squadron_id" value="<?php echo htmlspecialchars((string)$squadron['id']); ?>"><input type="file" name="icon" accept="image/png,image/jpeg,image/gif,image/webp" required><button type="submit">Upload Icon</button></form></div><?php endforeach; ?>
<div class="nav-link"><a href="admin-panel.php">&larr; Back to Admin Panel</a></div></div></body></html>
