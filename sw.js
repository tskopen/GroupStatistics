/**
 * Service worker for the USAFA Group 1 Squadron Tracker PWA.
 *
 * Strategy:
 *  - App shell pages (index.php, bracket.php, admin-login.php) and static
 *    assets are pre-cached on install.
 *  - Dynamic/data-bearing requests use a network-first strategy so scores
 *    and brackets are as fresh as possible, falling back to cache when
 *    offline.
 *  - Navigation requests fall back to a cached copy of the requested page,
 *    or to the cached index page, when the network is unavailable.
 *
 * Bump CACHE_VERSION whenever cached assets change so old caches are
 * cleaned up on activate.
 */

const CACHE_VERSION = 'v1';
const CACHE_NAME = `squadron-tracker-${CACHE_VERSION}`;

const PRECACHE_URLS = [
    'index.php',
    'bracket.php',
    'admin-login.php',
    'manifest.json',
    'pwa-icon.php?size=192',
    'pwa-icon.php?size=512',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return Promise.all(
                PRECACHE_URLS.map((url) =>
                    cache.add(url).catch(() => {
                        // Ignore individual failures (e.g. GD unavailable for icons)
                        // so install doesn't fail entirely.
                    })
                )
            );
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => key.startsWith('squadron-tracker-') && key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            )
        )
    );
    self.clients.claim();
});

function isNavigationRequest(request) {
    return request.mode === 'navigate' ||
        (request.method === 'GET' && request.headers.get('accept') && request.headers.get('accept').includes('text/html'));
}

// Network-first: try the network, cache the fresh response, fall back to
// cache (and then to a generic offline response) if the network fails.
async function networkFirst(request) {
    const cache = await caches.open(CACHE_NAME);
    try {
        const networkResponse = await fetch(request);
        if (networkResponse && networkResponse.ok) {
            cache.put(request, networkResponse.clone());
        }
        return networkResponse;
    } catch (err) {
        const cached = await cache.match(request);
        if (cached) {
            return cached;
        }
        if (isNavigationRequest(request)) {
            const fallback = await cache.match('index.php');
            if (fallback) {
                return fallback;
            }
        }
        return new Response(
            '<h1>Offline</h1><p>You are offline and this page has not been cached yet.</p>',
            { headers: { 'Content-Type': 'text/html' } }
        );
    }
}

// Cache-first: serve from cache when available, otherwise fetch and cache.
async function cacheFirst(request) {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(request);
    if (cached) {
        return cached;
    }
    try {
        const networkResponse = await fetch(request);
        if (networkResponse && networkResponse.ok) {
            cache.put(request, networkResponse.clone());
        }
        return networkResponse;
    } catch (err) {
        return cached || Response.error();
    }
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Only handle same-origin requests.
    if (url.origin !== self.location.origin) {
        return;
    }

    const path = url.pathname;

    // App shell / dynamic pages that hold live scores & brackets: network-first.
    const dynamicPages = ['index.php', 'bracket.php', 'admin-login.php', 'admin-panel.php'];
    const isDynamicPage = dynamicPages.some((page) => path.endsWith(page)) || path === '/' || path.endsWith('/');

    if (isDynamicPage || isNavigationRequest(request)) {
        event.respondWith(networkFirst(request));
        return;
    }

    // Static assets (images, icons, manifest, css/js): cache-first.
    const isStaticAsset = /\.(png|jpg|jpeg|gif|svg|webp|ico|css|js|woff2?|ttf)$/i.test(path) ||
        path.endsWith('manifest.json') ||
        path.includes('pwa-icon.php') ||
        path.includes('image.php');

    if (isStaticAsset) {
        event.respondWith(cacheFirst(request));
        return;
    }

    // Default: network-first for everything else so data stays fresh.
    event.respondWith(networkFirst(request));
});
