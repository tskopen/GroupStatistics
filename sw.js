/**
 * USAFA Squadron Tracker PWA Service Worker
 */

const CACHE_VERSION = 'v2';
const CACHE_NAME = `squadron-tracker-${CACHE_VERSION}`;

const PRECACHE_URLS = [
    '/',
    '/index.php',
    '/bracket.php',
    '/admin-login.php',
    '/manifest.json',
    '/pwa-icon.php?size=192',
    '/pwa-icon.php?size=512'
];

/* =========================
   INSTALL
========================= */

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache =>
            Promise.all(
                PRECACHE_URLS.map(url =>
                    cache.add(url).catch(err => {
                        console.warn('Precache failed:', url, err);
                    })
                )
            )
        )
    );

    self.skipWaiting();
});

/* =========================
   ACTIVATE
========================= */

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys
                    .filter(
                        key =>
                            key.startsWith('squadron-tracker-') &&
                            key !== CACHE_NAME
                    )
                    .map(key => caches.delete(key))
            )
        )
    );

    self.clients.claim();
});

/* =========================
   HELPERS
========================= */

function isNavigationRequest(request) {
    return (
        request.mode === 'navigate' ||
        (
            request.method === 'GET' &&
            request.headers.get('accept') &&
            request.headers.get('accept').includes('text/html')
        )
    );
}

async function networkFirst(request) {
    const cache = await caches.open(CACHE_NAME);

    try {
        const response = await fetch(request);

        if (response && response.ok) {
            cache.put(request, response.clone());
        }

        return response;
    } catch (err) {
        const cached = await cache.match(request);

        if (cached) {
            return cached;
        }

        if (isNavigationRequest(request)) {
            const fallback = await cache.match('/index.php');

            if (fallback) {
                return fallback;
            }
        }

        return new Response(
            `
            <html>
            <body>
                <h1>Offline</h1>
                <p>No network connection available.</p>
            </body>
            </html>
            `,
            {
                headers: {
                    'Content-Type': 'text/html'
                }
            }
        );
    }
}

async function cacheFirst(request) {
    const cache = await caches.open(CACHE_NAME);

    const cached = await cache.match(request);

    if (cached) {
        return cached;
    }

    try {
        const response = await fetch(request);

        if (response && response.ok) {
            cache.put(request, response.clone());
        }

        return response;
    } catch (err) {
        return Response.error();
    }
}

/* =========================
   FETCH
========================= */

self.addEventListener('fetch', event => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    const path = url.pathname;

    const dynamicPages = [
        'index.php',
        'bracket.php',
        'admin-login.php',
        'admin-panel.php'
    ];

    const isDynamicPage =
        dynamicPages.some(page => path.endsWith(page)) ||
        path === '/' ||
        path.endsWith('/');

    if (isDynamicPage || isNavigationRequest(request)) {
        event.respondWith(networkFirst(request));
        return;
    }

    const isStaticAsset =
        /\.(png|jpg|jpeg|gif|svg|webp|ico|css|js|woff2?|ttf)$/i.test(path) ||
        path.endsWith('manifest.json') ||
        path.includes('pwa-icon.php') ||
        path.includes('image.php');

    if (isStaticAsset) {
        event.respondWith(cacheFirst(request));
        return;
    }

    event.respondWith(networkFirst(request));
});

/* =========================
   PUSH NOTIFICATIONS
========================= */

self.addEventListener('push', event => {
    console.log('Push event received');

    let data = {};

    try {
        if (event.data) {
            const rawPayload = event.data.text();

            console.log('Push payload:', rawPayload);

            try {
                data = JSON.parse(rawPayload);
            } catch {
                data = {
                    title: 'Squadron Tracker',
                    body: rawPayload
                };
            }
        }
    } catch (err) {
        console.error('Push processing error:', err);
    }

    const options = {
        body: data.body || 'Score update available',
        icon: data.icon || '/pwa-icon.php?size=192',
        badge: data.badge || '/pwa-icon.php?size=192',
        tag: data.tag || `update-${Date.now()}`,
        renotify: true,
        requireInteraction: false,
        data: {
            url: data?.data?.url || '/index.php',
            squadron_id: data?.data?.squadron_id || null,
            type: data?.data?.type || null
        }
    };

    event.waitUntil(
        self.registration.showNotification(
            data.title || 'Squadron Tracker',
            options
        )
    );
});

/* =========================
   NOTIFICATION CLICK
========================= */

self.addEventListener('notificationclick', event => {
    event.notification.close();

    const targetUrl = new URL(
        event.notification?.data?.url || '/index.php',
        self.location.origin
    ).href;

    event.waitUntil(
        clients.matchAll({
            type: 'window',
            includeUncontrolled: true
        }).then(clientList => {

            for (const client of clientList) {
                if (client.url === targetUrl && 'focus' in client) {
                    return client.focus();
                }
            }

            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});

/* =========================
   BADGE SUPPORT
========================= */

self.addEventListener('message', event => {
    if (!event.data) {
        return;
    }

    if (event.data.type === 'UPDATE_BADGE') {
        const count = Number(event.data.count || 0);

        if ('setAppBadge' in self.registration) {
            if (count <= 0) {
                self.registration.clearAppBadge();
            } else {
                self.registration.setAppBadge(count);
            }
        }
    }
});

/* =========================
   ERROR LOGGING
========================= */

self.addEventListener('error', event => {
    console.error('Service Worker Error:', event.error);
});

self.addEventListener('unhandledrejection', event => {
    console.error('Unhandled Promise Rejection:', event.reason);
});
