<?php
require_once __DIR__ . '/bracket-schema-migration.php';
require_once __DIR__ . '/bracket-migrate.php';

function bracketBootstrap(PDO $db): void
{
    $db->exec('PRAGMA foreign_keys = ON');
    initBracketTables($db);
    $json = DATA_DIR . '/brackets.json';
    migrateLegacyBrackets($db, $json);

    // Backfill completed bracket matchups into the normal events ledger so
    // existing bracket results contribute to the same leaderboard totals as
    // scores entered through the scoring page. The matchup/event link makes
    // this idempotent and prevents duplicate points.
    try {
        $rows = $db->query("SELECT m.*, b.name AS bracket_name
            FROM bracket_matchups m JOIN brackets b ON b.id=m.bracket_id
            WHERE m.status='completed' AND m.winner_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        $find = $db->prepare('SELECT event_id FROM bracket_score_events WHERE matchup_id=?');
        $insert = $db->prepare('INSERT INTO events(squadron_id,event_type,event_name,value,points_awarded,timestamp,created_at) VALUES(?,?,?,?,?,?,?)');
        $link = $db->prepare('INSERT INTO bracket_score_events(matchup_id,event_id) VALUES(?,?)');
        foreach ($rows as $m) {
            $find->execute([$m['id']]);
            if ($find->fetchColumn()) continue;
            $points=(float)($m['points']??0);
            $now=date('c');
            $insert->execute([(int)$m['winner_id'],'bracket',$m['bracket_name'].' - Match '.$m['match_number'],$points,$points,$m['updated_at']??$now,$now]);
            $link->execute([$m['id'],$db->lastInsertId()]);
        }
    } catch (Throwable $e) {
        error_log('Bracket score-event backfill failed: '.$e->getMessage());
    }

    // The homepage renders the bracket matches as a flat list. Round sections
    // are added in the browser from the relational round metadata rather than
    // by injecting closing tags into already-rendered HTML. This keeps the
    // matchup markup intact and prevents the last matchup from being split.
    static $roundRendererRegistered = false;
    if (!$roundRendererRegistered) {
        ob_start('bracketRenderRoundSections');
        $roundRendererRegistered = true;
    }
}

function bracketRenderRoundSections(string $html): string
{
    if (strpos($html, 'class="tournament-card"') === false) {
        return $html;
    }

    try {
        $db = getDb();
        $rows = $db->query("SELECT id,name FROM brackets ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        $roundQueues = [];
        foreach ($rows as $row) {
            $roundStmt = $db->prepare('SELECT id,name FROM bracket_rounds WHERE bracket_id=? ORDER BY round_number');
            $roundStmt->execute([(string)$row['id']]);
            $rounds = [];
            foreach ($roundStmt->fetchAll(PDO::FETCH_ASSOC) as $round) {
                $matchStmt = $db->prepare('SELECT COUNT(*) FROM bracket_matchups WHERE round_id=?');
                $matchStmt->execute([(int)$round['id']]);
                $count = (int)$matchStmt->fetchColumn();
                if ($count > 0) {
                    $rounds[] = ['name' => $round['name'], 'count' => $count];
                }
            }
            if ($rounds) {
                $roundQueues[] = $rounds;
            }
        }

        $squadronRows = $db->query('SELECT name,icon_filename FROM squadrons ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $icons = [];
        foreach ($squadronRows as $squadron) {
            if (!empty($squadron['name']) && !empty($squadron['icon_filename'])) {
                $icons[$squadron['name']] = iconUrl($squadron['icon_filename']);
            }
        }

        $css = <<<'CSS'
<style id="homepage-card-layout-fix">
.tournament-match {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(110px, auto) minmax(0, 1fr);
    align-items: center;
    gap: 12px;
}
.match-team { min-width: 0; }
.match-team-name { overflow-wrap: anywhere; }
.match-score-block {
    display: grid;
    grid-template-columns: auto auto auto;
    grid-template-rows: auto auto;
    align-items: center;
    justify-items: center;
    column-gap: 8px;
    row-gap: 2px;
    min-width: 110px;
    padding: 0 8px;
    flex-shrink: 0;
}
.match-points {
    grid-column: 1 / -1;
    margin-top: 0;
    white-space: nowrap;
}
.bracket-round-section {
    margin: 0 0 18px;
    padding: 0 0 4px;
    border: 1px solid #e2e6ea;
    border-radius: 8px;
    background: #fff;
}
.bracket-round-title {
    padding: 10px 12px;
    margin: 0 0 10px;
    background: #f1f4f7;
    color: #002147;
    font-size: .9em;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    border-bottom: 1px solid #e2e6ea;
}
.event-card:not(.regular-event) .event-body > div {
    display: flex !important;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    min-height: 45px;
    padding: 10px 12px !important;
    margin-bottom: 10px;
    border-radius: 6px;
    background: #f9f9f9;
}
.event-card:not(.regular-event) .event-body > div:last-child { margin-bottom: 0; }
.event-card:not(.regular-event) .event-body > div > span {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
    font-weight: 700;
}
.event-card:not(.regular-event) .event-body > div > span img {
    width: 45px;
    height: 45px;
    border-radius: 4px;
    object-fit: cover;
    flex-shrink: 0;
}
.event-card:not(.regular-event) .event-body > div > strong {
    flex-shrink: 0;
    font-size: 1.1em;
}
@media (max-width: 600px) {
    .tournament-match { grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); gap: 6px; }
    .match-score-block { min-width: 92px; column-gap: 5px; padding: 0 3px; }
    .match-team-icon { width: 38px; height: 38px; }
}
</style>
CSS;

        $payload = '<script id="homepage-card-layout-data">window.__GROUP_STATS_BRACKET_ROUNDS=' . json_encode($roundQueues, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';window.__GROUP_STATS_SQUADRON_ICONS=' . json_encode($icons, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
        $payload .= <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Build round sections without changing or re-parenting individual matchup
    // internals. This fixes the previously malformed final matchup.
    var queues = window.__GROUP_STATS_BRACKET_ROUNDS || [];
    document.querySelectorAll('.tournament-card').forEach(function (card, cardIndex) {
        if (card.dataset.roundSectionsApplied === '1') return;
        var queue = queues[cardIndex] || [];
        var matches = Array.from(card.querySelectorAll(':scope > .tournament-body > .tournament-match'));
        if (!queue.length || !matches.length) return;

        var body = card.querySelector(':scope > .tournament-body');
        var meta = body ? body.querySelector(':scope > .bracket-meta') : null;
        var anchor = meta ? meta.nextElementSibling : body.firstElementChild;
        var cursor = 0;

        queue.forEach(function (round) {
            var section = document.createElement('div');
            section.className = 'bracket-round-section';
            var title = document.createElement('div');
            title.className = 'bracket-round-title';
            title.textContent = round.name || 'Round';
            section.appendChild(title);

            for (var i = 0; i < Number(round.count || 0) && cursor < matches.length; i++) {
                section.appendChild(matches[cursor++]);
            }
            if (section.children.length > 1) body.insertBefore(section, anchor || null);
        });

        card.dataset.roundSectionsApplied = '1';
    });

    // Bring PFT/OTHER grouped cards up to the same row treatment as the SAMI
    // cards and add the same squadron icon treatment when an icon is available.
    var icons = window.__GROUP_STATS_SQUADRON_ICONS || {};
    document.querySelectorAll('.event-card:not(.regular-event) .event-body > div').forEach(function (row) {
        var name = row.querySelector('span');
        if (!name) return;
        var label = name.textContent.trim();
        var icon = icons[label];
        if (icon && !name.querySelector('img')) {
            var img = document.createElement('img');
            img.src = icon;
            img.alt = '';
            img.loading = 'lazy';
            name.prepend(img);
        }
    });
});
</script>
JS;

        $needle = '</head>';
        $pos = stripos($html, $needle);
        if ($pos === false) return $html;
        return substr($html, 0, $pos) . $css . $payload . substr($html, $pos);
    } catch (Throwable $e) {
        error_log('Homepage card layout renderer failed: ' . $e->getMessage());
        return $html;
    }
}

function bracketSquadrons(): array
{
    $db=getDb();
    $rows=$db->query('SELECT id,name,icon_filename FROM squadrons ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if($rows)return $rows;
    $legacy=readJson(DATA_DIR.'/squadrons.json');
    return is_array($legacy)?$legacy:[];
}