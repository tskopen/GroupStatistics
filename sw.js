/**
 * USAFA Squadron Tracker PWA Service Worker
 *
 * Public pages may be cached for offline viewing. Authentication/admin pages
 * are always network-only and are never written to Cache Storage.
 */

const CACHE_VERSION = 'v5';
const CACHE_NAME = `squadron-tracker-${CACHE_VERSION}`;

const PRECACHE_URLS = [
    './',
    './index.php',
    './manifest.json',
    './assets/pwa-icon-192.png',
    './assets/pwa-icon-512.png'
];

const NEVER_CACHE_PATHS = [
    '/admin-login.php',
    '/admin-panel.php'
];

self.addEventListener('install', event => {
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE_NAME);
        // Missing optional entries should not prevent the worker installing.
        // Await every attempt so install work does not continue in the background.
        await Promise.allSettled(PRECACHE_URLS.map(url => cache.add(url)));
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', event => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys
            .filter(key => key.startsWith('squadron-tracker-') && key !== CACHE_NAME)
            .map(key => caches.delete(key)));
        await self.clients.claim();
    })());
});

function isNeverCachePath(path) {
    return NEVER_CACHE_PATHS.some(page => path.endsWith(page));
}

function isNavigationRequest(request) {
    return request.mode === 'navigate' ||
        (request.method === 'GET' &&
         (request.headers.get('accept') || '').includes('text/html'));
}

function isCacheablePublicResponse(response, path) {
    if (!response || !response.ok || isNeverCachePath(path)) return false;
    const cacheControl = (response.headers.get('Cache-Control') || '').toLowerCase();
    if (cacheControl.includes('private') || cacheControl.includes('no-store')) return false;
    if (response.headers.has('Set-Cookie')) return false;
    return true;
}

async function networkFirst(request) {
    const url = new URL(request.url);
    const cache = await caches.open(CACHE_NAME);

    try {
        const response = await fetch(request);
        if (isCacheablePublicResponse(response, url.pathname)) {
            try { await cache.put(request, response.clone()); }
            catch (err) { console.warn('Could not cache response:', url.pathname, err); }
        }
        return response;
    } catch (err) {
        const cached = await cache.match(request);
        if (cached) return cached;

        if (isNavigationRequest(request)) {
            const fallback = await cache.match('./index.php');
            if (fallback) return fallback;
        }

        return new Response(
            '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline</title><body><h1>You are offline</h1><p>This page is not available offline. Reconnect and try again.</p></body></html>',
            { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503 }
        );
    }
}

async function cacheFirst(request) {
    const url = new URL(request.url);
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(request);
    if (cached) return cached;

    try {
        const response = await fetch(request);
        if (isCacheablePublicResponse(response, url.pathname)) {
            try { await cache.put(request, response.clone()); }
            catch (err) { console.warn('Could not cache asset:', url.pathname, err); }
        }
        return response;
    } catch (err) {
        return Response.error();
    }
}

self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Never cache admin/login routes, including HTML navigation requests.
    if (isNeverCachePath(url.pathname)) {
        event.respondWith(fetch(request));
        return;
    }

    if (isNavigationRequest(request)) {
        event.respondWith(networkFirst(request));
        return;
    }

    const isStaticAsset =
        /\.(png|jpg|jpeg|gif|svg|webp|ico|css|js|woff2?|ttf)$/i.test(url.pathname) ||
        url.pathname.endsWith('manifest.json');

    if (isStaticAsset) {
        event.respondWith(cacheFirst(request));
        return;
    }

    event.respondWith(networkFirst(request));
});

/* Push notifications */
self.addEventListener('push', event => {
    let data = {};
    if (event.data) {
        const raw = event.data.text();
        try { data = JSON.parse(raw); }
        catch { data = { title: 'Squadron Tracker', body: raw }; }
    }

    const fallbackIcon = new URL('./assets/pwa-icon-192.png', self.registration.scope).href;
    event.waitUntil(self.registration.showNotification(
        data.title || 'Squadron Tracker',
        {
            body: data.body || 'New update available',
            icon: data.icon || fallbackIcon,
            badge: data.badge || fallbackIcon,
            tag: data.tag || `push-${Date.now()}`,
            data: data.data || {}
        }
    ));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const targetUrl = new URL(
        event.notification?.data?.url || './index.php',
        self.registration.scope
    );

    event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true })
        .then(async clientList => {
            const sameOriginClient = clientList.find(client => {
                try { return new URL(client.url).origin === targetUrl.origin; }
                catch { return false; }
            });

            if (sameOriginClient) {
                if ('navigate' in sameOriginClient &&
                    (new URL(sameOriginClient.url).pathname !== targetUrl.pathname ||
                     new URL(sameOriginClient.url).search !== targetUrl.search)) {
                    await sameOriginClient.navigate(targetUrl.href);
                }
                if ('focus' in sameOriginClient) return sameOriginClient.focus();
            }

            if (clients.openWindow) return clients.openWindow(targetUrl.href);
        }));
});

self.addEventListener('message', event => {
    if (event.data?.type !== 'UPDATE_BADGE') return;
    const count = Number(event.data.count || 0);
    if (!('setAppBadge' in self.registration)) return;
    event.waitUntil(count <= 0
        ? self.registration.clearAppBadge()
        : self.registration.setAppBadge(count));
});

self.addEventListener('error', event => console.error('Service Worker Error:', event.error));
self.addEventListener('unhandledrejection', event => console.error('Unhandled Service Worker rejection:', event.reason));
