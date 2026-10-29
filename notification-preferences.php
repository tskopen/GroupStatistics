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
                <label class="checkbox-item" style="margin-bottom:12px;">
                    <input type="checkbox" id="all-squadrons" checked> Notify for all squadrons
                </label>
                <label>Choose specific squadrons:</label>
                <div class="checkbox-group" id="squadron-list"></div>
                <button onclick="savePreferences()">Save Preferences</button>

                <div id="subscription-diagnostics" style="display:none; margin-top:16px; padding:12px; background:#f9f9f9; border-radius:4px; font-size:0.85em; color:#444;">
                    <strong>Subscription diagnostics</strong>
                    <div>Endpoint ID (hashed): <code id="diag-endpoint-hash"></code></div>
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

        async function init() {
            // Load squadrons
            const resp = await fetch('api-get-squadrons.php');
            const data = await resp.json();
            squadrons = data.squadrons || [];

            // Render user-provided labels as text instead of interpolating them into HTML.
            const list = document.getElementById('squadron-list');
            squadrons.forEach(sq => {
                const label = document.createElement('label');
                label.className = 'checkbox-item';
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.value = String(sq.id);
                input.addEventListener('change', () => {
                    if (input.checked) document.getElementById('all-squadrons').checked = false;
                    updateSquadronControls();
                });
                label.append(input, document.createTextNode(' ' + sq.name));
                list.appendChild(label);
            });
            document.getElementById('all-squadrons').addEventListener('change', updateSquadronControls);
            updateSquadronControls();

            // Check if the service worker and Push API are available.
            if ('serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window) {
                registration = await navigator.serviceWorker.ready;
                await checkSubscription();
            } else {
                showStatus('Push notifications are not supported in this browser or context.', 'error');
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
                await loadPreferences();
                await verifySubscriptionKey();
                await showSubscriptionDiagnostics();
            } else {
                checkbox.checked = false;
                document.getElementById('preferences').style.display = 'none';
                document.getElementById('disabled-message').style.display = 'block';
                document.getElementById('subscription-diagnostics').style.display = 'none';
            }
        }

        function updateSquadronControls() {
            const allSquadrons = document.getElementById('all-squadrons').checked;
            document.querySelectorAll('#squadron-list input[type="checkbox"]').forEach(input => {
                input.disabled = allSquadrons;
                if (allSquadrons) input.checked = false;
            });
        }

        async function loadPreferences() {
            if (!currentSubscription) return;

            try {
                let resp = await fetch('subscribe-notifications-api.php?action=get_preferences', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ endpoint: currentSubscription.endpoint })
                });

                if (resp.status === 404) {
                    // Recover a browser subscription missing from the server DB, without
                    // silently enabling notifications for every squadron.
                    resp = await fetch('subscribe-notifications-api.php?action=subscribe', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            subscription: currentSubscription.toJSON(),
                            squadrons: [],
                            all_scores: false
                        })
                    });
                    if (!resp.ok) throw new Error('The server could not restore this device subscription.');
                    document.getElementById('all-squadrons').checked = false;
                    document.querySelectorAll('#squadron-list input[type="checkbox"]').forEach(input => input.checked = false);
                    updateSquadronControls();
                    showStatus('This device subscription was restored. Choose the squadrons you want to follow.', 'info');
                    return;
                }

                if (!resp.ok) throw new Error('Unable to load saved notification preferences.');
                const data = await resp.json();
                if (!data.success) throw new Error(data.message || 'Unable to load saved preferences.');

                const selected = new Set((data.squadrons || []).map(id => String(id)));
                document.getElementById('all-squadrons').checked = Boolean(data.all_scores);
                document.querySelectorAll('#squadron-list input[type="checkbox"]').forEach(input => {
                    input.checked = !data.all_scores && selected.has(input.value);
                });
                updateSquadronControls();
            } catch (error) {
                showStatus(error.message || 'Unable to load notification preferences.', 'error');
            }
        }

        async function verifySubscriptionKey() {
            if (!currentSubscription) return;

            try {
                const response = await fetch('get-vapid-key.php', { cache: 'no-store' });
                if (!response.ok) {
                    showStatus('Push notifications are not configured on the server. Contact the site administrator.', 'error');
                    return;
                }
                const config = await response.json();
                if (!config.success || !config.publicKey) {
                    showStatus('The server did not provide a valid push public key.', 'error');
                    return;
                }

                const subscribedKey = currentSubscription.options && currentSubscription.options.applicationServerKey;
                if (!subscribedKey) return; // Some browsers do not expose the key for comparison.

                const actualBytes = subscribedKey instanceof ArrayBuffer
                    ? new Uint8Array(subscribedKey)
                    : new Uint8Array(subscribedKey.buffer, subscribedKey.byteOffset, subscribedKey.byteLength);
                const expectedBytes = urlBase64ToUint8Array(config.publicKey);
                const matches = actualBytes.length === expectedBytes.length &&
                    actualBytes.every((value, index) => value === expectedBytes[index]);

                if (!matches) {
                    showStatus('This device subscription uses an older push key. Turn notifications off, then back on to create a fresh subscription.', 'error');
                }
            } catch (_) {
                // A temporary diagnostics failure must not destroy a valid subscription.
            }
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

            try {
                const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(sub.endpoint));
                document.getElementById('diag-endpoint-hash').textContent =
                    Array.from(new Uint8Array(digest)).slice(0, 6).map(value => value.toString(16).padStart(2, '0')).join('');
            } catch (_) {
                document.getElementById('diag-endpoint-hash').textContent = '(unavailable)';
            }
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
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ endpoint: currentSubscription.endpoint })
                });

                const data = await resp.json();

                if (resp.ok && data.success) {
                    resultEl.style.color = '#155724';
                    resultEl.textContent = '✓ Push service accepted the test. Check your device for delivery.';
                } else {
                    resultEl.style.color = '#721c24';
                    resultEl.textContent = '✗ ' + (data.message || 'Test failed');
                }
            } catch (err) {
                resultEl.style.color = '#721c24';
                resultEl.textContent = '✗ Request failed: ' + err.message;
            } finally {
                btn.disabled = false;
            }
        }

        async function toggleNotifications(enable) {
            if (!registration) {
                showStatus('Service Worker not available', 'error');
                return;
            }

            if (enable) {
                if (!('Notification' in window) || !registration.pushManager) {
                    document.getElementById('enable-notifications').checked = false;
                    showStatus('Push notifications are not supported here.', 'error');
                    return;
                }
                try {
                    let permission = Notification.permission;
                    if (permission !== 'granted') permission = await Notification.requestPermission();
                    if (permission !== 'granted') {
                        document.getElementById('enable-notifications').checked = false;
                        showStatus('Notification permission was not granted.', 'error');
                        return;
                    }
                    await subscribe();
                } catch (error) {
                    document.getElementById('enable-notifications').checked = false;
                    showStatus(error.message || 'Could not enable notifications.', 'error');
                }
            } else {
                const subscription = currentSubscription;
                if (!subscription) return;
                try {
                    await subscription.unsubscribe();
                    const resp = await fetch('subscribe-notifications-api.php?action=unsubscribe', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ endpoint: subscription.endpoint })
                    });
                    if (!resp.ok) throw new Error('Browser notifications were disabled, but server cleanup failed. Try again.');
                    currentSubscription = null;
                    document.getElementById('preferences').style.display = 'none';
                    document.getElementById('disabled-message').style.display = 'block';
                    document.getElementById('subscription-diagnostics').style.display = 'none';
                    showStatus('Notifications disabled', 'info');
                } catch (error) {
                    showStatus(error.message || 'Unable to disable notifications completely.', 'error');
                }
            }
        }

        async function subscribe() {
            if (!registration) return;

            try {
                // Get VAPID public key from server
                const vapidResp = await fetch('get-vapid-key.php');
                if (!vapidResp.ok) {
                    throw new Error('Failed to fetch VAPID key: HTTP ' + vapidResp.status);
                }
                const vapidData = await vapidResp.json();

                // Validate public key exists and is not empty
                if (!vapidData.publicKey || vapidData.publicKey === '') {
                    throw new Error('Invalid VAPID public key from server: empty');
                }

                // Log the key for debugging (first 20 chars only for security)
                lastVapidKeyPrefix = vapidData.publicKey.substring(0, 20);

                // Validate key format (should be base64url, 80+ chars for 65 bytes)
                if (!/^[A-Za-z0-9_-]+$/.test(vapidData.publicKey)) {
                    throw new Error('Invalid VAPID key format: contains invalid characters');
                }

                if (vapidData.publicKey.length < 80) {
                    throw new Error('Invalid VAPID key length: ' + vapidData.publicKey.length + ' (expected 87-88)');
                }

                let applicationServerKey;
                try {
                    applicationServerKey = urlBase64ToUint8Array(vapidData.publicKey);
                } catch (e) {
                    throw new Error('Failed to decode VAPID key: ' + e.message);
                }

                // Validate decoded key is 65 bytes
                if (applicationServerKey.length !== 65) {
                    throw new Error('Decoded VAPID key is ' + applicationServerKey.length + ' bytes, expected 65');
                }

                const subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: applicationServerKey
                });

                // Send subscription to server
                const subResp = await fetch('subscribe-notifications-api.php?action=subscribe', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        subscription,
                        squadrons: Array.from(document.querySelectorAll('#squadron-list input:checked'))
                            .map(input => Number.parseInt(input.value, 10)),
                        all_scores: document.getElementById('all-squadrons').checked
                    })
                });
                const saved = await subResp.json();
                if (!subResp.ok || !saved.success) {
                    throw new Error(saved.message || 'Failed to save the subscription on the server.');
                }

                currentSubscription = subscription;
                document.getElementById('preferences').style.display = 'block';
                document.getElementById('disabled-message').style.display = 'none';
                showStatus('Notifications enabled. Save your squadron preferences below.', 'success');
                await savePreferences();
                await showSubscriptionDiagnostics();
            } catch (error) {
                console.error('Subscribe error:', error);
                console.error('Error stack:', error.stack);
                showStatus('Failed to enable notifications: ' + error.message, 'error');
                document.getElementById('enable-notifications').checked = false;
            }
        }

        async function savePreferences() {
            if (!currentSubscription) return;

            const checked = Array.from(document.querySelectorAll('#squadron-list input:checked'));
            const selectedSquadrons = checked.map(input => Number.parseInt(input.value, 10));
            const allScores = document.getElementById('all-squadrons').checked;

            try {
                const resp = await fetch('subscribe-notifications-api.php?action=update_preferences', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        endpoint: currentSubscription.endpoint,
                        squadrons: selectedSquadrons,
                        all_scores: allScores
                    })
                });
                const data = await resp.json();
                if (resp.ok && data.success) {
                    showStatus(allScores
                        ? 'Preferences saved: notifications for all squadrons.'
                        : selectedSquadrons.length
                            ? 'Preferences saved.'
                            : 'Preferences saved: no squadrons selected, so score notifications are paused.', 'success');
                } else {
                    showStatus(data.message || 'Failed to save preferences.', 'error');
                }
            } catch (error) {
                showStatus(error.message || 'Failed to save preferences.', 'error');
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
