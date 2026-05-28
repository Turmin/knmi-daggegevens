// sw.js - Service Worker for KNMI Weather App
const CACHE_NAME = 'knmi-weather-v1.1.3';
const APP_SHELL_URL = new URL('./', self.location.href).href;
const urlsToCache = [
    './',
    './manifest.json',
    './icons/favicon.ico',
    './icons/favicon-16x16.png',
    './icons/favicon-32x32.png',
    './icons/android-chrome-192x192.png',
    './icons/android-chrome-512x512.png',
    './icons/apple-touch-icon.png',
    './css/modern-style.css',
    './js/app-i18n.js',
    './js/weather-api.js',
    './js/weather-app.js',
    './js/chart-manager.js'
];

// Install event - cache resources
self.addEventListener('install', event => {
    console.log('Service Worker: Installing...');
    
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('Service Worker: Caching files');
                return cache.addAll(urlsToCache);
            })
            .then(() => {
                console.log('Service Worker: Cached all files successfully');
                return self.skipWaiting();
            })
            .catch(error => {
                console.error('Service Worker: Failed to cache files', error);
            })
    );
});

// Activate event - clean up old caches
self.addEventListener('activate', event => {
    console.log('Service Worker: Activating...');
    
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheName !== CACHE_NAME) {
                        console.log('Service Worker: Deleting old cache', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(() => {
            console.log('Service Worker: Activated successfully');
            return self.clients.claim();
        })
    );
});

// Fetch event - use fresh network data first, with cache only as an offline fallback
self.addEventListener('fetch', event => {
    // Skip non-GET requests
    if (event.request.method !== 'GET') {
        return;
    }

    // Skip Chrome extension requests
    if (event.request.url.startsWith('chrome-extension://')) {
        return;
    }

    const requestUrl = new URL(event.request.url);
    const isApiRequest = requestUrl.pathname.includes('/api/');
    const isSameOrigin = requestUrl.origin === self.location.origin;

    if (isApiRequest) {
        event.respondWith(
            fetch(event.request, { cache: 'no-store' }).catch(() => {
                return new Response(
                    JSON.stringify({
                        success: false,
                        error: {
                            code: 503,
                            message: 'Geen internetverbinding beschikbaar'
                        },
                        offline: true
                    }),
                    {
                        status: 503,
                        statusText: 'Service Unavailable',
                        headers: {
                            'Content-Type': 'application/json',
                            'Cache-Control': 'no-store'
                        }
                    }
                );
            })
        );
        return;
    }

    if (isSameOrigin) {
        event.respondWith(
            fetch(event.request)
                .then(response => {
                    if (response && response.status === 200 && response.type === 'basic') {
                        const responseToCache = response.clone();
                        caches.open(CACHE_NAME).then(cache => {
                            cache.put(event.request, responseToCache);
                        });
                    }

                    return response;
                })
                .catch(() => {
                    return caches.match(event.request).then(cachedResponse => {
                        if (cachedResponse) return cachedResponse;

                        if (event.request.headers.get('accept')?.includes('text/html')) {
                            return caches.match(APP_SHELL_URL);
                        }

                        return new Response('', { status: 504, statusText: 'Offline' });
                    });
                })
        );
        return;
    }

    event.respondWith(
        caches.match(event.request).then(cachedResponse => {
            return cachedResponse || fetch(event.request).then(response => {
                if (response && response.status === 200) {
                    const responseToCache = response.clone();
                    caches.open(CACHE_NAME).then(cache => {
                        cache.put(event.request, responseToCache);
                    });
                }

                return response;
            });
        })
    );
});

// Background sync for API requests when back online
self.addEventListener('sync', event => {
    if (event.tag === 'weather-data-sync') {
        console.log('Service Worker: Background sync triggered');
        event.waitUntil(syncWeatherData());
    }
});

// Push notifications (future feature)
self.addEventListener('push', event => {
    if (!event.data) return;

    const data = event.data.json();
    const title = data.title || 'KNMI Weer Update';
    const options = {
        body: data.body || 'Nieuwe weergegevens beschikbaar',
        icon: new URL('icons/android-chrome-192x192.png', self.location.href).href,
        badge: new URL('icons/android-chrome-192x192.png', self.location.href).href,
        tag: 'weather-update',
        renotify: true,
        data: data.url || '/',
        actions: [
            {
                action: 'view',
                title: 'Bekijken',
                icon: new URL('icons/android-chrome-192x192.png', self.location.href).href
            },
            {
                action: 'close',
                title: 'Sluiten'
            }
        ]
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

// Notification click handler
self.addEventListener('notificationclick', event => {
    event.notification.close();

    if (event.action === 'view') {
        event.waitUntil(
            clients.openWindow(event.notification.data)
        );
    }
});

// Message handler for communication with main thread
self.addEventListener('message', event => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    
    if (event.data && event.data.type === 'GET_VERSION') {
        event.ports[0].postMessage({ version: CACHE_NAME });
    }
});

// Sync function for background data updates
async function syncWeatherData() {
    try {
        // This would sync any pending API requests
        // Implementation depends on your specific caching strategy
        console.log('Service Worker: Syncing weather data...');
        
        // Clear old cached API responses
        const cache = await caches.open(CACHE_NAME);
        const keys = await cache.keys();
        
        const apiKeys = keys.filter(key => key.url.includes('/api/'));
        await Promise.all(apiKeys.map(key => cache.delete(key)));
        
        console.log('Service Worker: Weather data sync completed');
        return Promise.resolve();
    } catch (error) {
        console.error('Service Worker: Weather data sync failed', error);
        return Promise.reject(error);
    }
}

// Periodic background sync (if supported)
self.addEventListener('periodicsync', event => {
    if (event.tag === 'weather-update') {
        event.waitUntil(syncWeatherData());
    }
});

console.log('Service Worker: Loaded successfully');
