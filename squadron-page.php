<?php
require __DIR__ . '/config.php';
require __DIR__ . '/theme-loader.php';
$theme = loadTheme();
$db = getDb();
$squadronId = filter_input(INPUT_GET, 'squadron_id', FILTER_VALIDATE_INT);
if (!$squadronId || $squadronId < 1) {
    http_response_code(404);
    exit('Squadron not found.');
}
$squadronStmt = $db->prepare('SELECT id,name,description,icon_filename FROM squadrons WHERE id=? LIMIT 1');
$squadronStmt->execute([$squadronId]);
$squadron = $squadronStmt->fetch(PDO::FETCH_ASSOC);
if (!$squadron) {
    http_response_code(404);
    exit('Squadron not found.');
}

$leaderboardStmt = $db->prepare(
    "SELECT f.id, f.name,
            COALESCE(SUM(CASE WHEN c.status='published' AND r.status='published' THEN r.points ELSE 0 END),0) AS total_points,
            COUNT(CASE WHEN c.status='published' AND r.status='published' THEN r.id END) AS result_count
     FROM flights f
     LEFT JOIN flight_competitions c ON c.squadron_id=f.squadron_id
     LEFT JOIN flight_competition_results r
       ON r.competition_id=c.id AND r.flight_id=f.id AND r.squadron_id=f.squadron_id
     WHERE f.squadron_id=? AND f.is_active=1
     GROUP BY f.id, f.name, f.display_order
     ORDER BY total_points DESC, f.display_order ASC, f.name COLLATE NOCASE ASC"
);
$leaderboardStmt->execute([$squadronId]);
$flights = $leaderboardStmt->fetchAll(PDO::FETCH_ASSOC);

$competitionsStmt = $db->prepare(
    "SELECT c.*,
            (SELECT COUNT(*) FROM flight_competition_results r
             WHERE r.competition_id=c.id AND r.status='published') AS result_count
     FROM flight_competitions c
     WHERE c.squadron_id=? AND c.status='published'
     ORDER BY c.starts_at DESC, c.id DESC"
);
$competitionsStmt->execute([$squadronId]);
$competitions = $competitionsStmt->fetchAll(PDO::FETCH_ASSOC);

$recentStmt = $db->prepare(
    "SELECT r.title, r.description, r.points, r.awarded_at,
            f.name AS flight_name, c.title AS competition_title
     FROM flight_competition_results r
     JOIN flight_competitions c ON c.id=r.competition_id AND c.squadron_id=r.squadron_id
     JOIN flights f ON f.id=r.flight_id AND f.squadron_id=r.squadron_id
     WHERE r.squadron_id=? AND c.status='published' AND r.status='published'
     ORDER BY r.awarded_at DESC, r.id DESC
     LIMIT 12"
);
$recentStmt->execute([$squadronId]);
$recentResults = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

