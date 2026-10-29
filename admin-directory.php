<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/theme-loader.php';
$uiThemeForHeader = loadTheme();
if (empty($_SESSION['admin'])) { header('Location: admin-login.php'); exit; }
$db = getDb();
$errors = [];
$notice = '';
$now = static fn(): string => date('Y-m-d H:i:s');
function directoryAdminEscape($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function directoryTextLength(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_section') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $sort = filter_var($_POST['sort_order'] ?? 0, FILTER_VALIDATE_INT);
            $visible = isset($_POST['is_visible']) ? 1 : 0;
            if ($name === '' || directoryTextLength($name) > 100) $errors[] = 'Section name is required and must be 100 characters or fewer.';
            if (directoryTextLength($description) > 1000) $errors[] = 'Section description must be 1,000 characters or fewer.';
            if ($sort === false) $errors[] = 'Section order must be a whole number.';
            if (!$errors) {
                if ($id) {
                    $stmt = $db->prepare('UPDATE directory_sections SET name=?, description=?, sort_order=?, is_visible=?, updated_at=? WHERE id=?');
                    $stmt->execute([$name, $description, $sort, $visible, $now(), $id]);
                } else {
                    $stmt = $db->prepare('INSERT INTO directory_sections(name, description, sort_order, is_visible, created_at, updated_at) VALUES(?,?,?,?,?,?)');
                    $stmt->execute([$name, $description, $sort, $visible, $now(), $now()]);
                }
                header('Location: admin-directory.php?notice=section_saved'); exit;
            }
        } elseif ($action === 'save_person') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $name = trim((string)($_POST['display_name'] ?? ''));
            $bio = trim((string)($_POST['bio'] ?? ''));
            $contact = trim((string)($_POST['contact'] ?? ''));
            if ($name === '' || directoryTextLength($name) > 120) $errors[] = 'Display name is required and must be 120 characters or fewer.';
            if (directoryTextLength($bio) > 1000) $errors[] = 'Profile summary must be 1,000 characters or fewer.';
            if (directoryTextLength($contact) > 240) $errors[] = 'Contact information must be 240 characters or fewer.';
            $photoFilename = null;
            if ($id) {
                $stmt = $db->prepare('SELECT photo_filename FROM directory_people WHERE id=?');
                $stmt->execute([$id]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$existing) $errors[] = 'That profile no longer exists.';
                else $photoFilename = $existing['photo_filename'];
            }
            $removePhoto = isset($_POST['remove_photo']);
            $file = $_FILES['photo'] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                [$valid, $extOrError] = validateUploadedImage($file);
                if (!$valid) $errors[] = (string)$extOrError;
                elseif (!$errors) {
                    $filename = 'directory-' . bin2hex(random_bytes(12)) . '.' . $extOrError;
                    if (!is_dir(IMAGES_DIR) && !mkdir(IMAGES_DIR, 0755, true) && !is_dir(IMAGES_DIR)) $errors[] = 'Unable to prepare image storage.';
                    elseif (!move_uploaded_file($file['tmp_name'], IMAGES_DIR . '/' . $filename)) $errors[] = 'Unable to store the uploaded photo.';
                    else {
                        if ($photoFilename && is_file(IMAGES_DIR . '/' . basename($photoFilename))) @unlink(IMAGES_DIR . '/' . basename($photoFilename));
                        $photoFilename = $filename;
                    }
                }
            } elseif ($removePhoto) {
                if ($photoFilename && is_file(IMAGES_DIR . '/' . basename($photoFilename))) @unlink(IMAGES_DIR . '/' . basename($photoFilename));
                $photoFilename = null;
            }
            if (!$errors) {
                if ($id) {
                    $stmt = $db->prepare('UPDATE directory_people SET display_name=?, bio=?, contact=?, photo_filename=?, updated_at=? WHERE id=?');
                    $stmt->execute([$name, $bio, $contact, $photoFilename, $now(), $id]);
                } else {
                    $stmt = $db->prepare('INSERT INTO directory_people(display_name, bio, contact, photo_filename, created_at, updated_at) VALUES(?,?,?,?,?,?)');
                    $stmt->execute([$name, $bio, $contact, $photoFilename, $now(), $now()]);
                }
                header('Location: admin-directory.php?notice=profile_saved'); exit;
            }
        } elseif ($action === 'save_role') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $sectionId = filter_var($_POST['section_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $personId = filter_var($_POST['person_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $title = trim((string)($_POST['title'] ?? ''));
            $responsibilities = trim((string)($_POST['responsibilities'] ?? ''));
            $sort = filter_var($_POST['sort_order'] ?? 0, FILTER_VALIDATE_INT);
            $status = (string)($_POST['status'] ?? 'draft');
            if (!$sectionId || !$db->query('SELECT id FROM directory_sections WHERE id=' . (int)$sectionId)->fetchColumn()) $errors[] = 'Choose a valid section.';
            if (!$personId || !$db->query('SELECT id FROM directory_people WHERE id=' . (int)$personId)->fetchColumn()) $errors[] = 'Choose a valid profile.';
            if ($title === '' || directoryTextLength($title) > 140) $errors[] = 'Role title is required and must be 140 characters or fewer.';
            if (directoryTextLength($responsibilities) > 2000) $errors[] = 'Responsibilities must be 2,000 characters or fewer.';
            if ($sort === false) $errors[] = 'Role order must be a whole number.';
            if (!in_array($status, ['draft', 'published'], true)) $errors[] = 'Choose draft or published status.';
            if (!$errors) {
                if ($id) {
                    $stmt = $db->prepare('UPDATE directory_roles SET section_id=?, person_id=?, title=?, responsibilities=?, sort_order=?, status=?, updated_at=? WHERE id=?');
                    $stmt->execute([$sectionId, $personId, $title, $responsibilities, $sort, $status, $now(), $id]);
                } else {
                    $stmt = $db->prepare('INSERT INTO directory_roles(section_id, person_id, title, responsibilities, sort_order, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?)');
                    $stmt->execute([$sectionId, $personId, $title, $responsibilities, $sort, $status, $now(), $now()]);
                }
                header('Location: admin-directory.php?notice=role_saved'); exit;
            }
        } elseif ($action === 'delete_role') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) $errors[] = 'Choose a valid role to remove.';
            else {
                $stmt = $db->prepare('DELETE FROM directory_roles WHERE id=?');
                $stmt->execute([$id]);
                header('Location: admin-directory.php?notice=role_deleted'); exit;
            }
        } elseif ($action === 'delete_person') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) $errors[] = 'Choose a valid profile to remove.';
            else {
                $stmt = $db->prepare('SELECT photo_filename FROM directory_people WHERE id=?');
                $stmt->execute([$id]); $person = $stmt->fetch(PDO::FETCH_ASSOC);
                $db->prepare('DELETE FROM directory_people WHERE id=?')->execute([$id]);
                if (!empty($person['photo_filename']) && is_file(IMAGES_DIR . '/' . basename($person['photo_filename']))) @unlink(IMAGES_DIR . '/' . basename($person['photo_filename']));
                header('Location: admin-directory.php?notice=profile_deleted'); exit;
            }
        } elseif ($action === 'set_role_status') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $status = (string)($_POST['status'] ?? '');
            if (!$id || !in_array($status, ['draft', 'published'], true)) $errors[] = 'Invalid role status change.';
            else {
                $db->prepare('UPDATE directory_roles SET status=?, updated_at=? WHERE id=?')->execute([$status, $now(), $id]);
                header('Location: admin-directory.php?notice=status_saved'); exit;
            }
        } else {
            $errors[] = 'Unknown action.';
        }
    } catch (PDOException $e) {
        error_log($e);
        $errors[] = str_contains(strtolower($e->getMessage()), 'unique') ? 'A section with that name already exists.' : 'The change could not be saved. Check the information and try again.';
    } catch (Throwable $e) {
        error_log($e);
        $errors[] = 'The change could not be completed. Please try again.';
    }
}

