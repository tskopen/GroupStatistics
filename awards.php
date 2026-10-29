<?php
require __DIR__ . '/config.php';
require __DIR__ . '/theme-loader.php';
$theme = loadTheme();
$db = getDb();
function publicAwardEscape($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$awards = $db->query("SELECT * FROM engagement_awards WHERE status='published' ORDER BY created_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
$recipientsByAward = [];
$photosByAward = [];
if ($awards) {
    $ids = array_map(static fn($a) => (int)$a['id'], $awards);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT r.award_id, s.name AS squadron_name, r.points_awarded FROM engagement_award_recipients r JOIN squadrons s ON s.id=r.squadron_id WHERE r.award_id IN ($marks) ORDER BY s.id");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $recipientsByAward[(int)$row['award_id']][] = $row;
    $stmt = $db->prepare("SELECT id, award_id, alt_text FROM engagement_award_photos WHERE award_id IN ($marks) ORDER BY id");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $photosByAward[(int)$row['award_id']][] = $row;
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="<?php echo publicAwardEscape($theme['primary_color']); ?>"><title>Challenge Awards | GroupStatistics</title>
<style>:root{--primary:<?php echo publicAwardEscape($theme['primary_color']); ?>;--secondary:<?php echo publicAwardEscape($theme['secondary_color']); ?>;--accent:<?php echo publicAwardEscape($theme['accent_color']); ?>;--page:<?php echo publicAwardEscape($theme['background_color']); ?>;--ink:<?php echo publicAwardEscape($theme['text_color']); ?>;--surface:color-mix(in srgb,var(--page),white 92%);--line:color-mix(in srgb,var(--primary),var(--page) 78%);--soft:color-mix(in srgb,var(--accent),white 88%)}*{box-sizing:border-box}body{margin:0;background:var(--page);color:var(--ink);font:16px/1.55 Arial,sans-serif}.hero{background:linear-gradient(120deg,var(--primary),var(--secondary));color:#fff;padding:28px 18px}.hero-inner,main{max-width:1100px;margin:auto}.hero h1{margin:0;font-size:1.8rem}.hero p{margin:6px 0 0;color:#d7e4f2}.links{margin-top:14px;display:flex;gap:9px;flex-wrap:wrap}.links a{color:white;text-decoration:none;border:1px solid #ffffff60;border-radius:8px;padding:8px 11px;font-weight:bold}.wrap{padding:24px 14px 50px}.award{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:22px;margin-bottom:18px;box-shadow:0 4px 14px #0f172a08}.award h2{margin:0;color:var(--primary);font-size:1.3rem}.desc{white-space:pre-line;overflow-wrap:anywhere}.meta{color:#64748b;font-size:.88rem;margin:8px 0 12px}.recipients{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.recipient{background:var(--soft);color:var(--primary);border-radius:999px;padding:6px 10px;font-size:.9rem;font-weight:bold}.photos{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-top:16px}.photos a{display:block}.photos img{display:block;width:100%;height:190px;object-fit:cover;border-radius:9px;border:1px solid #dbe3ed}.teams{display:inline-block;margin-top:12px;color:var(--secondary);font-weight:bold}.empty{background:var(--surface);border:1px dashed var(--line);border-radius:12px;padding:35px;text-align:center;color:#64748b}@media(max-width:520px){.award{padding:15px}.hero h1{font-size:1.45rem}.photos{grid-template-columns:repeat(2,minmax(0,1fr))}.photos img{height:140px}}</style></head><body>
<header class="hero"><div class="hero-inner"><h1>🏅 Challenge Awards</h1><p>Recognizing teamwork, participation, creativity, and accomplishments across Group 1.</p><nav class="links"><a href="index.php">← GroupStatistics home</a><a href="announcements.php">Group announcements</a></nav></div></header>
<main class="wrap">
<?php if (!$awards): ?><div class="empty"><strong>No awards have been published yet.</strong><br>Check back for challenge recognition and squadron accomplishments.</div><?php endif; ?>
<?php foreach ($awards as $award): $id=(int)$award['id']; $recipients=$recipientsByAward[$id]??[]; $photos=$photosByAward[$id]??[]; ?>
<article class="award" id="award-<?php echo $id; ?>">
<h2><?php echo publicAwardEscape($award['title']); ?></h2>
<div class="meta"><?php echo publicAwardEscape((new DateTimeImmutable($award['created_at']))->format('F j, Y')); ?> · <?php echo in_array($award['award_type'], ['points','both'], true) ? publicAwardEscape((string)$award['points_awarded']).' engagement points per recipient squadron' : ($award['award_type']==='completion' ? 'Completion recognition' : 'Descriptive recognition'); ?></div>
<p class="desc"><?php echo nl2br(publicAwardEscape($award['description'])); ?></p>
<?php if ($recipients): ?><h3>Recognized squadrons</h3><div class="recipients"><?php foreach ($recipients as $recipient): ?><span class="recipient"><?php echo publicAwardEscape($recipient['squadron_name']); ?><?php if (in_array($award['award_type'], ['points','both'], true) && $recipient['points_awarded'] !== null): ?> · <?php echo publicAwardEscape((string)$recipient['points_awarded']); ?> pts<?php endif; ?></span><?php endforeach; ?></div><?php endif; ?>
<?php if ($photos): ?><div class="photos"><?php foreach ($photos as $photo): ?><a href="award-image.php?id=<?php echo (int)$photo['id']; ?>"><img loading="lazy" src="award-image.php?id=<?php echo (int)$photo['id']; ?>" alt="<?php echo publicAwardEscape($photo['alt_text'] ?: ($award['title'].' photo')); ?>"></a><?php endforeach; ?></div><?php endif; ?>
<?php if (!empty($award['teams_url'])): ?><a class="teams" href="<?php echo publicAwardEscape($award['teams_url']); ?>" target="_blank" rel="noopener noreferrer">Open Teams evidence channel/post ↗</a><?php endif; ?>
</article>
<?php endforeach; ?>
</main></body></html>