function squadronPageEscape($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function squadronPageDate(?string $value): string {
    if (!$value) return 'Not scheduled';
    try { return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('America/Denver'))->format('M j, Y · g:i A T'); }
    catch (Throwable $e) { return $value; }
}
function squadronPagePoints($value): string {
    $number = number_format((float)$value, 2, '.', '');
    return rtrim(rtrim($number, '0'), '.') ?: '0';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?php echo squadronPageEscape($theme['primary_color']??'#002147'); ?>">
<title><?php echo squadronPageEscape($squadron['name']); ?> | Flight vs Flight</title>
<link rel="stylesheet" href="site-ui.css">
<link rel="stylesheet" href="site-components.css">
<style>
:root{--primary:<?php echo squadronPageEscape($theme['primary_color']??'#002147');?>;--secondary:<?php echo squadronPageEscape($theme['secondary_color']??'#003366');?>;--page:<?php echo squadronPageEscape($theme['background_color']??'#f4f6f9');?>;--ink:<?php echo squadronPageEscape($theme['text_color']??'#1e293b');?>;--line:#dbe3ed}
*{box-sizing:border-box}body{margin:0;background:var(--page);color:var(--ink);font:16px/1.5 Arial,sans-serif}.hero{background:linear-gradient(120deg,var(--primary),var(--secondary));color:#fff;padding:26px 18px}.hero-inner,main{max-width:1120px;margin:0 auto}.hero h1{margin:5px 0 8px;font-size:clamp(1.8rem,4vw,2.5rem)}.hero p{margin:0;max-width:780px;color:#e7eef7;line-height:1.55}.eyebrow{font-size:.75rem;letter-spacing:.13em;text-transform:uppercase;font-weight:700;opacity:.85}.breadcrumbs{margin-top:13px}.breadcrumbs a{color:#fff}.hero-logo{float:right;width:68px;height:68px;object-fit:contain;margin-left:18px}.hero:after{content:"";display:block;clear:both}main{padding:23px 16px 45px}.layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px}.panel{background:#fff;border:1px solid var(--line);border-radius:12px;padding:19px;min-width:0;box-shadow:0 2px 8px rgba(15,23,42,.05)}.panel h2{margin:0 0 14px;color:var(--primary);font-size:1.25rem}.leaderboard{grid-column:1/-1}.flight-row{display:grid;grid-template-columns:42px minmax(0,1fr) auto;align-items:center;gap:12px;padding:12px 0;border-top:1px solid #edf1f5}.flight-rank{width:34px;height:34px;display:grid;place-items:center;border-radius:9px;background:#edf2f8;color:var(--primary);font-weight:800}.flight-name{font-weight:700;color:#1e293b}.flight-meta{font-size:.82rem;color:#64748b}.flight-points{font-weight:800;color:var(--primary);white-space:nowrap}.empty{padding:16px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:9px;color:#64748b}.competition{padding:13px 0;border-top:1px solid #edf1f5}.competition:first-of-type{border-top:0}.competition h3{font-size:1rem;margin:0 0 4px;color:#1e293b}.competition p{margin:5px 0;color:#526174;white-space:pre-line}.meta{font-size:.82rem;color:#64748b}.result{padding:14px 0;border-top:1px solid #edf1f5}.result:first-of-type{border-top:0}.result-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.result h3{margin:0;color:#1e293b;font-size:1rem}.result-points{font-weight:800;white-space:nowrap;color:var(--primary)}.result p{margin:6px 0;color:#526174;white-space:pre-line}.note{font-size:.85rem;color:#64748b;margin-top:13px}.footer{margin:22px 0;text-align:center}.footer a{color:var(--primary);margin:0 8px}@media(max-width:760px){.layout{grid-template-columns:1fr}.leaderboard{grid-column:auto}.hero-logo{width:54px;height:54px}}@media(max-width:500px){main{padding:16px 12px 35px}.panel{padding:15px}.flight-row{grid-template-columns:36px minmax(0,1fr) auto;gap:8px}.flight-points{font-size:.92rem}}
</style>
</head>
<body>
<?php require_once __DIR__ . '/site-header.php'; renderGroupSiteHeader(false); ?>
<header class="hero"><div class="hero-inner">
<?php if (!empty($squadron['icon_filename'])): ?><img class="hero-logo" src="<?php echo squadronPageEscape(iconUrl($squadron['icon_filename'])); ?>" alt="<?php echo squadronPageEscape($squadron['name']); ?> logo"><?php endif; ?>
<div class="eyebrow">USAFA · Group 1 Squadron Page</div>
<h1>Squadron <?php echo (int)$squadron['id']; ?> · <?php echo squadronPageEscape($squadron['name']); ?></h1>
<p><?php echo squadronPageEscape($squadron['description'] ?: 'Flight standings and published competition results for this squadron.'); ?></p>
<div class="breadcrumbs"><a href="squadron-pages.php">← All Squadron Pages</a></div>
</div></header>
<main>
<div class="layout">
<section class="panel leaderboard">
<h2>Flight vs Flight Leaderboard</h2>
<?php if (!$flights): ?>
<div class="empty">Flights have not been configured for this squadron yet. Check back after an administrator adds the flight roster.</div>
<?php else: ?>
<?php foreach ($flights as $index => $flight): ?>
<div class="flight-row">
<div class="flight-rank"><?php echo $index + 1; ?></div>
<div><div class="flight-name"><?php echo squadronPageEscape($flight['name']); ?></div><div class="flight-meta"><?php echo (int)$flight['result_count']; ?> published <?php echo (int)$flight['result_count'] === 1 ? 'result' : 'results'; ?></div></div>
<div class="flight-points"><?php echo squadronPagePoints($flight['total_points']); ?> pts</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
<p class="note">Participation is optional. Only published results from this squadron's competitions count toward these standings. Flight points do not affect Group 1 OPA, intramurals, or group event scoring.</p>
</section>

<section class="panel">
<h2>Published Competitions</h2>
<?php if (!$competitions): ?>
<div class="empty">No competitions have been published for this squadron yet.</div>
<?php else: foreach ($competitions as $competition): ?>
<article class="competition">
<h3><?php echo squadronPageEscape($competition['title']); ?></h3>
<p class="meta"><?php echo squadronPageDate($competition['starts_at']); ?> – <?php echo squadronPageDate($competition['ends_at']); ?></p>
<?php if (trim($competition['description']) !== ''): ?><p><?php echo nl2br(squadronPageEscape($competition['description'])); ?></p><?php endif; ?>
<p class="meta"><?php echo (int)$competition['result_count']; ?> published results</p>
</article>
<?php endforeach; endif; ?>
</section>

<section class="panel">
<h2>Recent Results &amp; Awards</h2>
<?php if (!$recentResults): ?>
<div class="empty">Published results and awards will appear here.</div>
<?php else: foreach ($recentResults as $result): ?>
<article class="result">
<div class="result-head"><h3><?php echo squadronPageEscape(trim($result['title']) !== '' ? $result['title'] : $result['flight_name']); ?></h3><span class="result-points"><?php echo ((float)$result['points'] > 0 ? '+' : '') . squadronPagePoints($result['points']); ?> pts</span></div>
<p><?php echo nl2br(squadronPageEscape($result['description'])); ?></p>
<div class="meta"><?php echo squadronPageEscape($result['flight_name']); ?> · <?php echo squadronPageEscape($result['competition_title']); ?> · <?php echo squadronPageDate($result['awarded_at']); ?></div>
</article>
<?php endforeach; endif; ?>
</section>
</div>
<div class="footer"><a href="squadron-pages.php">All Squadron Pages</a><a href="meet-the-group.php">Meet the Group</a><a href="index.php">Group Tracker</a></div>
</main>
</body>
</html>