$noticeMessages = [
    'section_saved' => 'Section saved.', 'profile_saved' => 'Profile saved.',
    'role_saved' => 'Role saved.', 'role_deleted' => 'Role removed.',
    'profile_deleted' => 'Profile and its role assignments removed.', 'status_saved' => 'Role publication status updated.',
];
$noticeKey = (string)($_GET['notice'] ?? '');
$notice = $noticeMessages[$noticeKey] ?? '';
$sections = $db->query('SELECT * FROM directory_sections ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC);
$people = $db->query('SELECT * FROM directory_people ORDER BY display_name')->fetchAll(PDO::FETCH_ASSOC);
$roles = $db->query('SELECT r.*, s.name AS section_name, p.display_name FROM directory_roles r JOIN directory_sections s ON s.id=r.section_id JOIN directory_people p ON p.id=r.person_id ORDER BY s.sort_order, s.name, r.sort_order, r.title')->fetchAll(PDO::FETCH_ASSOC);
$editPerson = null; $editRole = null; $editSection = null;
if (isset($_GET['edit_person'])) { $stmt=$db->prepare('SELECT * FROM directory_people WHERE id=?'); $stmt->execute([(int)$_GET['edit_person']]); $editPerson=$stmt->fetch(PDO::FETCH_ASSOC) ?: null; }
if (isset($_GET['edit_role'])) { $stmt=$db->prepare('SELECT * FROM directory_roles WHERE id=?'); $stmt->execute([(int)$_GET['edit_role']]); $editRole=$stmt->fetch(PDO::FETCH_ASSOC) ?: null; }
if (isset($_GET['edit_section'])) { $stmt=$db->prepare('SELECT * FROM directory_sections WHERE id=?'); $stmt->execute([(int)$_GET['edit_section']]); $editSection=$stmt->fetch(PDO::FETCH_ASSOC) ?: null; }
$personForm = $editPerson ?: ['id'=>null,'display_name'=>'','bio'=>'','contact'=>'','photo_filename'=>null];
$roleForm = $editRole ?: ['id'=>null,'section_id'=>$sections[0]['id'] ?? '', 'person_id'=>$people[0]['id'] ?? '', 'title'=>'','responsibilities'=>'','sort_order'=>0,'status'=>'draft'];
$sectionForm = $editSection ?: ['id'=>null,'name'=>'','description'=>'','sort_order'=>count($sections)*10,'is_visible'=>1];
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Manage Meet the Group</title>
<style>
*{box-sizing:border-box}body{font-family:Arial,sans-serif;background:#f4f6f9;margin:0;padding:22px;color:#222}.container{max-width:1180px;margin:0 auto}h1,h2{color:#002147}h1{margin:0 0 8px}.intro{color:#566273;margin:0 0 24px;line-height:1.5}.nav{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:22px}.nav a{color:#002147;font-weight:bold}.panel{background:#fff;border-radius:9px;box-shadow:0 2px 8px rgba(0,0,0,.08);padding:22px;margin:18px 0}.panel h2{margin:0 0 8px}.hint{color:#64748b;font-size:.88rem}.notice,.error{padding:12px 14px;border-radius:6px;margin:12px 0}.notice{background:#e8f6ed;color:#176534}.error{background:#fff0f0;color:#9d1b1b}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px}.field{display:flex;flex-direction:column;gap:6px;margin:12px 0}.field input,.field textarea,.field select{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:5px;font:inherit}.field textarea{min-height:90px;resize:vertical}.check{display:flex;align-items:center;gap:8px;margin:12px 0}.btn{display:inline-block;border:0;border-radius:5px;padding:9px 13px;background:#002147;color:#fff;text-decoration:none;font-weight:bold;cursor:pointer;font:inherit}.btn.secondary{background:#e8eef5;color:#002147}.btn.danger{background:#a51d2d}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.table-wrap{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:680px}th,td{text-align:left;padding:11px;border-bottom:1px solid #e4e8ee;vertical-align:top}th{background:#003366;color:#fff}td form{display:inline-block;margin:3px 4px 3px 0}.status{font-size:.82rem;font-weight:bold;text-transform:uppercase}.status-published{color:#18733a}.status-draft{color:#9a5a00}.small{font-size:.9rem;color:#64748b}.section-row{padding:13px 0;border-bottom:1px solid #e4e8ee}.section-row:last-child{border-bottom:0}
</style>
<link rel="stylesheet" href="site-ui.css">
<link rel="stylesheet" href="site-components.css">
</head><body>
<?php require_once __DIR__ . '/site-header.php'; renderGroupSiteHeader(); ?>
<header class="page-banner"><div class="page-banner-inner"><h1>Manage Meet the Group</h1><p>Edit profiles, assign one person to multiple roles, move roles between sections, and publish or unpublish directory entries.</p></div></header><main class="container">


<?php if ($notice): ?><div class="notice"><?php echo directoryAdminEscape($notice); ?></div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="error"><?php echo directoryAdminEscape($error); ?></div><?php endforeach; ?>

<section class="panel" id="profiles"><h2><?php echo $personForm['id'] ? 'Edit profile' : 'Add a profile'; ?></h2><p class="hint">A profile can hold multiple roles in different sections. Only use contact details and photos approved for publication.</p>
<form method="post" action="admin-directory.php" enctype="multipart/form-data"><input type="hidden" name="action" value="save_person"><?php if ($personForm['id']): ?><input type="hidden" name="id" value="<?php echo (int)$personForm['id']; ?>"><?php endif; ?>
<div class="grid"><label class="field">Display name *<input name="display_name" maxlength="120" required value="<?php echo directoryAdminEscape($personForm['display_name']); ?>"></label><label class="field">Approved contact information<input name="contact" maxlength="240" value="<?php echo directoryAdminEscape($personForm['contact']); ?>" placeholder="Official email, office, or approved contact route"></label></div>
<label class="field">Short profile / bio<textarea name="bio" maxlength="1000"><?php echo directoryAdminEscape($personForm['bio']); ?></textarea></label>
<label class="field">Profile photo (PNG, JPEG, GIF, or WebP; max 5 MB)<input type="file" name="photo" accept="image/png,image/jpeg,image/gif,image/webp"></label>
<?php if (!empty($personForm['photo_filename'])): ?><label class="check"><input type="checkbox" name="remove_photo" value="1"> Remove current photo</label><p class="small">Current photo filename: <?php echo directoryAdminEscape($personForm['photo_filename']); ?></p><?php endif; ?>
<div class="actions"><button class="btn" type="submit">Save profile</button><?php if ($personForm['id']): ?><a class="btn secondary" href="admin-directory.php#profiles">Cancel</a><?php endif; ?></div></form>
<div class="table-wrap"><table><thead><tr><th>Profile</th><th>Contact</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($people as $person): ?><tr><td><strong><?php echo directoryAdminEscape($person['display_name']); ?></strong><br><span class="small"><?php echo directoryAdminEscape($person['bio']); ?></span></td><td><?php echo directoryAdminEscape($person['contact']); ?></td><td><a class="btn secondary" href="admin-directory.php?edit_person=<?php echo (int)$person['id']; ?>#profiles">Edit</a> <form method="post" action="admin-directory.php" onsubmit="return confirm('Delete this profile and all roles assigned to it?');"><input type="hidden" name="action" value="delete_person"><input type="hidden" name="id" value="<?php echo (int)$person['id']; ?>"><button class="btn danger" type="submit">Delete profile</button></form></td></tr><?php endforeach; ?>
<?php if (!$people): ?><tr><td colspan="3">No profiles yet. Add a profile above, then assign a role.</td></tr><?php endif; ?></tbody></table></div></section>

<section class="panel" id="roles"><h2><?php echo $roleForm['id'] ? 'Edit role assignment' : 'Add a role assignment'; ?></h2>
<?php if (!$people || !$sections): ?><p class="hint">Create at least one profile and one section before adding a role.</p><?php endif; ?>
<form method="post" action="admin-directory.php"><input type="hidden" name="action" value="save_role"><?php if ($roleForm['id']): ?><input type="hidden" name="id" value="<?php echo (int)$roleForm['id']; ?>"><?php endif; ?>
<div class="grid"><label class="field">Person / profile *<select name="person_id" required><?php foreach ($people as $person): ?><option value="<?php echo (int)$person['id']; ?>" <?php echo (int)$roleForm['person_id']===(int)$person['id']?'selected':''; ?>><?php echo directoryAdminEscape($person['display_name']); ?></option><?php endforeach; ?></select></label>
<label class="field">Section *<select name="section_id" required><?php foreach ($sections as $section): ?><option value="<?php echo (int)$section['id']; ?>" <?php echo (int)$roleForm['section_id']===(int)$section['id']?'selected':''; ?>><?php echo directoryAdminEscape($section['name']); ?><?php echo empty($section['is_visible'])?' (hidden)':''; ?></option><?php endforeach; ?></select></label>
<label class="field">Role / title *<input name="title" maxlength="140" required value="<?php echo directoryAdminEscape($roleForm['title']); ?>" placeholder="e.g., Group Commander"></label>
<label class="field">Display order (lower appears first)<input type="number" name="sort_order" step="1" value="<?php echo (int)$roleForm['sort_order']; ?>"></label></div>
<label class="field">Responsibilities / description<textarea name="responsibilities" maxlength="2000"><?php echo directoryAdminEscape($roleForm['responsibilities']); ?></textarea></label>
<label class="field">Publication status<select name="status"><option value="draft" <?php echo $roleForm['status']==='draft'?'selected':''; ?>>Draft — admins only</option><option value="published" <?php echo $roleForm['status']==='published'?'selected':''; ?>>Published — visible publicly</option></select></label>
<div class="actions"><button class="btn" type="submit" <?php echo (!$people || !$sections)?'disabled':''; ?>>Save role</button><?php if ($roleForm['id']): ?><a class="btn secondary" href="admin-directory.php#roles">Cancel</a><?php endif; ?></div></form>
<div class="table-wrap"><table><thead><tr><th>Role</th><th>Section</th><th>Profile</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($roles as $role): ?><tr><td><strong><?php echo directoryAdminEscape($role['title']); ?></strong><br><span class="small"><?php echo directoryAdminEscape($role['responsibilities']); ?></span></td><td><?php echo directoryAdminEscape($role['section_name']); ?></td><td><?php echo directoryAdminEscape($role['display_name']); ?></td><td><span class="status status-<?php echo directoryAdminEscape($role['status']); ?>"><?php echo directoryAdminEscape($role['status']); ?></span></td><td><a class="btn secondary" href="admin-directory.php?edit_role=<?php echo (int)$role['id']; ?>#roles">Edit / move</a>
<form method="post" action="admin-directory.php"><input type="hidden" name="action" value="set_role_status"><input type="hidden" name="id" value="<?php echo (int)$role['id']; ?>"><input type="hidden" name="status" value="<?php echo $role['status']==='published'?'draft':'published'; ?>"><button class="btn secondary" type="submit"><?php echo $role['status']==='published'?'Unpublish':'Publish'; ?></button></form>
<form method="post" action="admin-directory.php" onsubmit="return confirm('Remove this role assignment? The profile itself will remain.');"><input type="hidden" name="action" value="delete_role"><input type="hidden" name="id" value="<?php echo (int)$role['id']; ?>"><button class="btn danger" type="submit">Remove role</button></form></td></tr><?php endforeach; ?>
<?php if (!$roles): ?><tr><td colspan="5">No role assignments yet.</td></tr><?php endif; ?></tbody></table></div></section>

<section class="panel" id="sections"><h2><?php echo $sectionForm['id'] ? 'Edit section' : 'Add a section'; ?></h2><p class="hint">Sections organize the directory. Hide a section to remove it from the public page without deleting its roles.</p>
<form method="post" action="admin-directory.php"><input type="hidden" name="action" value="save_section"><?php if ($sectionForm['id']): ?><input type="hidden" name="id" value="<?php echo (int)$sectionForm['id']; ?>"><?php endif; ?>
<div class="grid"><label class="field">Section name *<input name="name" maxlength="100" required value="<?php echo directoryAdminEscape($sectionForm['name']); ?>" placeholder="e.g., Group Leadership"></label><label class="field">Display order (lower appears first)<input type="number" name="sort_order" step="1" value="<?php echo (int)$sectionForm['sort_order']; ?>"></label></div>
<label class="field">Description<textarea name="description" maxlength="1000"><?php echo directoryAdminEscape($sectionForm['description']); ?></textarea></label><label class="check"><input type="checkbox" name="is_visible" value="1" <?php echo !empty($sectionForm['is_visible'])?'checked':''; ?>> Section visible publicly (published roles only)</label>
<div class="actions"><button class="btn" type="submit">Save section</button><?php if ($sectionForm['id']): ?><a class="btn secondary" href="admin-directory.php#sections">Cancel</a><?php endif; ?></div></form>
<?php foreach ($sections as $section): ?><div class="section-row"><strong><?php echo directoryAdminEscape($section['name']); ?></strong> <span class="small">· order <?php echo (int)$section['sort_order']; ?> · <?php echo $section['is_visible']?'visible':'hidden'; ?></span><p class="small"><?php echo directoryAdminEscape($section['description']); ?></p><a class="btn secondary" href="admin-directory.php?edit_section=<?php echo (int)$section['id']; ?>#sections">Edit section</a></div><?php endforeach; ?>
</section>
</main></body></html>
