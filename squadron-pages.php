<?php
require __DIR__ . '/config.php';
require __DIR__ . '/theme-loader.php';
$theme = loadTheme();
$db = getDb();

$squadrons = $db->query(
    "SELECT s.id, s.name, s.description, s.icon_filename,
            COUNT(f.id) AS flight_count
     FROM squadrons s
     LEFT JOIN flights f ON f.squadron_id = s.id AND f.is_active = 1
     GROUP BY s.id
     ORDER BY s.id"
)->fetchAll(PDO::FETCH_ASSOC);

function squadronPagesEscape($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?php echo squadronPagesEscape($theme['primary_color'] ?? '#002147'); ?>">
<title>Squadron Pages | USAFA Group 1</title>
<link rel="stylesheet" href="site-ui.css">
<link rel="stylesheet" href="site-components.css">
<style>
:root{--primary:<?php echo squadronPagesEscape($theme['primary_color']??'#002147');?>;--secondary:<?php echo squadronPagesEscape($theme['secondary_color']??'#003366');?>;--page:<?php echo squadronPagesEscape($theme['background_color']??'#f4f6f9');?>;--ink:<?php echo squadronPagesEscape($theme['text_color']??'#1e293b');?>;--line:#dbe3ed}
*{box-sizing:border-box}body{margin:0;background:var(--page);color:var(--ink);font:16px/1.5 Arial,sans-serif}.hero{background:linear-gradient(120deg,var(--primary),var(--secondary));color:#fff;padding:28px 18px}.hero-inner,main{max-width:1120px;margin:0 auto}.hero h1{margin:5px 0 8px;font-size:clamp(1.8rem,4vw,2.6rem)}.hero p{margin:0;max-width:760px;color:#e7eef7}.eyebrow{font-size:.75rem;letter-spacing:.13em;text-transform:uppercase;font-weight:700;opacity:.85}main{padding:24px 16px 44px}.squadron-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(245px,1fr));gap:16px}.squadron-card{display:flex;align-items:center;gap:15px;min-width:0;padding:19px;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 2px 8px rgba(15,23,42,.06);color:inherit;text-decoration:none;transition:transform .15s,box-shadow .15s}.squadron-card:hover{transform:translateY(-2px);box-shadow:0 5px 14px rgba(15,23,42,.1)}.squadron-card img,.icon-placeholder{width:58px;height:58px;flex:0 0 58px;object-fit:contain;border-radius:9px;background:#f1f5f9;padding:5px}.icon-placeholder{display:flex;align-items:center;justify-content:center;color:var(--primary);font-weight:800;font-size:1.05rem}.squadron-card h2{margin:0 0 4px;color:var(--primary);font-size:1.1rem}.squadron-card p{margin:0;color:#64748b;font-size:.9rem}.card-arrow{margin-left:auto;color:var(--primary);font-size:1.3rem}.footer{margin-top:25px;text-align:center}.footer a{color:var(--primary)}@media(max-width:520px){main{padding:18px 12px 35px}.squadron-card{padding:15px}}
</style>
</head>
<body>
<?php require_once __DIR__ . '/site-header.php'; renderGroupSiteHeader(false); ?>
<header class="hero"><div class="hero-inner">
<div class="eyebrow">USAFA · Group 1</div>
<h1>Squadron Pages</h1>
<p>Select your squadron to view its flights, Flight vs Flight leaderboard, published competitions, and recent results. Each squadron competes only within its own flight standings.</p>
</div></header>
<main>
<div class="squadron-grid">
<?php foreach ($squadrons as $squadron): ?>
<a class="squadron-card" href="squadron-page.php?squadron_id=<?php echo (int)$squadron['id']; ?>">
<?php if (!empty($squadron['icon_filename'])): ?>
<img src="<?php echo squadronPagesEscape(iconUrl($squadron['icon_filename'])); ?>" alt="<?php echo squadronPagesEscape($squadron['name']); ?> logo">
<?php else: ?>
<span class="icon-placeholder" aria-hidden="true"><?php echo (int)$squadron['id']; ?></span>
<?php endif; ?>
<span>
<h2>Squadron <?php echo (int)$squadron['id']; ?> · <?php echo squadronPagesEscape($squadron['name']); ?></h2>
<p><?php echo (int)$squadron['flight_count']; ?> active <?php echo (int)$squadron['flight_count'] === 1 ? 'flight' : 'flights'; ?></p>
</span>
<span class="card-arrow" aria-hidden="true">→</span>
</a>
<?php endforeach; ?>
</div>
<div class="footer"><a href="meet-the-group.php">Meet the Group</a> · <a href="index.php">Return to the Group Tracker</a></div>
</main>
</body>
</html>
