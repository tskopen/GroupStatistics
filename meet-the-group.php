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
function meetRenderRoleCard(array $role, string $nodeClass = ''): void {
    $initials = '';
    foreach (preg_split('/\s+/', trim($role['display_name'])) as $part) {
        if ($part !== '') $initials .= function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
    }
    $photoUrl = !empty($role['photo_filename']) ? iconUrl($role['photo_filename']) : null;
    ?>
    <article class="command-node <?php echo meetGroupEscape($nodeClass); ?>">
        <h3><?php echo meetGroupEscape($role['title']); ?></h3>
        <div class="rank-label"><?php echo meetGroupEscape($role['display_name']); ?></div>
        <div class="profile"><?php if ($photoUrl): ?><img src="<?php echo meetGroupEscape($photoUrl); ?>" alt="Photo of <?php echo meetGroupEscape($role['display_name']); ?>"><?php else: ?><div class="avatar" aria-hidden="true"><?php echo meetGroupEscape(function_exists('mb_substr') ? mb_substr($initials, 0, 2, 'UTF-8') : substr($initials, 0, 2)); ?></div><?php endif; ?></div>
        <?php if (trim($role['bio']) !== ''): ?><p class="profile-bio"><?php echo meetGroupEscape($role['bio']); ?></p><?php endif; ?>
        <?php if (trim($role['responsibilities']) !== ''): ?><p class="responsibilities"><?php echo meetGroupEscape($role['responsibilities']); ?></p><?php endif; ?>
        <?php if (trim($role['contact']) !== ''): ?><div><span class="contact"><?php echo meetGroupEscape($role['contact']); ?></span></div><?php endif; ?>
    </article>
    <?php
}
function meetRoleMatches(array $role, array $patterns): bool {
    $title = strtolower(preg_replace('/[^a-z0-9]+/', '', $role['title']));
    foreach ($patterns as $pattern) {
        if (str_contains($title, strtolower(preg_replace('/[^a-z0-9]+/', '', $pattern)))) return true;
    }
    return false;
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
*{box-sizing:border-box}body{margin:0;padding:0;font-family:Arial,sans-serif;background:var(--page);color:var(--ink)}.container{max-width:1180px;margin:0 auto;padding:24px 18px 50px}.topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:20px}.topbar a{color:var(--primary);font-weight:bold;text-decoration:none}.hero{background:linear-gradient(135deg,var(--primary),var(--secondary));color:#fff;border-radius:12px;padding:30px;margin-bottom:28px}.hero h1{margin:0 0 10px;font-size:clamp(2rem,4vw,3rem)}.hero p{margin:0;max-width:780px;line-height:1.6;color:#e7eef7}.section{margin:28px 0}.section h2{color:var(--primary);border-bottom:3px solid var(--primary);padding-bottom:10px;margin-bottom:8px}.section-description{color:#536273;margin:0 0 18px;line-height:1.5}.role-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(255px,1fr));gap:18px}.role-card{background:var(--surface);border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.09);border:1px solid var(--line)}.role-card-head{background:var(--secondary);color:#fff;padding:13px 16px;font-weight:bold}.role-card-body{padding:18px}.profile{display:flex;gap:14px;align-items:center;margin-bottom:14px}.profile img,.avatar{width:76px;height:76px;flex:0 0 76px;border-radius:8px;object-fit:cover}.avatar{display:flex;align-items:center;justify-content:center;background:var(--soft);color:var(--primary);font-size:1.6rem;font-weight:bold}.profile h3{font-size:1.1rem;margin:0 0 5px;color:#002147}.profile p{margin:0;color:#657184;font-size:.92rem}.responsibilities{line-height:1.55;white-space:pre-line;margin:12px 0 0}.contact{display:inline-block;margin-top:12px;color:var(--secondary);overflow-wrap:anywhere}.command-chart{display:flex;flex-direction:column;align-items:center;gap:0;margin:26px auto 8px;max-width:1000px}.command-tier{display:flex;justify-content:center;align-items:stretch;gap:clamp(22px,7vw,110px);width:100%;position:relative}.command-tier + .command-tier{margin-top:54px}.command-tier + .command-tier:before{content:'';position:absolute;top:-28px;left:50%;height:28px;border-left:2px solid #9aa8b8}.command-tier.dual:after{content:'';position:absolute;top:-28px;left:25%;right:25%;border-top:2px solid #9aa8b8}.command-tier.support:before{content:'';position:absolute;top:-28px;left:12.5%;right:12.5%;border-top:2px solid #9aa8b8}.command-node{background:#e7f1fc;border:1px solid #6b9bd5;border-radius:12px;color:#174f88;text-align:center;padding:14px 20px;min-width:220px;max-width:100%;flex:0 1 auto;box-shadow:0 1px 2px rgba(0,0,0,.04)}.command-node.primary-node{width:256px;max-width:300px;flex:0 1 256px}.command-node.support-node{background:#f1efe9;border-color:#a5a39d;color:#484846}.command-node h3{font-size:1.12rem;margin:0 0 5px;font-weight:600}.command-node .rank-label{font-size:.98rem;color:#1c70c3}.support-node .rank-label{color:#666}.command-node .profile{justify-content:center;margin:12px 0 0;flex-wrap:wrap}.command-node .profile img,.command-node .avatar{width:58px;height:58px;flex-basis:58px}.command-node .profile h3{color:inherit;font-size:1rem}.command-node .profile p{color:#657184;font-size:.86rem}.command-node .responsibilities{font-size:.9rem;margin-top:8px}.command-node .contact{font-size:.85rem}.command-extras{margin-top:28px}.command-extras h3{color:var(--primary);margin:0 0 14px}.command-chart .command-tier .command-node:only-child{max-width:300px}@media(max-width:680px){.command-tier{gap:10px;flex-wrap:wrap}.command-node{min-width:0;flex:1 1 42%;padding:12px 10px}.command-tier + .command-tier{margin-top:36px}.command-tier + .command-tier:before{top:-19px;height:19px}.command-tier.dual:after,.command-tier.support:before{display:none}.command-node.primary-node{flex-basis:100%}}.empty{background:#fff;padding:22px;border-radius:8px;color:#536273}.footer{text-align:center;margin:36px 0 10px}.footer a{color:var(--primary)}.back-link{display:inline-block;padding:10px 14px;border-radius:6px;background:var(--primary);color:#fff!important;text-decoration:none}

:root{--navy:var(--primary);--blue:var(--secondary)}
/* Match the Group Announcements banner exactly. */
header{background:linear-gradient(120deg,var(--navy),var(--blue));color:#fff;padding:30px 20px}
.header-inner,.container{max-width:1080px;margin:0 auto}
.eyebrow{text-transform:uppercase;letter-spacing:.13em;font-size:.76rem;font-weight:700;opacity:.8}
.header-inner > h1{font-size:clamp(1.8rem,4vw,2.6rem);margin:7px 0;color:#fff;text-align:left}
header p{margin:0;max-width:650px;color:#e4edf7}
@media(max-width:600px){header{padding:24px 17px}.container{padding:14px 12px 35px}}
</style>
<link rel="stylesheet" href="site-ui.css">
<link rel="stylesheet" href="site-components.css">
</head>
<body>
<?php require_once __DIR__ . '/site-header.php'; renderGroupSiteHeader(); ?>
<header><div class="header-inner">
<div class="eyebrow">USAFA · Group 1</div>
<h1>👥 Meet the Group</h1>
<p>Get to know the people and roles that support USAFA Group 1. Role information is maintained by site administrators and only published entries appear here.</p>
</div></header>
<section class="squadron-pages-callout" style="max-width:1080px;margin:18px auto 0;padding:0 18px">
<a href="squadron-pages.php" style="display:block;background:#fff;border:1px solid var(--line);border-left:5px solid var(--primary);border-radius:9px;padding:15px 18px;color:var(--primary);font-weight:700;text-decoration:none">🏅 Explore Squadron Pages · View each squadron's flights and Flight vs Flight standings →</a>
</section>
<main class="container">
<?php if (!$sections): ?>
<div class="empty">The directory is being updated. Please check back later.</div>
<?php else: foreach ($sections as $section): ?>
<section class="section" aria-labelledby="section-<?php echo (int)$section['id']; ?>">
<h2 id="section-<?php echo (int)$section['id']; ?>"><?php echo meetGroupEscape($section['name']); ?></h2>
<?php if (trim($section['description']) !== ''): ?><p class="section-description"><?php echo nl2br(meetGroupEscape($section['description'])); ?></p><?php endif; ?>
<?php
$rolesStmt->execute([(int)$section['id']]);
$roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);
if (strcasecmp(trim($section['name']), 'Group Leadership') === 0):
    $slotPatterns = [
        'commander' => ['Group commander', 'Gp/CC', 'group command'],
        'deputy' => ['Deputy group commander', 'Gp/CD', 'deputy group'],
        'chief' => ['Group chief of staff', 'Gp/CoS', 'chief of staff'],
        'exec' => ['Exec', 'Executive officer'],
        'sel' => ['SEL', 'Senior enlisted leader'],
        'first_shirt' => ['First Shirt', 'First sergeant'],
        'superintendent' => ['Gp/Supt', 'Staff superintendent', 'group superintendent']
    ];
    $slots = array_fill_keys(array_keys($slotPatterns), null);
    $extras = [];
    foreach ($roles as $role) {
        $assigned = false;
        foreach ($slotPatterns as $slot => $patterns) {
            if ($slots[$slot] === null && meetRoleMatches($role, $patterns)) {
                $slots[$slot] = $role; $assigned = true; break;
            }
        }
        if (!$assigned) $extras[] = $role;
    }
?>
<div class="command-chart" aria-label="Group staff chain of command">
    <?php if ($slots['commander']): ?><div class="command-tier"><?php meetRenderRoleCard($slots['commander'], 'primary-node'); ?></div><?php endif; ?>
    <?php if ($slots['deputy'] || $slots['chief']): ?><div class="command-tier dual">
        <?php if ($slots['deputy']) meetRenderRoleCard($slots['deputy']); ?>
        <?php if ($slots['chief']) meetRenderRoleCard($slots['chief']); ?>
    </div><?php endif; ?>
    <?php $support = array_filter([$slots['exec'], $slots['sel'], $slots['first_shirt'], $slots['superintendent']]); ?>
    <?php if ($support): ?><div class="command-tier support">
        <?php foreach ($support as $role) meetRenderRoleCard($role, 'support-node'); ?>
    </div><?php endif; ?>
</div>
<?php if ($extras): ?><div class="command-extras"><h3>Additional Group Leadership</h3><div class="role-grid"><?php foreach ($extras as $role) meetRenderRoleCard($role); ?></div></div><?php endif; ?>
<?php else: ?>
<div class="role-grid"><?php foreach ($roles as $role) meetRenderRoleCard($role); ?></div>
<?php endif; ?>
</section>
<?php endforeach; endif; ?>
<div class="footer"><a class="back-link" href="index.php">Return to the Group Tracker</a></div>
</main></body></html>
