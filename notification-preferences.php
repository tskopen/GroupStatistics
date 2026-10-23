<?php require __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notification Settings</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial; background: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 500px; margin: 0 auto; }
        .card { background: #fff; border-radius: 6px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        h1 { color: #002147; }
        label { display: block; margin: 12px 0 6px 0; font-weight: bold; }
        input[type="checkbox"] { margin-right: 8px; }
        .checkbox-group { margin: 12px 0; }
        .checkbox-item { display: flex; align-items: center; margin: 8px 0; }
        button { padding: 10px 16px; background: #002147; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        button:hover { background: #003366; }
        .status { padding: 12px; border-radius: 4px; margin: 12px 0; }
        .status.success { background: #d4edda; color: #155724; }
        .status.error { background: #f8d7da; color: #721c24; }
        .status.info { background: #d1ecf1; color: #0c5460; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>Notification Settings</h1>
            <p>Get push notifications when your followed squadrons score points.</p>

            <div id="status"></div>

            <label>
                <input type="checkbox" id="enable-notifications"> Enable Notifications
            </label>

            <div id="preferences" style="display:none;">
                <label>
                    <input type="checkbox" id="all-squadrons" checked>
                    Notify me about all squadrons
                </label>
                <p class="status info" style="margin-top:8px;">Turn this off to choose specific squadrons. If no squadrons are selected, score notifications will be paused.</p>
                <label>Follow specific squadrons:</label>
                <div class="checkbox-group" id="squadron-list"></div>
                <button onclick="savePreferences()">Save Preferences</button>

                <div id="subscription-diagnostics" style="display:none; margin-top:16px; padding:12px; background:#f9f9f9; border-radius:4px; font-size:0.85em; color:#444;">
                    <strong>Subscription diagnostics</strong>
                    <div>Endpoint: <code id="diag-endpoint-hash"></code>...</div>
                    <div>VAPID key used: <code id="diag-vapid-key"></code>...</div>
                    <button id="test-notification-btn" onclick="sendTestNotification()" style="margin-top:10px; background:#0c5460;">Test Notification</button>
                    <div id="test-notification-result" style="margin-top:8px;"></div>
                </div>
            </div>

            <div id="disabled-message" style="display:none; padding:12px; background:#e9ecef; border-radius:4px;">
                Notifications are disabled. Enable them above to get started.
            </div>
        </div>

        <div class="card">
            <button onclick="goBack()" style="background:#666;">← Back to Rankings</button>
        </div>
    </div>

    <script>
        let registration = null;
        let currentSubscription = null;
        let squadrons = [];
        let lastVapidKeyPrefix = '';
        const csrfToken = <?php echo json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        async function init() {
            try {
                const resp = await fetch('api-get-squadrons.php', { cache: 'no-store' });
                if (!resp.ok) throw new Error('Could not load squadron list.');
                const data = await resp.json();
                squadrons = data.squadrons || [];

                const list = document.getElementById('squadron-list');
                list.replaceChildren();
                squadrons.forEach(sq => {
                    const label = document.createElement('label');
                    label.className = 'checkbox-item';
                    const input = document.createElement('input');
                    input.type = 'checkbox';
                    input.value = String(sq.id);
                    const name = document.createElement('span');
                    name.textContent = String(sq.name || ('Squadron ' + sq.id));
                    label.append(input, name);
                    list.appendChild(label);
                });

                document.getElementById('all-squadrons').addEventListener('change', toggleSquadronSelection);

                if ('serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window) {
                    registration = await navigator.serviceWorker.ready;
                    await checkSubscription();
                } else {
                    document.getElementById('enable-notifications').disabled = true;
                    showStatus('Push notifications are not supported in this browser or installation.', 'error');
                }
            } catch (error) {
                showStatus('Unable to load notification settings: ' + error.message, 'error');
            }
        }

        async function checkSubscription() {
            if (!registration) return;

            currentSubscription = await registration.pushManager.getSubscription();
            const checkbox = document.getElementById('enable-notifications');

            if (currentSubscription) {
                checkbox.checked = true;
                document.getElementById('preferences').style.display = 'block';
                document.getElementById('disabled-message').style.display = 'none';
                await loadSavedPreferences();
                await showSubscriptionDiagnostics();
            } else {
                checkbox.checked = false;
                document.getElementById('preferences').style.display = 'none';
                document.getElementById('disabled-message').style.display = 'block';
                document.getElementById('all-squadrons').checked = true;
                toggleSquadronSelection();
            }
        }

        function toggleSquadronSelection() {
            const allSquadrons = document.getElementById('all-squadrons').checked;
            document.querySelectorAll('#squadron-list input[type="checkbox"]').forEach(input => {
                input.disabled = allSquadrons;
            });
        }

        async function loadSavedPreferences() {
            if (!currentSubscription) return;

            const resp = await fetch('subscribe-notifications-api.php?action=get_preferences', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                cache: 'no-store',
                body: JSON.stringify({ endpoint: currentSubscription.endpoint })
            });

            if (resp.status === 404) {
                // Recover a browser subscription that was lost from the server.
                // Start with no score categories selected rather than silently
                // re-enabling all-squad notifications.
                const recovery = await fetch('subscribe-notifications-api.php?action=subscribe', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({
                        subscription: currentSubscription.toJSON(),
                        all_scores: false,
                        squadrons: []
                    })
                });
                if (!recovery.ok) throw new Error('Could not restore this device subscription.');
                document.getElementById('all-squadrons').checked = false;
                document.querySelectorAll('#squadron-list input[type="checkbox"]').forEach(input => { input.checked = false; });
                toggleSquadronSelection();
                showStatus('This device subscription was restored. Choose all squadrons or select the squadrons you want to follow.', 'info');
                return;
            }

            const data = await resp.json();
            if (!resp.ok || !data.success) throw new Error(data.message || 'Could not load saved preferences.');

            document.getElementById('all-squadrons').checked = !!data.all_scores;
            const selected = new Set((data.squadrons || []).map(String));
            document.querySelectorAll('#squadron-list input[type="checkbox"]').forEach(input => {
                input.checked = selected.has(input.value);
            });
            toggleSquadronSelection();
        }

        // Client-side subscription diagnostics: immediately re-fetch the
        // current subscription from the pushManager and surface identifying
        // details so the user (and support staff) can confirm the browser
        // actually holds a live push subscription tied to the expected
        // VAPID key.
        async function showSubscriptionDiagnostics() {
            if (!registration) return;

            const sub = await registration.pushManager.getSubscription();

            if (!sub) {
                document.getElementById('subscription-diagnostics').style.display = 'none';
                return;
            }

            currentSubscription = sub;

            document.getElementById('diag-endpoint-hash').textContent = sub.endpoint.substring(0, 20);
            document.getElementById('diag-vapid-key').textContent = lastVapidKeyPrefix || '(unknown - key not refetched this session)';
            document.getElementById('subscription-diagnostics').style.display = 'block';
        }

        // Sends an immediate test push through the server so the user can
        // verify end-to-end delivery without waiting for a real score event.
        async function sendTestNotification() {
            const resultEl = document.getElementById('test-notification-result');
            const btn = document.getElementById('test-notification-btn');

            if (!currentSubscription) {
                resultEl.textContent = 'No active subscription to test.';
                return;
            }

            btn.disabled = true;
            resultEl.textContent = 'Sending test notification...';

            try {
                const resp = await fetch('subscribe-notifications-api.php?action=test_notification', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ endpoint: currentSubscription.endpoint })
                });

                const data = await resp.json();

                if (resp.ok && data.success) {
                    resultEl.innerHTML = '<span style="color:#155724;">✓ Test notification sent — check your device.</span>';
                } else {
                    resultEl.innerHTML = '<span style="color:#721c24;">✗ ' + (data.message || 'Test failed') + '</span>';
                }
            } catch (err) {
                resultEl.innerHTML = '<span style="color:#721c24;">✗ Request failed: ' + err.message + '</span>';
            } finally {
                btn.disabled = false;
            }
        }

        async function toggleNotifications(enable) {
            const checkbox = document.getElementById('enable-notifications');

            if (!registration) {
                checkbox.checked = false;
                showStatus('Service Worker not available. Reload the page and try again.', 'error');
                return;
            }

            if (enable) {
                if (Notification.permission === 'denied') {
                    checkbox.checked = false;
                    showStatus('Notifications are blocked in browser settings. Allow notifications for this site, then try again.', 'error');
                    return;
                }

                const permission = Notification.permission === 'granted'
                    ? 'granted'
                    : await Notification.requestPermission();
                if (permission !== 'granted') {
                    checkbox.checked = false;
                    showStatus('Notification permission was not granted.', 'error');
                    return;
                }

                await subscribe();
                return;
            }

            if (!currentSubscription) {
                document.getElementById('preferences').style.display = 'none';
                document.getElementById('disabled-message').style.display = 'block';
                showStatus('Notifications are already disabled for this device.', 'info');
                return;
            }

            try {
                const resp = await fetch('subscribe-notifications-api.php?action=unsubscribe', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ endpoint: currentSubscription.endpoint })
                });
                const data = await resp.json();
                if (!resp.ok || !data.success) {
                    throw new Error(data.message || 'Could not clear the server subscription.');
                }

                await currentSubscription.unsubscribe();
                currentSubscription = null;
                document.getElementById('preferences').style.display = 'none';
                document.getElementById('disabled-message').style.display = 'block';
                document.getElementById('subscription-diagnostics').style.display = 'none';
                showStatus('Notifications disabled on this device.', 'info');
            } catch (error) {
                checkbox.checked = !!currentSubscription;
                showStatus('Could not disable notifications cleanly: ' + error.message, 'error');
            }
        }

        async function subscribe() {
            if (!registration) return;

            try {
                const vapidResp = await fetch('get-vapid-key.php', { cache: 'no-store' });
                const vapidData = await vapidResp.json();
                if (!vapidResp.ok || !vapidData.success || !vapidData.publicKey) {
                    throw new Error(vapidData.message || 'Push notifications are not configured on the server.');
                }

                const applicationServerKey = urlBase64ToUint8Array(vapidData.publicKey);
                if (applicationServerKey.length !== 65 || applicationServerKey[0] !== 4) {
                    throw new Error('The server returned an invalid VAPID public key.');
                }
                lastVapidKeyPrefix = vapidData.publicKey.substring(0, 12);

                const subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey
                });

                const allScores = document.getElementById('all-squadrons').checked;
                const selectedSquadrons = Array.from(document.querySelectorAll('#squadron-list input:checked'))
                    .map(input => Number(input.value));

                const subResp = await fetch('subscribe-notifications-api.php?action=subscribe', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({
                        subscription: subscription.toJSON(),
                        all_scores: allScores,
                        squadrons: selectedSquadrons
                    })
                });
                const subData = await subResp.json();
                if (!subResp.ok || !subData.success) {
                    throw new Error(subData.message || 'Failed to save this device subscription.');
                }

                currentSubscription = subscription;
                document.getElementById('preferences').style.display = 'block';
                document.getElementById('disabled-message').style.display = 'none';
                await loadSavedPreferences();
                await showSubscriptionDiagnostics();
                showStatus('Notifications enabled. Your preferences are saved.', 'success');
            } catch (error) {
                console.error('Subscribe error:', error);
                showStatus('Failed to enable notifications: ' + error.message, 'error');
                document.getElementById('enable-notifications').checked = false;
            }
        }

        async function savePreferences() {
            if (!currentSubscription) return;

            const allScores = document.getElementById('all-squadrons').checked;
            const checked = Array.from(document.querySelectorAll('#squadron-list input:checked'));
            const selectedSquadrons = checked.map(input => Number(input.value));

            try {
                const resp = await fetch('subscribe-notifications-api.php?action=update_preferences', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({
                        endpoint: currentSubscription.endpoint,
                        all_scores: allScores,
                        squadrons: selectedSquadrons
                    })
                });
                const data = await resp.json();
                if (!resp.ok || !data.success) throw new Error(data.message || 'Failed to save preferences.');
                showStatus('Preferences saved.', 'success');
            } catch (error) {
                showStatus('Failed to save preferences: ' + error.message, 'error');
            }
        }

        function showStatus(message, type) {
            const el = document.getElementById('status');
            el.className = 'status ' + type;
            el.textContent = message;
            el.style.display = 'block';
        }

        function goBack() {
            window.location.href = 'index.php';
        }

        function urlBase64ToUint8Array(base64String) {
            // Validate input
            if (!base64String || typeof base64String !== 'string') {
                throw new Error('Invalid VAPID public key: not a string');
            }

            if (base64String.length === 0) {
                throw new Error('Invalid VAPID public key: empty string');
            }

            // Base64url uses - and _ instead of + and /
            // Don't add padding; let atob handle it
            const base64 = base64String
                .replace(/\-/g, '+')
                .replace(/_/g, '/');

            let rawData;
            try {
                rawData = window.atob(base64);
            } catch (e) {
                throw new Error('Failed to decode base64: ' + e.message);
            }

            const outputArray = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; ++i) {
                outputArray[i] = rawData.charCodeAt(i);
            }

            return outputArray;
        }

        document.getElementById('enable-notifications').addEventListener('change', (e) => {
            toggleNotifications(e.target.checked);
        });

        init();
    </script>
</body>
</html>
