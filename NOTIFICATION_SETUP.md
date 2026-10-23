# Push Notification Setup and Verification

The app uses the **Web Push Protocol** through `minishlink/web-push` and the browser's Push API. Browser vendors operate their own push services; the server sends encrypted Web Push messages to the endpoint returned by the browser. A Firebase service account or a separate WNS API key is not part of this implementation.

## Production configuration

### 1. Rotate the VAPID key pair

The old source included a fallback VAPID private key. Treat that pair as compromised and **rotate it before relying on production push notifications**. Existing browser subscriptions created with the old public key must be renewed after the server starts using a new pair.

Generate a fresh key pair in a trusted environment with the installed PHP library:

```sh
php -r 'require "vendor/autoload.php"; print_r(\\Minishlink\\WebPush\\VAPID::createVapidKeys());'
```

Set these Railway service variables to the matching generated values:

- `VAPID_PUBLIC_KEY` — base64url-encoded uncompressed P-256 public key (65 decoded bytes).
- `VAPID_PRIVATE_KEY` — matching base64url-encoded private scalar (32 decoded bytes).
- `VAPID_SUBJECT` — a valid `mailto:` contact address or an HTTPS URL.

Keep the private key in Railway's secret/environment-variable storage. **Never commit it, print it into application logs, or share it in diagnostics.** If using the persistent-file option instead, place a JSON object with `publicKey` and `privateKey` at `DATA_DIR/vapid-keys.json` and restrict file access. If either environment key is set, both must be set; the application does not combine environment and file keys.

The key loader checks encoding and expected lengths. Those checks alone cannot prove that the public/private values are a matching pair, so the end-to-end push test is required.

### 2. Confirm deploy configuration

- Confirm `/data` (or `DATA_DIR`) is persistent if the file-based key configuration is used.
- Confirm the deployed app is HTTPS and the Composer dependencies are installed.
- Confirm the service can make outbound HTTPS requests to the push providers used by subscribed browsers.
- `get-vapid-key.php` returns only the public key; when configuration is missing or malformed it returns HTTP 503 instead of inventing fallback keys.
- Subscription endpoints are restricted to HTTPS endpoints from recognized providers to prevent arbitrary URLs from becoming outbound server requests. If a browser uses a new push-service hostname, review and explicitly allow that provider in `notifications-helper.php` before enabling it.

## User setup and preferences

1. Open the app on the device/browser where notifications should appear.
2. Open **Notifications**, enable notifications, and allow the browser permission prompt.
3. Choose either **all squadrons** or specific squadrons. Unchecking all-squad notifications and selecting no squadron intentionally pauses score notifications.
4. Save preferences, then use **Test Notification**.
5. Check that the notification actually appears on the device. An HTTP success from the push service means it accepted the message; it does not guarantee the OS displayed it.
6. Tap the notification and confirm the tracker opens/focuses the intended page.
7. Disable notifications and confirm the browser subscription and server record are both cleared.

The settings page saves preferences on the server and reloads them when reopened. If a device has a local subscription but its server record is missing, the page attempts to restore the record with no squadrons selected so it does not silently broaden the user's notification preferences.

## Test the production flow

1. Subscribe one test device on each supported target listed in `PWA-CHECKLIST.md`.
2. Use the device-side test push and confirm the system notification appears while the app is backgrounded or closed.
3. Record one score through `admin-scores.php`; verify that only users following that squadron (plus users who opted into all scores) receive it.
4. Record a bulk event through `admin-bulk-scores.php`; verify that tags are unique per recorded squadron event and one user's bulk notifications do not overwrite one another.
5. Test all-squad, selected-squad, and no-squad preferences, then reload the settings page and verify the values persist.
6. Test denial/revocation of notification permission, lost server subscription rows, a stale endpoint, and delivery failure. Expired endpoints (HTTP 404/410 from the push service) should be removed automatically.
7. Review Railway logs for delivery counts and hashed endpoint identifiers. Never paste or log raw subscription endpoints or subscription encryption keys.

## Troubleshooting

- **HTTP 503 from `get-vapid-key.php`:** configure a full matching VAPID pair and a valid `VAPID_SUBJECT`, or fix the persistent key file.
- **Invalid application server key / subscribe failure:** ensure the public key is base64url encoded, is 65 decoded bytes, and matches the private key configured on the server. Existing subscriptions need to be recreated after key rotation.
- **HTTP 401/403 from a push service:** verify the VAPID pair, subject, subscription validity, and provider response.
- **HTTP 404/410:** the push subscription expired; the delivery service removes it so the user can subscribe again.
- **Server says sent but no notification appears:** confirm OS/browser notifications are enabled, test from the installed PWA where required by the platform, and check the browser's notification settings.
- **Preferences not sticking:** reload the page, inspect the API response, and check for HTTP 419 (expired session security token) or 404 (missing subscription; the settings page should offer recovery).

## Related code

- `vapid-config.php` — shared VAPID key loading and format validation.
- `get-vapid-key.php` — public-key endpoint used when subscribing.
- `subscribe-notifications-api.php` — subscribe, unsubscribe, preference, and device-test operations.
- `notifications-helper.php` — stored preferences, recipient matching, provider endpoint validation, and cleanup.
- `push-service.php` — encrypted Web Push delivery and expiry handling.
- `sw.js` — background notification display and click behavior.
