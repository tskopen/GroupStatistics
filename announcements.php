<?php
require __DIR__ . '/config.php';
require __DIR__ . '/theme-loader.php';
$theme = loadTheme();

$db = getDb();
$eventTimezone = new DateTimeZone('America/Denver');
$now = new DateTimeImmutable('now', $eventTimezone);
$announcements = $db->query(
    "SELECT * FROM group_announcements WHERE status = 'published'
     ORDER BY CASE WHEN start_at IS NULL THEN 1 ELSE 0 END, start_at ASC, created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$upcoming = [];
$ongoing = [];
$past = [];
$undated = [];

foreach ($announcements as $announcement) {
    $start = !empty($announcement['start_at']) ? new DateTimeImmutable($announcement['start_at'], $eventTimezone) : null;
    $end = !empty($announcement['end_at']) ? new DateTimeImmutable($announcement['end_at'], $eventTimezone) : null;
    if ($start === null) {
        $undated[] = $announcement;
    } elseif ($start > $now) {
        $upcoming[] = $announcement;
    } elseif ($end !== null && $end >= $now) {
        $ongoing[] = $announcement;
    } else {
        $past[] = $announcement;
    }
}

function announcementEscape($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function announcementDateLabel(?string $value): string {
    if (!$value) return '';
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('America/Denver')))->format('D, M j, Y \\a\\t g:i A T');
    } catch (Throwable $e) {
        return $value;
    }
}
function renderAnnouncementCard(array $announcement, bool $showDate = true): void {
    $title = announcementEscape($announcement['title']);
    $description = nl2br(announcementEscape($announcement['description']));
    $location = trim((string)($announcement['location'] ?? ''));
    $link = trim((string)($announcement['link_url'] ?? ''));
    echo '<article class="announcement-card">';
    echo '<div class="card-content"><h3>' . $title . '</h3>';
    if ($showDate && !empty($announcement['start_at'])) {
        echo '<p class="event-date">' . announcementEscape(announcementDateLabel($announcement['start_at'])) . '</p>';
        $countdownStart = new DateTimeImmutable($announcement['start_at'], new DateTimeZone('America/Denver'));
        echo '<p class="event-countdown" data-countdown-target="' . (int)$countdownStart->getTimestamp() . '">Countdown loading…</p>';
        if (!empty($announcement['end_at'])) {
            echo '<p class="event-end">Ends ' . announcementEscape(announcementDateLabel($announcement['end_at'])) . '</p>';
        }
    } elseif ($showDate) {
        echo '<p class="event-date">Date to be announced</p>';
    }
    if ($location !== '') echo '<p class="event-location">📍 ' . announcementEscape($location) . '</p>';
    echo '<div class="description">' . $description . '</div>';
    if ($link !== '' && filter_var($link, FILTER_VALIDATE_URL) && strtolower((string)parse_url($link, PHP_URL_SCHEME)) === 'https') {
        echo '<a class="event-link" href="' . announcementEscape($link) . '" target="_blank" rel="noopener noreferrer">Open event details ↗</a>';
    }
    echo '</div></article>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?php echo htmlspecialchars($theme['primary_color'], ENT_QUOTES, 'UTF-8'); ?>">
<title>Group Announcements | USAFA Group 1 Squadron Tracker</title>
<style>
:root{--navy:<?php echo htmlspecialchars($theme['primary_color']); ?>;--blue:<?php echo htmlspecialchars($theme['secondary_color']); ?>;--accent:<?php echo htmlspecialchars($theme['accent_color']); ?>;--ink:<?php echo htmlspecialchars($theme['text_color']); ?>;--muted:color-mix(in srgb,<?php echo htmlspecialchars($theme['text_color']); ?> 58%,<?php echo htmlspecialchars($theme['background_color']); ?>);--line:color-mix(in srgb,var(--navy),var(--page) 78%);--page:<?php echo htmlspecialchars($theme['background_color']); ?>}
*{box-sizing:border-box}body{margin:0;background:var(--page);color:var(--ink);font-family:Arial,Helvetica,sans-serif;line-height:1.55}
header{background:linear-gradient(120deg,var(--navy),var(--blue));color:#fff;padding:30px 20px}
.header-inner,.container{max-width:1080px;margin:0 auto}.eyebrow{text-transform:uppercase;letter-spacing:.13em;font-size:.76rem;font-weight:700;opacity:.8}
header h1{font-size:clamp(1.8rem,4vw,2.6rem);margin:7px 0}header p{margin:0;max-width:650px;color:#e4edf7}
.top-links{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.top-links a{color:#fff;text-decoration:none;border:1px solid #ffffff70;padding:8px 12px;border-radius:8px;font-weight:700}
.container{padding:24px 18px 50px}.section-heading{display:flex;align-items:end;justify-content:space-between;gap:12px;margin:28px 0 14px}
.section-heading h2{margin:0;color:var(--navy);font-size:1.35rem}.section-heading p{margin:0;color:var(--muted);font-size:.9rem}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:15px}
.announcement-card{background:color-mix(in srgb,var(--page),white 92%);border:1px solid var(--line);border-radius:14px;box-shadow:0 4px 14px #0f172a0b;overflow:hidden;border-top:4px solid var(--accent)}
.card-content{padding:19px}.announcement-card h3{margin:0 0 7px;color:var(--navy);font-size:1.16rem;overflow-wrap:anywhere}
.event-date{margin:0 0 3px;color:var(--blue);font-weight:700}.event-countdown{display:inline-block;margin:5px 0 8px;padding:7px 10px;border-radius:8px;background:color-mix(in srgb,var(--accent) 12%,white);border:1px solid color-mix(in srgb,var(--accent) 30%,white);color:var(--navy);font-size:.92rem;font-weight:800;font-variant-numeric:tabular-nums}.event-countdown.is-started{background:color-mix(in srgb,var(--accent) 12%,white);border-color:var(--accent);color:var(--navy)}.event-end{margin:0 0 8px;color:var(--muted);font-size:.88rem}.event-location{margin:8px 0;color:var(--ink);font-weight:600}
.description{margin-top:10px;overflow-wrap:anywhere}.event-link{display:inline-block;margin-top:15px;color:var(--blue);font-weight:700;text-decoration:none}.event-link:hover{text-decoration:underline}
.empty{background:color-mix(in srgb,var(--page),white 92%);border:1px dashed var(--line);border-radius:12px;padding:24px;color:var(--muted);text-align:center}
details.past{margin-top:28px;background:color-mix(in srgb,var(--page),white 92%);border:1px solid var(--line);border-radius:12px;padding:16px}details.past summary{font-weight:700;color:var(--navy);cursor:pointer}
@media(max-width:600px){header{padding:24px 17px}.container{padding:14px 12px 35px}.section-heading{align-items:start;flex-direction:column}.card-content{padding:16px}}
</style>
</head>
<body>
<header><div class="header-inner">
<div class="eyebrow">USAFA · Group 1</div>
<h1>📣 Group Announcements</h1>
<p>One place for upcoming group events, important dates, locations, and event details. All event times are Mountain Time (America/Denver).</p>
<div class="top-links"><a href="index.php">← Squadron Tracker</a><a href="admin-login.php">Admin Login</a></div>
</div></header>
<main class="container">
<?php if (!$announcements): ?>
<div class="empty">There are no published announcements right now. Check back for upcoming group events.</div>
<?php endif; ?>

<?php if ($ongoing): ?>
<section><div class="section-heading"><h2>Happening Now</h2><p>Currently active events</p></div><div class="cards"><?php foreach ($ongoing as $item) renderAnnouncementCard($item); ?></div></section>
<?php endif; ?>

<?php if ($upcoming): ?>
<section><div class="section-heading"><h2>Upcoming Events</h2><p>Mark your calendar</p></div><div class="cards"><?php foreach ($upcoming as $item) renderAnnouncementCard($item); ?></div></section>
<?php endif; ?>

<?php if ($undated): ?>
<section><div class="section-heading"><h2>Announcements</h2><p>Details without a scheduled date</p></div><div class="cards"><?php foreach ($undated as $item) renderAnnouncementCard($item); ?></div></section>
<?php endif; ?>

<?php if ($past): ?>
<details class="past"><summary>Past events (<?php echo count($past); ?>)</summary><div class="cards" style="margin-top:16px"><?php foreach (array_reverse($past) as $item) renderAnnouncementCard($item); ?></div></details>
<?php endif; ?>
</main>
<script>
(function () {
    var countdowns = Array.prototype.slice.call(document.querySelectorAll('[data-countdown-target]'));
    function updateCountdowns() {
        var now = Math.floor(Date.now() / 1000);
        countdowns.forEach(function (element) {
            var target = parseInt(element.getAttribute('data-countdown-target'), 10);
            var remaining = target - now;
            if (!Number.isFinite(target)) return;
            if (remaining <= 0) {
                element.textContent = 'Event has started';
                element.classList.add('is-started');
                return;
            }
            var days = Math.floor(remaining / 86400);
            var hours = Math.floor((remaining % 86400) / 3600);
            var minutes = Math.floor((remaining % 3600) / 60);
            var seconds = remaining % 60;
            var time = [hours, minutes, seconds].map(function (part) {
                return String(part).padStart(2, '0');
            }).join(':');
            element.textContent = 'Starts in ' + (days ? days + 'd ' : '') + time;
        });
    }
    updateCountdowns();
    if (countdowns.length) window.setInterval(updateCountdowns, 1000);
})();
</script>
</body>
</html>
