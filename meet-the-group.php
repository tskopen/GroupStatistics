<?php
require __DIR__ . '/config.php';
require __DIR__ . '/theme-loader.php';
$theme = loadTheme();
$db = getDb();

$sections = $db->query(
    "SELECT s.id, s.name, s.description
     FROM directory_sections s
     WHERE s.is_visible = 1
       AND EXISTS (SELECT 1 FROM directory_roles r WHERE r.section_id = s.id AND r.status = 'published')
     ORDER BY s.sort_order, s.name"
)->fetchAll(PDO::FETCH_ASSOC);

$rolesStmt = $db->prepare(
    "SELECT r.id AS role_id, r.title, r.responsibilities, p.display_name, p.bio, p.contact, p.photo_filename
     FROM directory_roles r
     JOIN directory_people p ON p.id = r.person_id
     WHERE r.section_id = ? AND r.status = 'published'
     ORDER BY r.sort_order, r.title, p.display_name"
);
function meetGroupEscape($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?php echo meetGroupEscape($theme['primary_color']); ?>">
<title>Meet the Group | USAFA Group 1</title>
<style>
:root{--primary:<?php echo meetGroupEscape($theme['primary_color']); ?>;--secondary:<?php echo meetGroupEscape($theme['secondary_color']); ?>;--accent:<?php echo meetGroupEscape($theme['accent_color']); ?>;--page:<?php echo meetGroupEscape($theme['background_color']); ?>;--ink:<?php echo meetGroupEscape($theme['text_color']); ?>;--surface:color-mix(in srgb,var(--page),white 92%);--line:color-mix(in srgb,var(--primary),var(--page) 78%);--soft:color-mix(in srgb,var(--accent),white 88%)}
*{box-sizing:border-box}body{margin:0;padding:24px;font-family:Arial,sans-serif;background:var(--page);color:var(--ink)}.container{max-width:1180px;margin:0 auto}.topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:20px}.topbar a{color:var(--primary);font-weight:bold;text-decoration:none}.hero{background:linear-gradient(135deg,var(--primary),var(--secondary));color:#fff;border-radius:12px;padding:30px;margin-bottom:28px}.hero h1{margin:0 0 10px;font-size:clamp(2rem,4vw,3rem)}.hero p{margin:0;max-width:780px;line-height:1.6;color:#e7eef7}.section{margin:28px 0}.section h2{color:var(--primary);border-bottom:3px solid var(--primary);padding-bottom:10px;margin-bottom:8px}.section-description{color:#536273;margin:0 0 18px;line-height:1.5}.role-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(255px,1fr));gap:18px}.role-card{background:var(--surface);border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.09);border:1px solid var(--line)}.role-card-head{background:var(--secondary);color:#fff;padding:13px 16px;font-weight:bold}.role-card-body{padding:18px}.profile{display:flex;gap:14px;align-items:center;margin-bottom:14px}.profile img,.avatar{width:76px;height:76px;flex:0 0 76px;border-radius:8px;object-fit:cover}.avatar{display:flex;align-items:center;justify-content:center;background:var(--soft);color:var(--primary);font-size:1.6rem;font-weight:bold}.profile h3{font-size:1.1rem;margin:0 0 5px;color:#002147}.profile p{margin:0;color:#657184;font-size:.92rem}.responsibilities{line-height:1.55;white-space:pre-line;margin:12px 0 0}.contact{display:inline-block;margin-top:12px;color:var(--secondary);overflow-wrap:anywhere}.empty{background:#fff;padding:22px;border-radius:8px;color:#536273}.footer{text-align:center;margin:36px 0 10px}.footer a{color:var(--primary)}.back-link{display:inline-block;padding:10px 14px;border-radius:6px;background:var(--primary);color:#fff!important;text-decoration:none}
</style>
<link rel="stylesheet" href="site-ui.css">
</head>
<body>
<?php require_once __DIR__ . '/site-header.php'; renderGroupSiteHeader(); ?><main class="container">
<div class="topbar"><a href="index.php">← Group 1 Squadron Tracker</a><a href="announcements.php">Group Announcements</a></div>
<header class="hero"><h1>Meet the Group</h1><p>Get to know the people and roles that support USAFA Group 1. Role information is maintained by site administrators and only published entries appear here.</p></header>
<?php if (!$sections): ?>
<div class="empty">The directory is being updated. Please check back later.</div>
<?php else: foreach ($sections as $section): ?>
<section class="section" aria-labelledby="section-<?php echo (int)$section['id']; ?>">
<h2 id="section-<?php echo (int)$section['id']; ?>"><?php echo meetGroupEscape($section['name']); ?></h2>
<?php if (trim($section['description']) !== ''): ?><p class="section-description"><?php echo nl2br(meetGroupEscape($section['description'])); ?></p><?php endif; ?>
<div class="role-grid">
<?php
$rolesStmt->execute([(int)$section['id']]);
$roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($roles as $role):
$initials = '';
foreach (preg_split('/\s+/', trim($role['display_name'])) as $part) { if ($part !== '') $initials .= function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1); }
$photoUrl = !empty($role['photo_filename']) ? iconUrl($role['photo_filename']) : null;
?>
<article class="role-card">
<div class="role-card-head"><?php echo meetGroupEscape($role['title']); ?></div>
<div class="role-card-body">
<div class="profile">
<?php if ($photoUrl): ?><img src="<?php echo meetGroupEscape($photoUrl); ?>" alt="Photo of <?php echo meetGroupEscape($role['display_name']); ?>">
<?php else: ?><div class="avatar" aria-hidden="true"><?php echo meetGroupEscape(function_exists('mb_substr') ? mb_substr($initials, 0, 2, 'UTF-8') : substr($initials, 0, 2)); ?></div><?php endif; ?>
<div><h3><?php echo meetGroupEscape($role['display_name']); ?></h3><?php if (trim($role['bio']) !== ''): ?><p><?php echo meetGroupEscape($role['bio']); ?></p><?php endif; ?></div>
</div>
<?php if (trim($role['responsibilities']) !== ''): ?><p class="responsibilities"><?php echo meetGroupEscape($role['responsibilities']); ?></p><?php endif; ?>
<?php if (trim($role['contact']) !== ''): ?><div><span class="contact"><?php echo meetGroupEscape($role['contact']); ?></span></div><?php endif; ?>
</div></article>
<?php endforeach; ?>
</div></section>
<?php endforeach; endif; ?>
<div class="footer"><a class="back-link" href="index.php">Return to the Group Tracker</a></div>
</main></body></html>
