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
                <label>Follow Squadrons:</label>
                <div class="checkbox-group" id="squadron-list"></div>
                <button onclick="savePreferences()">Save Preferences</button>
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

        async function init() {
            // Load squadrons
            const resp = await fetch('api-get-squadrons.php');
            const data = await resp.json();
            squadrons = data.squadrons || [];

            // Render squadron checkboxes
            const list = document.getElementById('squadron-list');
            squadrons.forEach(sq => {
                const label = document.createElement('label');
                label.className = 'checkbox-item';
                label.innerHTML = `<input type="checkbox" value="${sq.id}"> ${sq.name}`;
                list.appendChild(label);
            });

            // Check if SW is registered
            if ('serviceWorker' in navigator) {
                registration = await navigator.serviceWorker.ready;
                checkSubscription();
            } else {
                showStatus('Service Worker not supported', 'error');
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
            } else {
                checkbox.checked = false;
                document.getElementById('preferences').style.display = 'none';
                document.getElementById('disabled-message').style.display = 'block';
            }
        }

        async function toggleNotifications(enable) {
            if (!registration) {
                showStatus('Service Worker not available', 'error');
                return;
            }

            if (enable) {
                // Request notification permission
                if ('Notification' in window && Notification.permission === 'granted') {
                    subscribe();
                } else if ('Notification' in window) {
                    const permission = await Notification.requestPermission();
                    if (permission === 'granted') {
                        subscribe();
                    } else {
                        document.getElementById('enable-notifications').checked = false;
                        showStatus('Notification permission denied', 'error');
                    }
                }
            } else {
                // Unsubscribe
                if (currentSubscription) {
                    await currentSubscription.unsubscribe();
                    await fetch('subscribe-notifications-api.php?action=unsubscribe', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ endpoint: currentSubscription.endpoint })
                    });
                    currentSubscription = null;
                    document.getElementById('preferences').style.display = 'none';
                    document.getElementById('disabled-message').style.display = 'block';
                    showStatus('Notifications disabled', 'info');
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
                console.log('Using VAPID key: ' + vapidData.publicKey.substring(0, 20) + '...');

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
                    body: JSON.stringify({ subscription })
                });

                if (subResp.ok) {
                    currentSubscription = subscription;
                    document.getElementById('preferences').style.display = 'block';
                    document.getElementById('disabled-message').style.display = 'none';
                    showStatus('Notifications enabled!', 'success');
                    await savePreferences();
                } else {
                    throw new Error('Failed to subscribe on server');
                }
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
            const squadrons = checked.map(input => parseInt(input.value));

            const resp = await fetch('subscribe-notifications-api.php?action=update_preferences', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    endpoint: currentSubscription.endpoint,
                    squadrons
                })
            });

            if (resp.ok) {
                showStatus('Preferences saved', 'success');
            } else {
                showStatus('Failed to save preferences', 'error');
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
