#!/usr/bin/env node
const webpush = require('web-push');
const fs = require('fs');

// Load VAPID keys
const vapid = JSON.parse(fs.readFileSync('/data/vapid-keys.json', 'utf8'));
webpush.setVapidDetails('mailto:admin@tracker.local', vapid.publicKey, vapid.privateKey);

// Get args
const subs = JSON.parse(process.argv[2]);
const payload = JSON.parse(process.argv[3]);

// Send
(async () => {
    for (const sub of subs) {
        try {
            await webpush.sendNotification(
                {
                    endpoint: sub.endpoint,
                    keys: { auth: sub.auth, p256dh: sub.p256dh }
                },
                JSON.stringify(payload)
            );
            console.log('✓ Sent');
        } catch(e) {
            console.error('✗ Failed:', e.message);
        }
    }
})();
