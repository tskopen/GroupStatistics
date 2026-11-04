<?php
/**
 * Shared Group 1 brand header. Include after config.php and theme-loader.php,
 * then call renderGroupSiteHeader() inside the page body.
 */
if (!function_exists('renderGroupSiteHeader')) {
    function renderGroupSiteHeader(?bool $showNavigation = null): void {
        // Default to the public navigation on visitor pages and hide it on every admin route.
        $currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if ($showNavigation === null) {
            $showNavigation = strpos($currentPage, 'admin-') !== 0;
        }
        // Load theme utilities even on pages (including some admin screens) that
        // do not include theme-loader.php themselves.
        if (!function_exists('loadTheme')) {
            require_once __DIR__ . '/theme-loader.php';
        }
        $theme = function_exists('loadTheme') ? loadTheme() : [];
        $groupIconSettings = function_exists('readJson') && defined('DATA_DIR')
            ? readJson(DATA_DIR . '/group-icon.json') : [];
        $groupIconFilename = $groupIconSettings['filename'] ?? null;
        $themeSquadron = null;
        if (function_exists('getDb')) {
            try {
                $db = getDb();
                if (!empty($theme['selected_squadron_id'])) {
                    $stmt = $db->prepare('SELECT id, name, icon_filename FROM squadrons WHERE id = ? LIMIT 1');
                    $stmt->execute([(int)$theme['selected_squadron_id']]);
                    $themeSquadron = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                // If no selected squadron exists (or it has no uploaded icon),
                // use the first squadron with an icon so the right-hand brand
                // slot remains populated consistently on public and admin pages.
                if (empty($themeSquadron['icon_filename'])) {
                    $stmt = $db->query('SELECT id, name, icon_filename FROM squadrons WHERE icon_filename IS NOT NULL AND TRIM(icon_filename) <> \'\' ORDER BY id LIMIT 1');
                    $fallbackSquadron = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    if ($fallbackSquadron) $themeSquadron = $fallbackSquadron;
                }
            } catch (Throwable $e) {
                $themeSquadron = null;
            }
        }
        $groupIconUrl = $groupIconFilename && function_exists('iconUrl') ? iconUrl($groupIconFilename) : null;
        $squadronIconUrl = !empty($themeSquadron['icon_filename']) && function_exists('iconUrl')
            ? iconUrl($themeSquadron['icon_filename']) : null;
        ?>
        <div class="site-header" role="banner" aria-label="USAFA Group 1 Squadron Tracker">
            <div class="site-header-mark" aria-label="Group 1 icon">
                <?php if ($groupIconUrl): ?>
                    <img src="<?php echo htmlspecialchars($groupIconUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Group 1 logo">
                <?php else: ?><span aria-hidden="true">G1</span><?php endif; ?>
            </div>
            <h1 class="site-header-title">USAFA Group 1 Squadron Tracker</h1>
            <div class="site-header-squadron" aria-label="<?php echo htmlspecialchars($themeSquadron['name'] ?? 'Selected squadron logo', ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($squadronIconUrl): ?>
                    <img src="<?php echo htmlspecialchars($squadronIconUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars(($themeSquadron['name'] ?? 'Squadron') . ' logo', ENT_QUOTES, 'UTF-8'); ?>">
                <?php else: ?><span class="site-header-squadron-placeholder" aria-hidden="true"></span><?php endif; ?>
            </div>
            <?php if ($showNavigation): ?>
            <nav class="site-header-nav" aria-label="Primary page navigation">
                <a href="index.php" class="site-header-install-button"<?php echo $currentPage === 'index.php' ? ' aria-current="page"' : ''; ?>>Home</a>
                <a href="meet-the-group.php"<?php echo $currentPage === 'meet-the-group.php' ? ' aria-current="page"' : ''; ?>>Meet the Group</a>
                <a href="squadron-pages.php"<?php echo $currentPage === 'squadron-pages.php' ? ' aria-current="page"' : ''; ?>>Squadron Pages</a>
                <a href="announcements.php"<?php echo $currentPage === 'announcements.php' ? ' aria-current="page"' : ''; ?>>📣 Group Announcements</a>
                <a href="intramural-standings.php"<?php echo $currentPage === 'intramural-standings.php' ? ' aria-current="page"' : ''; ?>>Intramurals</a>
                <a href="cadet-of-month.php"<?php echo $currentPage === 'cadet-of-month.php' ? ' aria-current="page"' : ''; ?>>⭐ Cadet of the Month</a>
                <a href="challenges.php"<?php echo $currentPage === 'challenges.php' ? ' aria-current="page"' : ''; ?>>🎯 Group Challenges</a>
                <a href="awards.php"<?php echo $currentPage === 'awards.php' ? ' aria-current="page"' : ''; ?>>🏅 Challenge Awards</a>
                <a href="score-history.php"<?php echo $currentPage === 'score-history.php' ? ' aria-current="page"' : ''; ?>>OPA History</a>
                <a href="notification-preferences.php"<?php echo $currentPage === 'notification-preferences.php' ? ' aria-current="page"' : ''; ?>>🔔 Notifications</a>
                <button type="button" id="pwa-install-button" class="site-header-install-button" hidden>⬇ Install App</button>
                <div id="pwa-install-help" class="site-header-install-help" role="tooltip" hidden></div>
            </nav>
            <script src="pwa-install.js" defer></script>
            <?php endif; ?>
        </div>
        <?php
    }
}
