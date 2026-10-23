# PWA Icon and Installability Checklist

This checklist tracks the custom app icon and the remaining verification needed before this PWA change should be merged/deployed.

## Implementation in this PR

- [x] Point the web app manifest at static 192×192 and 512×512 PNG assets.
- [x] Use the 192×192 asset for the iOS home-screen icon.
- [x] Use a new service-worker cache version so existing installations refresh their cached resources.
- [x] Await service-worker precache attempts instead of allowing them to continue in the background.
- [x] Keep login/admin routes network-only and do not cache their responses.
- [x] Avoid storing responses marked `private` or `no-store`, or responses that set cookies.
- [x] Add a repeatable checklist for the asset upload and post-deployment checks.
- [x] Add a browser-native install button that appears when the PWA install prompt is available and hides after installation.

## Required before merge/deploy

- [x] Add `assets/pwa-icon-192.png` (exactly 192×192 pixels).
- [x] Add `assets/pwa-icon-512.png` (exactly 512×512 pixels).
- [x] Confirm both images show the supplied circular squadron patch centered on a square canvas. (Fine emblem details may be small at launcher size; visually verify on target devices.)
- [x] Keep manifest icon purpose as `any` unless a separate maskable-safe icon is created and tested. The circular patch should not be advertised as maskable by default.

## Verification after the images are added

- [ ] Validate `manifest.json` and confirm both icon URLs return HTTP 200 with `Content-Type: image/png`.
- [ ] Confirm the deployed site is served over HTTPS.
- [ ] Test install prompt / browser install on desktop Chromium and Android where available.
- [ ] Add the site to the iOS home screen and confirm the correct touch icon appears.
- [ ] Check the installed app's name, standalone launch, and icon on the home screen/app launcher.
- [ ] With network available, confirm scores and brackets refresh from the live site.
- [ ] With network disabled, confirm the public homepage fallback works and pages not cached show a clear offline message.
- [ ] Verify login/admin pages are never served from Cache Storage and are not available from stale cached HTML.
- [ ] Verify the service worker activates the new cache version and removes the previous `squadron-tracker-*` cache.
- [ ] Confirm notifications (if enabled) use the custom icon.
- [ ] Check browser console and service-worker logs for install, cache, and fetch errors.

## Notes / limitations

- A service worker improves offline behavior but does not itself guarantee every page works offline.
- The cache is intended for public read-only pages/assets. Admin/login routes are deliberately excluded.
- This PR does not deploy anything. Complete the image and browser checks above before merging/deploying.


## Push notification implementation

- [x] Load VAPID configuration from explicit environment variables or a pre-existing persistent key file; do not generate a published demo key.
- [x] Reject the previously published demo public key and require a configured VAPID subject.
- [x] Remove private-key material, complete endpoints, and endpoint prefixes from diagnostic logging and admin HTML output.
- [x] Require same-origin POST requests for notification API writes and the admin test push.
- [x] Restrict new subscriptions to HTTPS endpoints from common browser push providers.
- [x] Restore saved squadron preferences and distinguish all squadrons, selected squadrons, and no squadrons.
- [x] Clean up subscriptions when a push provider reports an expired endpoint.
- [ ] Configure a fresh production VAPID key pair and VAPID subject in Railway before rollout.
- [ ] Run PHP syntax checks and the project test runner.
- [ ] Test preference persistence, subscription recovery, and per-device/admin test notifications.
- [ ] Verify real background delivery and notification click-through on Windows, Android, and supported iOS/iPadOS installed PWAs.
- [ ] Confirm score-entry and bulk-score flows send exactly the intended notifications.
- [ ] If keys are rotated, communicate the required off/on re-subscription process and verify existing device subscriptions recover.
