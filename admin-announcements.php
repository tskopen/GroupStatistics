<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/theme-loader.php';
$theme = loadTheme();
if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

$db = getDb();
$errors = [];
$notice = '';
$noticeMessages = [
    'created' => 'Announcement created.',
    'saved' => 'Changes saved.',
    'deleted' => 'Announcement permanently deleted.',
    'status' => 'Announcement status updated.',
];
$noticeKey = (string)($_GET['notice'] ?? '');
if (isset($noticeMessages[$noticeKey])) $notice = $noticeMessages[$noticeKey];

function adminAnnouncementEscape($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function announcementTextLength(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}
function announcementTextExcerpt(string $value, int $limit = 220): string {
    if (function_exists('mb_strimwidth')) return mb_strimwidth($value, 0, $limit, '…', 'UTF-8');
    return strlen($value) > $limit ? substr($value, 0, $limit - 3) . '...' : $value;
}
function announcementInputDate(?string $value): string {
    if (!$value) return '';
    try { return (new DateTimeImmutable($value, new DateTimeZone('America/Denver')))->format('Y-m-d\\TH:i'); }
    catch (Throwable $e) { return ''; }
}
function announcementParseInputDate(string $value, string $label, array &$errors): ?string {
    if (trim($value) === '') return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
        $errors[] = $label . ' is not a valid date and time.';
        return null;
    }
    return $date->format('Y-m-d H:i:s');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($action === 'delete') {
        if (!$id) {
            $errors[] = 'Choose a valid announcement to delete.';
        } else {
            $stmt = $db->prepare('DELETE FROM group_announcements WHERE id = ?');
            $stmt->execute([$id]);
            header('Location: admin-announcements.php?notice=deleted');
            exit;
        }
    } elseif ($action === 'set_status') {
        $status = (string)($_POST['status'] ?? '');
        if (!$id || !in_array($status, ['draft', 'published'], true)) {
            $errors[] = 'The requested status change is invalid.';
        } else {
            $stmt = $db->prepare('UPDATE group_announcements SET status = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([$status, date('Y-m-d H:i:s'), $id]);
            header('Location: admin-announcements.php?notice=status');
            exit;
        }
    } elseif ($action === 'save') {
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $link = trim((string)($_POST['link_url'] ?? ''));
        $startRaw = trim((string)($_POST['start_at'] ?? ''));
        $endRaw = trim((string)($_POST['end_at'] ?? ''));
        $status = (string)($_POST['save_status'] ?? 'draft');

        if ($title === '' || announcementTextLength($title) > 120) $errors[] = 'Title is required and must be 120 characters or fewer.';
        if ($description === '' || announcementTextLength($description) > 5000) $errors[] = 'Description is required and must be 5,000 characters or fewer.';
        if (announcementTextLength($location) > 160) $errors[] = 'Location must be 160 characters or fewer.';
        if (!in_array($status, ['draft', 'published'], true)) $errors[] = 'Choose whether to save as a draft or publish.';
        if ($link !== '') {
            $scheme = strtolower((string)parse_url($link, PHP_URL_SCHEME));
            if (!filter_var($link, FILTER_VALIDATE_URL) || $scheme !== 'https') {
                $errors[] = 'Event link must be a valid HTTPS URL (for example, https://example.com/event).';
            }
        }
        $start = announcementParseInputDate($startRaw, 'Start date', $errors);
        $end = announcementParseInputDate($endRaw, 'End date', $errors);
        if ($start !== null && $end !== null && strtotime($end) < strtotime($start)) {
            $errors[] = 'End date and time must be after the start date and time.';
        }

        $form = [
            'id' => $id ?: null, 'title' => $title, 'description' => $description,
            'location' => $location, 'link_url' => $link, 'start_at' => $startRaw,
            'end_at' => $endRaw, 'status' => $status,
        ];
        if (!$errors) {
            $now = date('Y-m-d H:i:s');
            if ($id) {
                $stmt = $db->prepare('UPDATE group_announcements SET title=?, description=?, start_at=?, end_at=?, location=?, link_url=?, status=?, updated_at=? WHERE id=?');
                $stmt->execute([$title, $description, $start, $end, $location !== '' ? $location : null, $link !== '' ? $link : null, $status, $now, $id]);
                header('Location: admin-announcements.php?notice=saved');
            } else {
                $stmt = $db->prepare('INSERT INTO group_announcements(title,description,start_at,end_at,location,link_url,status,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$title, $description, $start, $end, $location !== '' ? $location : null, $link !== '' ? $link : null, $status, (string)($_SESSION['username'] ?? 'admin'), $now, $now]);
                header('Location: admin-announcements.php?notice=created');
            }
            exit;
        }
    } else {
        $errors[] = 'That action was not recognized. Please try again.';
    }
}

$editingId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$editing = null;
if ($editingId) {
    $stmt = $db->prepare('SELECT * FROM group_announcements WHERE id = ?');
    $stmt->execute([$editingId]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$form = $form ?? [
    'id' => $editing['id'] ?? null,
    'title' => $editing['title'] ?? '',
    'description' => $editing['description'] ?? '',
    'location' => $editing['location'] ?? '',
    'link_url' => $editing['link_url'] ?? '',
    'start_at' => announcementInputDate($editing['start_at'] ?? null),
    'end_at' => announcementInputDate($editing['end_at'] ?? null),
    'status' => $editing['status'] ?? 'published',
];
if ($editingId && !$editing && !$errors) $errors[] = 'That announcement could not be found. It may have been deleted.';

$search = trim((string)($_GET['q'] ?? ''));
$sql = 'SELECT * FROM group_announcements';
$params = [];
if ($search !== '') {
    $sql .= ' WHERE title LIKE ? OR description LIKE ? OR location LIKE ?';
    $needle = '%' . $search . '%';
    $params = [$needle, $needle, $needle];
}
$sql .= " ORDER BY CASE status WHEN 'draft' THEN 0 ELSE 1 END, COALESCE(start_at, created_at) ASC, id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
$counts = $db->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) AS published, SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) AS drafts FROM group_announcements")->fetch(PDO::FETCH_ASSOC) ?: [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<style>:root{--primary-color:<?php echo htmlspecialchars($theme['primary_color'] ?? '#002147', ENT_QUOTES, 'UTF-8'); ?>;--secondary-color:<?php echo htmlspecialchars($theme['secondary_color'] ?? '#003366', ENT_QUOTES, 'UTF-8'); ?>;--accent-color:<?php echo htmlspecialchars($theme['accent_color'] ?? '#667eea', ENT_QUOTES, 'UTF-8'); ?>;--background-color:<?php echo htmlspecialchars($theme['background_color'] ?? '#f4f4f4', ENT_QUOTES, 'UTF-8'); ?>;--text-color:<?php echo htmlspecialchars($theme['text_color'] ?? '#222222', ENT_QUOTES, 'UTF-8'); ?>;--surface-color:#fff;--border-color:#d7dee8;--muted-color:#5b6575}</style>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#002147">
<title>Manage Announcements | Squadron Tracker Admin</title>
<style>
:root{--navy:#002147;--blue:#003366;--accent:#667eea;--ink:#1e293b;--muted:#64748b;--line:#dbe3ed;--page:#f3f6fa;--danger:#b42332;--green:#167447}
*{box-sizing:border-box}body{margin:0;background:var(--page);color:var(--ink);font-family:Arial,Helvetica,sans-serif;line-height:1.5}
.topbar{background:var(--navy);color:#fff;padding:20px}.topbar-inner{max-width:1240px;margin:auto;display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap}.topbar h1{font-size:1.5rem;margin:0}.topbar p{margin:3px 0 0;color:#d7e4f2;font-size:.92rem}.top-links{display:flex;gap:8px;flex-wrap:wrap}.top-links a{color:#fff;text-decoration:none;border:1px solid #ffffff60;border-radius:8px;padding:8px 11px;font-weight:700;font-size:.9rem}
.wrap{max-width:1240px;margin:24px auto;padding:0 16px 45px}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:20px}.stat{background:#fff;border:1px solid var(--line);border-radius:12px;padding:15px 18px}.stat span{display:block;color:var(--muted);font-size:.85rem;font-weight:700}.stat strong{font-size:1.8rem;color:var(--navy)}
.layout{display:grid;grid-template-columns:minmax(300px,.85fr) minmax(0,1.4fr);gap:20px;align-items:start}.panel{background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 4px 14px #0f172a08;overflow:hidden}.panel-head{padding:18px 20px;border-bottom:1px solid var(--line);background:#fbfcfe}.panel-head h2{margin:0;color:var(--navy);font-size:1.15rem}.panel-head p{margin:5px 0 0;color:var(--muted);font-size:.9rem}.panel-body{padding:20px}
label{display:block;font-weight:700;color:#334155;font-size:.9rem;margin:14px 0 6px}label:first-child{margin-top:0}.hint{font-size:.8rem;color:var(--muted);font-weight:400}input[type=text],input[type=url],input[type=datetime-local],input[type=search],textarea,select{display:block;width:100%;min-width:0;max-width:100%;padding:11px 12px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:var(--ink);font:inherit}textarea{min-height:145px;resize:vertical}input:focus,textarea:focus,select:focus{outline:3px solid #667eea30;border-color:var(--accent)}.date-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.date-grid>div{min-width:0}.date-grid input[type=datetime-local]{padding:11px 8px;min-width:0;max-width:100%;font-size:.92rem}.date-grid .hint{overflow-wrap:anywhere}
.actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:18px}.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 13px;border:0;border-radius:8px;text-decoration:none;font-weight:700;font-size:.9rem;cursor:pointer}.btn-primary{background:var(--navy);color:#fff}.btn-secondary{background:#e8eef6;color:var(--navy)}.btn-danger{background:#fff0f0;color:var(--danger);border:1px solid #ffd0d0}.btn-small{padding:7px 10px;font-size:.82rem}.btn:hover{filter:brightness(.96)}.alert{padding:12px 14px;border-radius:9px;margin-bottom:16px}.success{background:#eaf8ef;color:#155b35;border:1px solid #c4ebd1}.error{background:#fff0f0;color:#8d1825;border:1px solid #ffd0d0}.error ul{margin:5px 0 0;padding-left:20px}
.list-toolbar{display:flex;gap:10px;align-items:center;margin-bottom:15px}.list-toolbar input{flex:1;min-width:0}.announcement-row{padding:16px 0;border-bottom:1px solid var(--line)}.announcement-row:first-child{padding-top:0}.announcement-row:last-child{border-bottom:0;padding-bottom:0}.row-title{display:flex;justify-content:space-between;align-items:start;gap:12px}.row-title h3{margin:0;color:var(--navy);font-size:1.03rem;overflow-wrap:anywhere}.status{flex-shrink:0;border-radius:999px;padding:4px 9px;font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em}.status-published{background:#e8f7ee;color:#17633b}.status-draft{background:#fff3d7;color:#875600}.row-meta{margin:7px 0;color:var(--muted);font-size:.84rem}.row-description{margin:8px 0 12px;color:#475569;white-space:pre-line;overflow-wrap:anywhere}.row-actions{display:flex;gap:7px;flex-wrap:wrap}.inline-form{display:inline}.empty{padding:26px 12px;text-align:center;color:var(--muted);background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px}
@media(max-width:900px){.layout{grid-template-columns:1fr}.stats{gap:8px}.panel-body{padding:16px}}
@media(max-width:600px){.date-grid{grid-template-columns:minmax(0,1fr)}.date-grid input[type=datetime-local]{font-size:1rem;padding:11px 10px}}@media(max-width:520px){.wrap{padding:0 10px 30px}.stats{grid-template-columns:1fr 1fr}.stat:last-child{grid-column:1/-1}.list-toolbar{align-items:stretch;flex-direction:column}.row-title{align-items:start}.topbar h1{font-size:1.25rem}}
</style>
<link rel="stylesheet" href="site-ui.css">
<link rel="stylesheet" href="site-components.css">
</head>
<body>
<?php require_once __DIR__ . '/site-header.php'; renderGroupSiteHeader(); ?>
<header class="topbar"><div class="topbar-inner">
<div><h1>📣 Manage Group Announcements</h1><p>Create event posts, keep details current, and remove outdated announcements.</p></div>
<nav class="top-links"><a href="announcements.php">View public board ↗</a><a href="admin-panel.php">Admin panel</a><a href="index.php">Home</a></nav>
</div></header>
<main class="wrap">
<?php if ($notice): ?><div class="alert success" role="status"><?php echo adminAnnouncementEscape($notice); ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert error" role="alert"><strong>Please review the following:</strong><ul><?php foreach ($errors as $error): ?><li><?php echo adminAnnouncementEscape($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<section class="stats" aria-label="Announcement totals">
<div class="stat"><span>Total announcements</span><strong><?php echo (int)($counts['total'] ?? 0); ?></strong></div>
<div class="stat"><span>Published and public</span><strong><?php echo (int)($counts['published'] ?? 0); ?></strong></div>
<div class="stat"><span>Drafts (admins only)</span><strong><?php echo (int)($counts['drafts'] ?? 0); ?></strong></div>
</section>

<div class="layout">
<section class="panel" id="editor">
<div class="panel-head"><h2><?php echo !empty($form['id']) ? 'Edit announcement' : 'Create an announcement'; ?></h2><p>Only published posts appear on the public board. You can save a draft and publish it later.</p></div>
<div class="panel-body">
<form method="post" action="admin-announcements.php<?php echo !empty($form['id']) ? '?edit=' . (int)$form['id'] : ''; ?>">
<input type="hidden" name="action" value="save">
<?php if (!empty($form['id'])): ?><input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>"><?php endif; ?>
<label for="title">Announcement title <span class="hint">(required, max 120 characters)</span></label>
<input type="text" id="title" name="title" maxlength="120" required value="<?php echo adminAnnouncementEscape($form['title']); ?>" placeholder="e.g., Group 1 Fall Festival">
<label for="description">Details <span class="hint">(required, max 5,000 characters)</span></label>
<textarea id="description" name="description" maxlength="5000" required placeholder="What should cadets know? Include key instructions, schedule details, or what to bring."><?php echo adminAnnouncementEscape($form['description']); ?></textarea>
<label for="start_at">Date and time <span class="hint">(optional · Mountain Time)</span></label>
<div class="date-grid"><div><input type="datetime-local" id="start_at" name="start_at" value="<?php echo adminAnnouncementEscape($form['start_at']); ?>" aria-label="Start date and time"><p class="hint">Start date and time</p></div><div><input type="datetime-local" id="end_at" name="end_at" value="<?php echo adminAnnouncementEscape($form['end_at']); ?>" aria-label="End date and time"><p class="hint">End date and time (optional)</p></div></div>
<label for="location">Location <span class="hint">(optional)</span></label>
<input type="text" id="location" name="location" maxlength="160" value="<?php echo adminAnnouncementEscape($form['location']); ?>" placeholder="e.g., Arnold Hall">
<label for="link_url">Event link <span class="hint">(optional; HTTPS only)</span></label>
<input type="url" id="link_url" name="link_url" value="<?php echo adminAnnouncementEscape($form['link_url']); ?>" placeholder="https://example.com/event">
<div class="actions">
<button class="btn btn-primary" type="submit" name="save_status" value="published">✓ Save and publish</button>
<button class="btn btn-secondary" type="submit" name="save_status" value="draft">Save draft</button>
<?php if (!empty($form['id'])): ?><a class="btn btn-secondary" href="admin-announcements.php">Cancel edit</a><?php endif; ?>
</div>
</form>
</div></section>

<section class="panel">
<div class="panel-head"><h2>All announcements</h2><p>Edit a post, publish a draft, unpublish a post, or permanently delete it.</p></div>
<div class="panel-body">
<form class="list-toolbar" method="get" action="admin-announcements.php">
<input type="search" name="q" value="<?php echo adminAnnouncementEscape($search); ?>" placeholder="Search titles, details, or locations" aria-label="Search announcements">
<button class="btn btn-secondary" type="submit">Search</button>
<?php if ($search !== ''): ?><a class="btn btn-secondary" href="admin-announcements.php">Clear</a><?php endif; ?>
</form>
<?php if (!$items): ?><div class="empty"><?php echo $search !== '' ? 'No announcements match that search.' : 'No announcements yet. Use the form to create your first one.'; ?></div><?php endif; ?>
<?php foreach ($items as $item): ?>
<article class="announcement-row">
<div class="row-title"><h3><?php echo adminAnnouncementEscape($item['title']); ?></h3><span class="status status-<?php echo adminAnnouncementEscape($item['status']); ?>"><?php echo adminAnnouncementEscape($item['status']); ?></span></div>
<div class="row-meta">
<?php echo !empty($item['start_at']) ? '🗓 ' . adminAnnouncementEscape((new DateTimeImmutable($item['start_at'], new DateTimeZone('America/Denver')))->format('M j, Y · g:i A T')) : '🗓 Date not set'; ?>
<?php if (!empty($item['location'])): ?> · 📍 <?php echo adminAnnouncementEscape($item['location']); ?><?php endif; ?>
<br>Updated <?php echo adminAnnouncementEscape((new DateTimeImmutable($item['updated_at']))->format('M j, Y')); ?>
</div>
<p class="row-description"><?php echo adminAnnouncementEscape(announcementTextExcerpt($item['description'])); ?></p>
<div class="row-actions">
<a class="btn btn-secondary btn-small" href="admin-announcements.php?edit=<?php echo (int)$item['id']; ?>#editor">✎ Edit</a>
<form class="inline-form" method="post" action="admin-announcements.php">
<input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
<input type="hidden" name="status" value="<?php echo $item['status'] === 'published' ? 'draft' : 'published'; ?>">
<button class="btn btn-secondary btn-small" type="submit"><?php echo $item['status'] === 'published' ? 'Unpublish' : 'Publish'; ?></button>
</form>
<form class="inline-form" method="post" action="admin-announcements.php" onsubmit="return confirm('Permanently delete this announcement? This cannot be undone.');">
<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
<button class="btn btn-danger btn-small" type="submit">🗑 Delete</button>
</form>
</div>
</article>
<?php endforeach; ?>
</div></section>
</div>
</main>
</body>
</html>
