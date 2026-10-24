# Push Notification Setup and Release Checklist

The application uses the standards-based Web Push protocol through `minishlink/web-push`. It does not use a separate Firebase Cloud Messaging API key or a Windows Notification Services server key.

## Required production configuration

Configure these Railway service variables before deploying this change:

- `VAPID_PUBLIC_KEY`: the URL-safe Base64 public application-server key.
- `VAPID_PRIVATE_KEY`: the matching URL-safe Base64 private application-server key. Treat this as a secret; never put it in source control, logs, screenshots, or support output.
- `VAPID_SUBJECT`: a valid `mailto:` contact address or HTTPS URL for the application owner.
- `APP_ORIGIN`: set this to the exact public origin for the app, such as `https://groupstatistics-production.up.railway.app` (no path).

Generate one VAPID pair using a trusted local environment with the `web-push` command-line package:

```sh
npm install -g web-push
web-push generate-vapid-keys
```

Paste the **public** value into `VAPID_PUBLIC_KEY` and the **private** value into `VAPID_PRIVATE_KEY`. Both variables must be set together, and the values must be from the same generated pair. Never commit the private value.

The service can also read `/data/vapid-keys.json` for backward-compatible deployments, but it will not generate or fall back to demo keys. Environment variables take precedence over the file. The previously bundled public demo key is rejected because the matching private key was exposed in public source.

**Important:** changing the VAPID pair changes the application-server identity. Devices that subscribed using the old key may need to turn notifications off and back on in Notification Settings to create a fresh subscription. Plan and verify this migration before rollout; do not rotate keys casually.

## Current behavior

- Users can subscribe from Notification Settings and choose all squadrons, selected squadrons, or pause score notifications by selecting none.
- Saved preferences load from the server when the settings page opens.
- The server stores subscription endpoints and per-subscription encryption keys in SQLite.
- Score entry and bulk score entry use the same Web Push delivery service.
- Delivery reports distinguish push-service acceptance from a notification actually appearing on a device.
- Expired subscription endpoints reported as HTTP 404/410 are removed.
- Subscription-changing endpoints require same-origin POST requests and accept new endpoint URLs only for known HTTPS browser push-service domains.
- Endpoint identifiers shown in diagnostics are one-way hashes; they are not the raw subscription endpoint.

## Verification before merge and deployment

1. Set and review the Railway variables above. Verify there are no private VAPID keys in logs or source.
2. Run `php tests/run.php` and `php -l` on changed PHP files in the project environment.
3. Confirm `get-vapid-key.php` returns HTTP 200 with only the public key when configured, and HTTP 503 with a generic message when configuration is absent or uses the known demo key.
4. Subscribe a fresh browser/device, reload settings, and confirm that saved squadron preferences remain checked correctly.
5. Test the explicit all-squadrons option, one-squadron selection, multiple selections, and the no-squadron/paused state.
6. Test the per-device test push and admin test push; verify that their UI wording does not claim delivery just because the push service accepted the request.
7. Record a single score and a bulk score; confirm each intended subscribed device receives the expected notifications without duplicate or misdirected messages.
8. Test with the app in the background and fully closed, then open the notification and verify it reaches the rankings page.
9. Test disable/re-enable, denied permission, missing server subscription, expired subscription, invalid origin, and an unsupported endpoint host.
10. Verify at least one supported installed PWA on Windows (Edge or Chrome), Android (Chrome), and iOS/iPadOS Home Screen where available. Device-level checks cannot be replaced by code inspection.

## Troubleshooting

- **HTTP 503 from `get-vapid-key.php`:** missing/invalid VAPID configuration or the old published demo key is still active.
- **Push request fails:** check the server log's status and error category; do not log private keys or full subscription endpoints.
- **Browser appears subscribed but settings cannot load:** reopen settings and follow the displayed recovery guidance; if keys were rotated, turn notifications off and back on.
- **Push service accepted but nothing appears:** check browser permission, operating-system notification settings, battery/background restrictions, installed-app status, and service-worker logs. Provider acceptance is not confirmation of display.

