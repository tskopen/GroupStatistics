<?php
session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/theme-loader.php';
$theme = loadTheme();
if (empty($_SESSION['admin'])) { header('Location: admin-login.php'); exit; }

$db = getDb();
$username = (string)($_SESSION['username'] ?? 'admin');
$error = '';
$notice = trim((string)($_GET['notice'] ?? ''));

function flightAdminEscape($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function flightAdminFindSquadron(PDO $db, int $id): ?array {
    $stmt = $db->prepare('SELECT id,name FROM squadrons WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
function flightAdminParseDate(string $value): string {
    $zone = new DateTimeZone('America/Denver');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $zone);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d\TH:i') !== $value) {
        throw new RuntimeException('Enter a valid Mountain Time date and time.');
    }
    return $date->format('Y-m-d H:i:sP');
}
function flightAdminDateInput(?string $value): string {
    if (!$value) return '';
    try { return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('America/Denver'))->format('Y-m-d\TH:i'); }
    catch (Throwable $e) { return ''; }
}
function flightAdminDateLabel(?string $value): string {
    if (!$value) return 'Not scheduled';
    try { return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('America/Denver'))->format('M j, Y · g:i A T'); }
    catch (Throwable $e) { return (string)$value; }
}
function flightAdminRedirect(int $squadronId, string $notice): void {
    header('Location: admin-flight-competition.php?' . http_build_query(['squadron_id' => $squadronId, 'notice' => $notice]));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $squadronId = (int)($_POST['squadron_id'] ?? 0);
    try {
        if (!flightAdminFindSquadron($db, $squadronId)) throw new RuntimeException('Choose a valid squadron.');
        $action = (string)($_POST['action'] ?? '');
        $now = date('c');

        if ($action === 'save_flight') {
            $flightId = (int)($_POST['flight_id'] ?? 0);
            $name = trim((string)($_POST['flight_name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 80) throw new RuntimeException('Enter a flight name up to 80 characters.');
            if ($flightId > 0) {
                $stmt = $db->prepare('UPDATE flights SET name=?,updated_at=? WHERE id=? AND squadron_id=?');
                $stmt->execute([$name, $now, $flightId, $squadronId]);
                if (!$stmt->rowCount() && !$db->prepare('SELECT 1 FROM flights WHERE id=? AND squadron_id=?')->execute([$flightId, $squadronId])) {
                    throw new RuntimeException('Flight not found for this squadron.');
                }
            } else {
                $order = (int)$db->query('SELECT COALESCE(MAX(display_order),0)+1 FROM flights WHERE squadron_id=' . $squadronId)->fetchColumn();
                $stmt = $db->prepare('INSERT INTO flights(squadron_id,name,display_order,is_active,created_at,updated_at) VALUES(?,?,?,1,?,?)');
                $stmt->execute([$squadronId, $name, $order, $now, $now]);
            }
            flightAdminRedirect($squadronId, 'flight_saved');
        }

        if ($action === 'toggle_flight') {
            $flightId = (int)($_POST['flight_id'] ?? 0);
            $stmt = $db->prepare('UPDATE flights SET is_active=CASE WHEN is_active=1 THEN 0 ELSE 1 END,updated_at=? WHERE id=? AND squadron_id=?');
            $stmt->execute([$now, $flightId, $squadronId]);
            flightAdminRedirect($squadronId, 'flight_updated');
        }

        if ($action === 'save_competition') {
            $competitionId = (int)($_POST['competition_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $startsAt = flightAdminParseDate((string)($_POST['starts_at'] ?? ''));
            $endsAt = flightAdminParseDate((string)($_POST['ends_at'] ?? ''));
            $status = (string)($_POST['status'] ?? 'draft');
            if ($title === '' || mb_strlen($title) > 160) throw new RuntimeException('Enter a competition title up to 160 characters.');
            if (mb_strlen($description) > 2000) throw new RuntimeException('Competition descriptions may not exceed 2,000 characters.');
            if (strtotime($endsAt) < strtotime($startsAt)) throw new RuntimeException('The competition end must be on or after its start.');
            if (!in_array($status, ['draft','published'], true)) throw new RuntimeException('Choose a valid competition status.');
            if ($competitionId > 0) {
                $stmt = $db->prepare('UPDATE flight_competitions SET title=?,description=?,starts_at=?,ends_at=?,status=?,updated_at=? WHERE id=? AND squadron_id=?');
                $stmt->execute([$title, $description, $startsAt, $endsAt, $status, $now, $competitionId, $squadronId]);
                $exists = $db->prepare('SELECT 1 FROM flight_competitions WHERE id=? AND squadron_id=?');
                $exists->execute([$competitionId, $squadronId]);
                if (!$exists->fetchColumn()) throw new RuntimeException('Competition not found for this squadron.');
            } else {
                $stmt = $db->prepare('INSERT INTO flight_competitions(squadron_id,title,description,starts_at,ends_at,status,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$squadronId, $title, $description, $startsAt, $endsAt, $status, $username, $now, $now]);
            }
            flightAdminRedirect($squadronId, 'competition_saved');
        }

        if ($action === 'toggle_competition') {
            $competitionId = (int)($_POST['competition_id'] ?? 0);
            $stmt = $db->prepare("UPDATE flight_competitions SET status=CASE WHEN status='published' THEN 'draft' ELSE 'published' END,updated_at=? WHERE id=? AND squadron_id=?");
            $stmt->execute([$now, $competitionId, $squadronId]);
            flightAdminRedirect($squadronId, 'competition_updated');
        }

        if ($action === 'delete_competition') {
            $competitionId = (int)($_POST['competition_id'] ?? 0);
            $stmt = $db->prepare('DELETE FROM flight_competitions WHERE id=? AND squadron_id=?');
            $stmt->execute([$competitionId, $squadronId]);
            flightAdminRedirect($squadronId, 'competition_deleted');
        }

        if ($action === 'save_result') {
            $resultId = (int)($_POST['result_id'] ?? 0);
            $competitionId = (int)($_POST['competition_id'] ?? 0);
            $flightId = (int)($_POST['flight_id'] ?? 0);
            $title = trim((string)($_POST['result_title'] ?? ''));
            $description = trim((string)($_POST['result_description'] ?? ''));
            $pointsRaw = trim((string)($_POST['points'] ?? '0'));
            $points = filter_var($pointsRaw, FILTER_VALIDATE_FLOAT);
            $awardedAt = flightAdminParseDate((string)($_POST['awarded_at'] ?? ''));
            $status = (string)($_POST['result_status'] ?? 'draft');
            $competitionCheck = $db->prepare('SELECT 1 FROM flight_competitions WHERE id=? AND squadron_id=?');
            $competitionCheck->execute([$competitionId, $squadronId]);
            if (!$competitionCheck->fetchColumn()) throw new RuntimeException('Choose a competition belonging to this squadron.');
            $flightCheck = $db->prepare('SELECT is_active FROM flights WHERE id=? AND squadron_id=?');
            $flightCheck->execute([$flightId, $squadronId]);
            $flightActive = $flightCheck->fetchColumn();
            if ($flightActive === false) throw new RuntimeException('Choose a flight belonging to this squadron.');
            if ($resultId === 0 && (int)$flightActive !== 1) throw new RuntimeException('New results can only be assigned to an active flight.');
            if ($title !== '' && mb_strlen($title) > 160) throw new RuntimeException('Result titles may not exceed 160 characters.');
            if ($description === '' || mb_strlen($description) > 2000) throw new RuntimeException('Enter a result description up to 2,000 characters.');
            if ($points === false || !is_finite((float)$points)) throw new RuntimeException('Enter a valid points value.');
            if (!in_array($status, ['draft','published'], true)) throw new RuntimeException('Choose a valid result status.');
            if ($resultId > 0) {
                $stmt = $db->prepare('UPDATE flight_competition_results SET competition_id=?,flight_id=?,title=?,description=?,points=?,awarded_at=?,status=?,updated_at=? WHERE id=? AND squadron_id=?');
                $stmt->execute([$competitionId, $flightId, $title, $description, (float)$points, $awardedAt, $status, $now, $resultId, $squadronId]);
                $exists = $db->prepare('SELECT 1 FROM flight_competition_results WHERE id=? AND squadron_id=?');
                $exists->execute([$resultId, $squadronId]);
                if (!$exists->fetchColumn()) throw new RuntimeException('Result not found for this squadron.');
            } else {
                $stmt = $db->prepare('INSERT INTO flight_competition_results(competition_id,flight_id,squadron_id,title,description,points,awarded_at,status,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$competitionId, $flightId, $squadronId, $title, $description, (float)$points, $awardedAt, $status, $username, $now, $now]);
            }
            flightAdminRedirect($squadronId, 'result_saved');
        }

        if ($action === 'toggle_result') {
            $resultId = (int)($_POST['result_id'] ?? 0);
            $stmt = $db->prepare("UPDATE flight_competition_results SET status=CASE WHEN status='published' THEN 'draft' ELSE 'published' END,updated_at=? WHERE id=? AND squadron_id=?");
            $stmt->execute([$now, $resultId, $squadronId]);
            flightAdminRedirect($squadronId, 'result_updated');
        }

        if ($action === 'delete_result') {
            $resultId = (int)($_POST['result_id'] ?? 0);
            $stmt = $db->prepare('DELETE FROM flight_competition_results WHERE id=? AND squadron_id=?');
            $stmt->execute([$resultId, $squadronId]);
            flightAdminRedirect($squadronId, 'result_deleted');
        }

        throw new RuntimeException('Unknown administration action.');
    } catch (Throwable $e) {
        $error = $e instanceof PDOException && str_contains(strtolower($e->getMessage()), 'unique')
            ? 'A flight with that name already exists in this squadron.'
            : $e->getMessage();
    }
}

$squadrons = $db->query('SELECT id,name FROM squadrons ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$selectedSquadronId = (int)($_GET['squadron_id'] ?? ($squadrons[0]['id'] ?? 0));
if (!flightAdminFindSquadron($db, $selectedSquadronId)) $selectedSquadronId = (int)($squadrons[0]['id'] ?? 0);
$selectedSquadron = $selectedSquadronId ? flightAdminFindSquadron($db, $selectedSquadronId) : null;

$flights = [];
$competitions = [];
$results = [];
$flightForm = null;
$competitionForm = null;
$resultForm = null;
if ($selectedSquadronId) {
    $stmt = $db->prepare('SELECT * FROM flights WHERE squadron_id=? ORDER BY is_active DESC,display_order,name COLLATE NOCASE');
    $stmt->execute([$selectedSquadronId]);
    $flights = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $db->prepare(
        "SELECT c.*,(SELECT COUNT(*) FROM flight_competition_results r WHERE r.competition_id=c.id) result_count
         FROM flight_competitions c WHERE c.squadron_id=? ORDER BY c.starts_at DESC,c.id DESC"
    );
    $stmt->execute([$selectedSquadronId]);
    $competitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $db->prepare(
        "SELECT r.*,f.name AS flight_name,c.title AS competition_title
         FROM flight_competition_results r
         JOIN flights f ON f.id=r.flight_id AND f.squadron_id=r.squadron_id
         JOIN flight_competitions c ON c.id=r.competition_id AND c.squadron_id=r.squadron_id
         WHERE r.squadron_id=? ORDER BY r.awarded_at DESC,r.id DESC"
    );
    $stmt->execute([$selectedSquadronId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $editFlightId = (int)($_GET['edit_flight'] ?? 0);
    if ($editFlightId) {
        $stmt = $db->prepare('SELECT * FROM flights WHERE id=? AND squadron_id=?');
        $stmt->execute([$editFlightId, $selectedSquadronId]);
        $flightForm = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $editCompetitionId = (int)($_GET['edit_competition'] ?? 0);
    if ($editCompetitionId) {
        $stmt = $db->prepare('SELECT * FROM flight_competitions WHERE id=? AND squadron_id=?');
        $stmt->execute([$editCompetitionId, $selectedSquadronId]);
        $competitionForm = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $editResultId = (int)($_GET['edit_result'] ?? 0);
    if ($editResultId) {
        $stmt = $db->prepare('SELECT * FROM flight_competition_results WHERE id=? AND squadron_id=?');
        $stmt->execute([$editResultId, $selectedSquadronId]);
        $resultForm = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

$nowInput = (new DateTimeImmutable('now', new DateTimeZone('America/Denver')))->format('Y-m-d\TH:i');
$noticeLabels = [
    'flight_saved' => 'Flight saved.',
    'flight_updated' => 'Flight updated.',
    'competition_saved' => 'Competition saved.',
    'competition_updated' => 'Competition visibility updated.',
    'competition_deleted' => 'Competition and its results deleted.',
    'result_saved' => 'Result saved.',
    'result_updated' => 'Result visibility updated.',
    'result_deleted' => 'Result deleted.'
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Flight vs Flight Management | GroupStatistics</title>
<link rel="stylesheet" href="site-ui.css">
<link rel="stylesheet" href="site-components.css">
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f6fa;color:#1e293b;font:16px/1.5 Arial,sans-serif}.hero{background:linear-gradient(120deg,#002147,#003b70);color:#fff;padding:23px 18px}.hero-inner,main{max-width:1250px;margin:0 auto}.hero h1{margin:0 0 5px;font-size:clamp(1.6rem,3vw,2.2rem)}.hero p{margin:0;color:#e4edf7}.hero a{color:#fff;margin-right:14px}.eyebrow{font-size:.75rem;letter-spacing:.13em;text-transform:uppercase;font-weight:700;opacity:.85}main{padding:22px 15px 45px}.toolbar{display:flex;justify-content:space-between;align-items:end;gap:12px;flex-wrap:wrap;margin-bottom:17px}.toolbar label{display:block;font-weight:700;margin-bottom:5px}.toolbar select{min-width:260px}.grid{display:grid;grid-template-columns:minmax(280px,.85fr) minmax(0,1.15fr);gap:17px}.panel{background:#fff;border:1px solid #dbe3ed;border-radius:12px;padding:18px;min-width:0;box-shadow:0 2px 8px rgba(15,23,42,.05)}.panel h2{color:#002147;margin:0 0 12px;font-size:1.2rem}label{display:block;font-weight:700;margin:11px 0 5px}input,textarea,select{box-sizing:border-box;width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:7px;font:inherit;background:#fff;color:#1e293b}textarea{min-height:85px;resize:vertical}.field-help,.meta{color:#64748b;font-size:.84rem}.actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:11px}.btn{display:inline-block;border:0;border-radius:7px;padding:8px 12px;background:#002147;color:#fff;text-decoration:none;font-weight:700;font:inherit;cursor:pointer}.btn.secondary{background:#e8eef6;color:#002147}.btn.danger{background:#fff0f0;color:#a21d2a}.inline{display:inline}.alert{padding:11px 13px;background:#eaf8ef;color:#17623a;border-radius:8px;margin-bottom:15px}.alert.error{background:#fff0f0;color:#8d1825}.row{border-top:1px solid #e5eaf0;padding:13px 0}.row:first-of-type{border-top:0}.row h3{margin:0 0 4px;font-size:1rem;color:#1e293b}.row p{margin:5px 0;white-space:pre-line}.status{font-size:.78rem;text-transform:uppercase;font-weight:800;letter-spacing:.04em;color:#64748b}.status.published{color:#17623a}.empty{padding:12px;color:#64748b;background:#f8fafc;border-radius:8px}.result-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:start}.result-points{font-weight:800;color:#002147;white-space:nowrap}.small-form{display:inline}.admin-footer{margin-top:20px}.admin-footer a{color:#002147}@media(max-width:850px){.grid{grid-template-columns:1fr}}@media(max-width:560px){main{padding:15px 11px 35px}.panel{padding:14px}.toolbar select{min-width:0}.result-row{grid-template-columns:1fr}.result-points{grid-row:1}}
</style>
</head>
<body>
<?php require_once __DIR__ . '/site-header.php'; renderGroupSiteHeader(false); ?>
<header class="hero"><div class="hero-inner">
<div class="eyebrow">Administration</div>
<h1>Flight vs Flight Management</h1>
<p>Manage each squadron's flight roster, competitions, point awards, and publication status. Flight points are isolated from all Group 1 scoring systems.</p>
<a href="admin-panel.php">← Admin Panel</a><a href="squadron-pages.php">Public Squadron Pages ↗</a>
</div></header>
<main>
<?php if ($error !== ''): ?><div class="alert error"><?php echo flightAdminEscape($error); ?></div><?php endif; ?>
<?php if (isset($noticeLabels[$notice])): ?><div class="alert"><?php echo flightAdminEscape($noticeLabels[$notice]); ?></div><?php endif; ?>

<?php if (!$selectedSquadron): ?>
<div class="panel"><p>No squadrons are available to manage.</p></div>
<?php else: ?>
<form class="toolbar" method="get">
<div><label for="squadron_id">Manage squadron</label><select id="squadron_id" name="squadron_id" onchange="this.form.submit()">
<?php foreach ($squadrons as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php echo (int)$s['id']===$selectedSquadronId?'selected':''; ?>>Squadron <?php echo (int)$s['id']; ?> · <?php echo flightAdminEscape($s['name']); ?></option><?php endforeach; ?>
</select></div>
<div class="meta">All flights, competitions, and results below are scoped to <?php echo flightAdminEscape($selectedSquadron['name']); ?>.</div>
</form>

<div class="grid">
<section class="panel">
<h2><?php echo $flightForm ? 'Edit Flight' : 'Add a Flight'; ?></h2>
<form method="post">
<input type="hidden" name="action" value="save_flight"><input type="hidden" name="squadron_id" value="<?php echo $selectedSquadronId; ?>"><input type="hidden" name="flight_id" value="<?php echo (int)($flightForm['id']??0); ?>">
<label for="flight_name">Flight name</label><input id="flight_name" name="flight_name" maxlength="80" required value="<?php echo flightAdminEscape($flightForm['name']??''); ?>" placeholder="e.g. Alpha Flight">
<div class="actions"><button class="btn">Save Flight</button><a class="btn secondary" href="admin-flight-competition.php?squadron_id=<?php echo $selectedSquadronId; ?>">New / cancel</a></div>
</form>
<hr>
<h2>Flight Roster</h2>
<?php if (!$flights): ?><div class="empty">No flights configured yet.</div><?php else: foreach ($flights as $flight): ?>
<article class="row">
<h3><?php echo flightAdminEscape($flight['name']); ?> <?php if (!(int)$flight['is_active']): ?><span class="status">Archived</span><?php endif; ?></h3>
<div class="actions"><a class="btn secondary" href="admin-flight-competition.php?squadron_id=<?php echo $selectedSquadronId; ?>&edit_flight=<?php echo (int)$flight['id']; ?>">Edit</a>
<form class="small-form" method="post"><input type="hidden" name="action" value="toggle_flight"><input type="hidden" name="squadron_id" value="<?php echo $selectedSquadronId; ?>"><input type="hidden" name="flight_id" value="<?php echo (int)$flight['id']; ?>"><button class="btn secondary"><?php echo (int)$flight['is_active']?'Archive':'Reactivate'; ?></button></form></div>
</article>
<?php endforeach; endif; ?>
<p class="field-help">Archiving hides a flight from public standings and new result entry while preserving its historical records.</p>
</section>

<section class="panel">
<h2><?php echo $competitionForm ? 'Edit Competition' : 'Create Competition'; ?></h2>
<form method="post">
<input type="hidden" name="action" value="save_competition"><input type="hidden" name="squadron_id" value="<?php echo $selectedSquadronId; ?>"><input type="hidden" name="competition_id" value="<?php echo (int)($competitionForm['id']??0); ?>">
<label for="competition-title">Competition title</label><input id="competition-title" name="title" maxlength="160" required value="<?php echo flightAdminEscape($competitionForm['title']??''); ?>" placeholder="e.g. Fall Flight Challenge">
<label for="competition-description">Competition description</label><textarea id="competition-description" name="description" maxlength="2000" placeholder="Explain the competition and how flights can participate."><?php echo flightAdminEscape($competitionForm['description']??''); ?></textarea>
<label for="starts-at">Start — Mountain Time</label><input id="starts-at" type="datetime-local" name="starts_at" required value="<?php echo flightAdminEscape(flightAdminDateInput($competitionForm['starts_at']??null) ?: $nowInput); ?>">
<label for="ends-at">End — Mountain Time</label><input id="ends-at" type="datetime-local" name="ends_at" required value="<?php echo flightAdminEscape(flightAdminDateInput($competitionForm['ends_at']??null) ?: $nowInput); ?>">
<label for="competition-status">Visibility</label><select id="competition-status" name="status"><option value="draft" <?php echo ($competitionForm['status']??'draft')==='draft'?'selected':''; ?>>Draft — hidden publicly</option><option value="published" <?php echo ($competitionForm['status']??'')==='published'?'selected':''; ?>>Published</option></select>
<div class="actions"><button class="btn">Save Competition</button><a class="btn secondary" href="admin-flight-competition.php?squadron_id=<?php echo $selectedSquadronId; ?>">New / cancel</a></div>
</form>
<hr>
<h2>Competitions</h2>
<?php if (!$competitions): ?><div class="empty">No competitions created yet.</div><?php else: foreach ($competitions as $competition): ?>
<article class="row">
<h3><?php echo flightAdminEscape($competition['title']); ?> · <span class="status <?php echo $competition['status']==='published'?'published':''; ?>"><?php echo flightAdminEscape($competition['status']); ?></span></h3>
<div class="meta"><?php echo flightAdminEscape(flightAdminDateLabel($competition['starts_at'])); ?> – <?php echo flightAdminEscape(flightAdminDateLabel($competition['ends_at'])); ?> · <?php echo (int)$competition['result_count']; ?> results</div>
<?php if ($competition['description']!==''): ?><p><?php echo nl2br(flightAdminEscape($competition['description'])); ?></p><?php endif; ?>
<div class="actions"><a class="btn secondary" href="admin-flight-competition.php?squadron_id=<?php echo $selectedSquadronId; ?>&edit_competition=<?php echo (int)$competition['id']; ?>">Edit</a>
<form class="small-form" method="post"><input type="hidden" name="action" value="toggle_competition"><input type="hidden" name="squadron_id" value="<?php echo $selectedSquadronId; ?>"><input type="hidden" name="competition_id" value="<?php echo (int)$competition['id']; ?>"><button class="btn secondary"><?php echo $competition['status']==='published'?'Unpublish':'Publish'; ?></button></form>
<form class="small-form" method="post" onsubmit="return confirm('Delete this competition and all of its results?');"><input type="hidden" name="action" value="delete_competition"><input type="hidden" name="squadron_id" value="<?php echo $selectedSquadronId; ?>"><input type="hidden" name="competition_id" value="<?php echo (int)$competition['id']; ?>"><button class="btn danger">Delete</button></form></div>
</article>
<?php endforeach; endif; ?>
</section>

<section class="panel" style="grid-column:1/-1">
<h2><?php echo $resultForm ? 'Edit Result / Award' : 'Add Points or an Award'; ?></h2>
<p class="field-help">New results are saved as drafts by default. Publish a result to make it visible on the squadron page and include its points in the flight leaderboard. The parent competition must also be published.</p>
<form method="post">
<input type="hidden" name="action" value="save_result"><input type="hidden" name="squadron_id" value="<?php echo $selectedSquadronId; ?>"><input type="hidden" name="result_id" value="<?php echo (int)($resultForm['id']??0); ?>">
<label for="result-competition">Competition</label><select id="result-competition" name="competition_id" required><option value="">Choose competition</option>
<?php foreach ($competitions as $competition): ?><option value="<?php echo (int)$competition['id']; ?>" <?php echo (int)($resultForm['competition_id']??0)===(int)$competition['id']?'selected':''; ?>><?php echo flightAdminEscape($competition['title']); ?> · <?php echo flightAdminEscape($competition['status']); ?></option><?php endforeach; ?></select>
<label for="result-flight">Flight</label><select id="result-flight" name="flight_id" required><option value="">Choose flight</option>
<?php foreach ($flights as $flight): if (!(int)$flight['is_active'] && (int)($resultForm['flight_id']??0)!==(int)$flight['id']) continue; ?><option value="<?php echo (int)$flight['id']; ?>" <?php echo (int)($resultForm['flight_id']??0)===(int)$flight['id']?'selected':''; ?>><?php echo flightAdminEscape($flight['name']); ?><?php if (!(int)$flight['is_active']): ?> · archived<?php endif; ?></option><?php endforeach; ?></select>
<label for="result-title">Result / award title (optional)</label><input id="result-title" name="result_title" maxlength="160" value="<?php echo flightAdminEscape($resultForm['title']??''); ?>" placeholder="e.g. Inspection Excellence">
<label for="points">Points awarded</label><input id="points" type="number" step="any" name="points" required value="<?php echo flightAdminEscape($resultForm['points']??'0'); ?>">
<label for="result-description">Reason / description</label><textarea id="result-description" name="result_description" maxlength="2000" required><?php echo flightAdminEscape($resultForm['description']??''); ?></textarea>
<label for="awarded-at">Result date &amp; time — Mountain Time</label><input id="awarded-at" type="datetime-local" name="awarded_at" required value="<?php echo flightAdminEscape(flightAdminDateInput($resultForm['awarded_at']??null) ?: $nowInput); ?>">
<label for="result-status">Visibility</label><select id="result-status" name="result_status"><option value="draft" <?php echo ($resultForm['status']??'draft')==='draft'?'selected':''; ?>>Draft — hidden publicly</option><option value="published" <?php echo ($resultForm['status']??'')==='published'?'selected':''; ?>>Published</option></select>
<div class="actions"><button class="btn">Save Result</button><a class="btn secondary" href="admin-flight-competition.php?squadron_id=<?php echo $selectedSquadronId; ?>">New / cancel</a></div>
</form>
<hr>
<h2>Results &amp; Awards</h2>
<?php if (!$results): ?><div class="empty">No results entered yet.</div><?php else: foreach ($results as $result): ?>
<article class="row">
<div class="result-row"><div><h3><?php echo flightAdminEscape($result['title']!==''?$result['title']:$result['flight_name']); ?> · <span class="status <?php echo $result['status']==='published'?'published':''; ?>"><?php echo flightAdminEscape($result['status']); ?></span></h3><div class="meta"><?php echo flightAdminEscape($result['flight_name']); ?> · <?php echo flightAdminEscape($result['competition_title']); ?> · <?php echo flightAdminEscape(flightAdminDateLabel($result['awarded_at'])); ?></div><p><?php echo nl2br(flightAdminEscape($result['description'])); ?></p></div><div class="result-points"><?php echo ((float)$result['points']>0?'+':'') . flightAdminEscape($result['points']); ?> pts</div></div>
<div class="actions"><a class="btn secondary" href="admin-flight-competition.php?squadron_id=<?php echo $selectedSquadronId; ?>&edit_result=<?php echo (int)$result['id']; ?>">Edit</a>
<form class="small-form" method="post"><input type="hidden" name="action" value="toggle_result"><input type="hidden" name="squadron_id" value="<?php echo $selectedSquadronId; ?>"><input type="hidden" name="result_id" value="<?php echo (int)$result['id']; ?>"><button class="btn secondary"><?php echo $result['status']==='published'?'Unpublish':'Publish'; ?></button></form>
<form class="small-form" method="post" onsubmit="return confirm('Delete this result?');"><input type="hidden" name="action" value="delete_result"><input type="hidden" name="squadron_id" value="<?php echo $selectedSquadronId; ?>"><input type="hidden" name="result_id" value="<?php echo (int)$result['id']; ?>"><button class="btn danger">Delete</button></form></div>
</article>
<?php endforeach; endif; ?>
</section>
</div>
<?php endif; ?>
<div class="admin-footer"><a href="admin-panel.php">← Admin Panel</a></div>
</main>
</body>
</html>
