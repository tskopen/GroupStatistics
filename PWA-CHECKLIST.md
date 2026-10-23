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

## Push notification completion checklist

### Security and configuration
- [ ] Rotate the previously committed fallback VAPID key pair in Railway; existing device subscriptions will need to be renewed.
- [ ] Configure both `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY` as a matching pair, plus a valid `VAPID_SUBJECT`, or provision the matching pair through persistent `DATA_DIR/vapid-keys.json`.
- [x] Remove hardcoded fallback VAPID credentials from the public-key endpoint.
- [x] Keep private VAPID values and raw push subscription endpoints out of logs and admin diagnostics.
- [x] Require a session CSRF token on subscription, preference, unsubscribe, and test-push POST requests.
- [x] Rate-limit user/admin connection-test pushes.
- [x] Restrict outbound push endpoints to HTTPS hostnames belonging to supported push providers.
- [x] Remove expired subscriptions when the push service returns HTTP 404/410.
- [ ] Confirm key rotation and stored environment variables in the actual Railway service; this cannot be verified from the repository.

### Preferences and delivery consistency
- [x] Persist an explicit `all_scores` preference independently of the selected-squad list.
- [x] Reload saved preferences when the settings page opens.
- [x] Recover a browser-held subscription missing on the server without automatically opting the user into all squads.
- [x] Give score events unique notification tags so rapid/bulk score updates do not replace one another.
- [x] Standardize the notification icon and click-through behavior in the service worker.
- [x] Redact subscription credentials from admin test output.
- [x] Align setup documentation with the implemented standard Web Push protocol.
- [ ] Test that selected-squad/all-squad/none preferences deliver to the correct devices.

### Installed-device verification (required before calling push complete)
- [ ] Windows Edge: installed PWA receives a background test notification and tap/click opens the tracker.
- [ ] Windows Chrome: installed PWA receives a background test notification and tap/click opens the tracker.
- [ ] Android Chrome: installed PWA receives a background test notification while backgrounded/closed and tap opens the tracker.
- [ ] iPhone/iPad: supported Home Screen web app can subscribe and receive a background notification, then open the tracker when tapped.
- [ ] Uninstall/reinstall, permission denied/revoked, service-worker update, server subscription missing, stale endpoint, and offline/network error behavior.
- [ ] Confirm a push-service HTTP success is reported as “accepted for delivery,” not guaranteed display.
- [ ] Review Railway logs and confirm they contain no private VAPID values or raw endpoint URLs.

