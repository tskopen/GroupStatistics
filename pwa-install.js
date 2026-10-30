// Shared PWA installation control for public pages.
(function () {
    var installButton = document.getElementById('pwa-install-button');
    var installHelp = document.getElementById('pwa-install-help');
    if (!installButton || !installHelp) return;

    var installPrompt = null;
    var userAgent = window.navigator.userAgent || '';
    var isIOS = /iPad|iPhone|iPod/.test(userAgent) ||
        (window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1);
    var isAndroid = /Android/i.test(userAgent);
    var guideUrl = 'https://support.google.com/chrome/answer/9658361?co=GENIE.Platform%3DAndroid&hl=en-0';

    if (isIOS) {
        guideUrl = 'https://support.apple.com/guide/iphone/bookmark-a-website-iph42ab2f3a7/ios';
        installButton.textContent = '＋ Add to Home Screen';
        installHelp.textContent = 'For iPhone and iPad, open this page in Safari, tap Share, then choose Add to Home Screen.';
    } else if (isAndroid) {
        installButton.textContent = '＋ Add to Home Screen';
        installHelp.textContent = 'If the install prompt does not appear, follow the Chrome instructions to add this site to your home screen.';
    } else {
        guideUrl = 'https://developer.chrome.com/blog/how_chrome_helps_users_install_the_apps_they_value';
        installButton.textContent = '⬇ Install App';
        installHelp.textContent = 'If installation is not offered automatically, open the browser menu and look for Install app or Install page as app.';
    }

    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone === true;
    }

    function hideInstallUi() {
        installButton.hidden = true;
        installHelp.hidden = true;
    }

    if (isStandalone()) {
        hideInstallUi();
        return;
    }

    installButton.hidden = false;
    installHelp.hidden = false;

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        installPrompt = event;
        installButton.hidden = false;
        installButton.textContent = isIOS ? '＋ Add to Home Screen' : '⬇ Install App';
        installHelp.hidden = true;
    });

    installButton.addEventListener('click', async function () {
        if (!installPrompt) {
            window.open(guideUrl, '_blank', 'noopener,noreferrer');
            return;
        }

        installButton.disabled = true;
        try {
            await installPrompt.prompt();
            await installPrompt.userChoice;
        } catch (error) {
            console.error('PWA installation prompt failed:', error);
        } finally {
            installPrompt = null;
            installButton.disabled = false;
            hideInstallUi();
        }
    });

    window.addEventListener('appinstalled', function () {
        installPrompt = null;
        hideInstallUi();
    });
})();
