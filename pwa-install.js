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
        installButton.textContent = '＋ Add to Home Screen';
        installButton.setAttribute('aria-expanded', 'false');
        installButton.setAttribute('aria-controls', 'pwa-install-help');
        installHelp.classList.add('ios-install-help');
        installHelp.innerHTML = '<strong>How to add this app to your Home Screen</strong><ol><li>Open this website in <strong>Safari</strong>.</li><li>Tap the <strong>Share</strong> button (the square with an arrow).</li><li>Scroll down and tap <strong>Add to Home Screen</strong>.</li><li>Tap <strong>Add</strong> to finish.</li></ol><span>Tip: If you do not see the option, make sure you are using Safari.</span>;
    } else if (isAndroid) {
        installButton.textContent = '＋ Add to Home Screen';
        installHelp.textContent = 'If the install prompt does not appear, follow the Chrome instructions to add this site to your home screen.';
    } else {
        guideUrl = 'https://developer.chrome.com/blog/how_chrome_helps_users_install_the_apps_they_value';
        installButton.textContent = '⬇ Install App';
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
    installHelp.hidden = !installHelp.textContent;

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        installPrompt = event;
        installButton.hidden = false;
        installButton.textContent = isIOS ? '＋ Add to Home Screen' : '⬇ Install App';
        installHelp.hidden = true;
    });

    installButton.addEventListener('click', async function () {
        if (isIOS) {
            var isOpen = installHelp.classList.toggle('is-open');
            installButton.setAttribute('aria-expanded', String(isOpen));
            return;
        }

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
