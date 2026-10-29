<?php
/**
 * Shared Group 1 brand header. Include after config.php and theme-loader.php,
 * then call renderGroupSiteHeader() inside the page body.
 */
if (!function_exists('renderGroupSiteHeader')) {
    function renderGroupSiteHeader(): void {
        $theme = function_exists('loadTheme') ? loadTheme() : [];
        $groupIconSettings = function_exists('readJson') && defined('DATA_DIR')
            ? readJson(DATA_DIR . '/group-icon.json') : [];
        $groupIconFilename = $groupIconSettings['filename'] ?? null;
        $themeSquadron = null;
        if (!empty($theme['selected_squadron_id']) && function_exists('getDb')) {
            try {
                $stmt = getDb()->prepare('SELECT id, name, icon_filename FROM squadrons WHERE id = ? LIMIT 1');
                $stmt->execute([(int)$theme['selected_squadron_id']]);
                $themeSquadron = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
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
            <nav class="site-header-nav" aria-label="Main navigation">
                <a href="index.php">Home</a>
                <a href="meet-the-group.php">Meet the Group</a>
                <a href="announcements.php">Announcements</a>
                <a href="intramural-standings.php">Standings</a>
            </nav>
        </div>
        <?php
    }
}
