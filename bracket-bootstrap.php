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
/* Grid rows no longer stretch every card to the tallest card beside it. */
.events-grid { align-items: start; }
.events-grid > * { align-self: start; height: max-content; }
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
.match-points { grid-column: 1 / -1; margin-top: 0; white-space: nowrap; }
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
.event-card:not(.regular-event) .event-body > div > strong { flex-shrink: 0; font-size: 1.1em; }
.score-details-modal {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(0,0,0,.55);
}
.score-details-modal.is-open { display: flex; }
.score-details-panel {
    width: min(680px, 100%);
    max-height: min(760px, 90vh);
    overflow: auto;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 12px 40px rgba(0,0,0,.3);
    padding: 22px;
}
.score-details-header { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:16px; }
.score-details-header h3 { margin:0; color:#002147; }
.score-details-close { border:0; background:transparent; font-size:1.7em; cursor:pointer; line-height:1; }
.score-details-total { font-size:1.25em; font-weight:700; margin-bottom:16px; }
.score-details-category { border:1px solid #e2e6ea; border-radius:8px; margin-bottom:12px; overflow:hidden; }
.score-details-category-header { display:flex; justify-content:space-between; gap:12px; padding:11px 13px; background:#f4f6f8; font-weight:700; }
.score-details-items { padding:0 13px; }
.score-details-item { display:flex; justify-content:space-between; gap:12px; padding:9px 0; border-bottom:1px solid #eee; }
.score-details-item:last-child { border-bottom:0; }
.score-details-item-name { min-width:0; overflow-wrap:anywhere; }
.score-details-item-points { flex-shrink:0; font-weight:700; }
.score-details-loading, .score-details-error { padding:20px 0; text-align:center; }
@media (max-width: 600px) {
    .tournament-match { grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); gap: 6px; }
    .match-score-block { min-width: 92px; column-gap: 5px; padding: 0 3px; }
    .match-team-icon { width: 38px; height: 38px; }
    .score-details-modal { padding: 10px; }
    .score-details-panel { max-height: 94vh; padding: 16px; }
}
</style>
CSS;

        $payload = '<script id="homepage-card-layout-data">window.__GROUP_STATS_BRACKET_ROUNDS=' . json_encode($roundQueues, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';window.__GROUP_STATS_SQUADRON_ICONS=' . json_encode($icons, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
        $payload .= <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Build round sections without changing or re-parenting individual matchup
    // internals. This restores the old per-round presentation and fixes the
    // previously malformed final matchup.
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

    // Preserve the consistent squadron-row treatment on grouped event cards.
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

    // Restore the ranking Details button. The breakdown is loaded from the
    // same events/intramural tables used by the leaderboard calculation, so
    // the displayed contributors cannot drift from the actual total.
    var modal = document.createElement('div');
    modal.className = 'score-details-modal';
    modal.innerHTML = '<div class="score-details-panel" role="dialog" aria-modal="true" aria-labelledby="score-details-title">' +
        '<div class="score-details-header"><h3 id="score-details-title">Score Details</h3><button type="button" class="score-details-close" aria-label="Close">×</button></div>' +
        '<div class="score-details-content"><div class="score-details-loading">Loading…</div></div></div>';
    document.body.appendChild(modal);
    var content = modal.querySelector('.score-details-content');
    var close = function () { modal.classList.remove('is-open'); };
    modal.querySelector('.score-details-close').addEventListener('click', close);
    modal.addEventListener('click', function (event) { if (event.target === modal) close(); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape') close(); });

    document.querySelectorAll('.details-btn').forEach(function (button) {
        button.addEventListener('click', async function () {
            var cells = button.closest('tr') ? button.closest('tr').querySelectorAll('td') : [];
            var squadronCell = cells.length > 1 ? cells[1] : null;
            var squadron = squadronCell ? squadronCell.textContent.trim() : '';
            if (!squadron) return;
            modal.classList.add('is-open');
            content.innerHTML = '<div class="score-details-loading">Loading…</div>';
            try {
                var response = await fetch('score-details.php?squadron=' + encodeURIComponent(squadron), { cache: 'no-store' });
                var data = await response.json();
                if (!response.ok) throw new Error(data.error || 'Unable to load score details');

                var html = '<div class="score-details-total">' + escapeHtml(data.squadron) + ': ' + formatPoints(data.total) + ' pts</div>';
                var categories = data.categories || {};
                Object.keys(categories).forEach(function (category) {
                    var group = categories[category];
                    html += '<section class="score-details-category"><div class="score-details-category-header"><span>' + escapeHtml(category) + '</span><span>' + formatPoints(group.points) + ' pts</span></div><div class="score-details-items">';
                    (group.items || []).forEach(function (item) {
                        var suffix = item.record ? ' <span style="color:#667">(' + escapeHtml(item.record) + ')</span>' : '';
                        html += '<div class="score-details-item"><span class="score-details-item-name">' + escapeHtml(item.name) + suffix + '</span><span class="score-details-item-points">' + formatPoints(item.points) + ' pts</span></div>';
                    });
                    html += '</div></section>';
                });
                if (!Object.keys(categories).length) html += '<div class="score-details-error">No score contributions recorded.</div>';
                content.innerHTML = html;
            } catch (error) {
                content.innerHTML = '<div class="score-details-error">' + escapeHtml(error.message || 'Unable to load score details.') + '</div>';
            }
        });
    });

    function formatPoints(value) {
        var number = Number(value || 0);
        return Number.isInteger(number) ? String(number) : number.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
    }
    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (character) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character];
        });
    }
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