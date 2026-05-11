// sw.js - Service Worker for KNMI Weather App
const CACHE_NAME = 'knmi-weather-v1.0.0.1';
const urlsToCache = [
    // '/',
    // '/index.php',
    // '/css/modern-style.css',
    // '/js/weather-api.js',
    // '/js/weather-app.js',
    // '/js/chart-manager.js',
    // '/manifest.json',
    // External dependencies (cached for offline use)
    'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.1/font/bootstrap-icons.min.css'
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

// Fetch event - serve cached content when offline
self.addEventListener('fetch', event => {
    // Skip non-GET requests
    if (event.request.method !== 'GET') {
        return;
    }

    // Skip Chrome extension requests
    if (event.request.url.startsWith('chrome-extension://')) {
        return;
    }

    event.respondWith(
        caches.match(event.request)
            .then(response => {
                // Return cached version if available
                if (response) {
                    console.log('Service Worker: Serving from cache', event.request.url);
                    return response;
                }

                // Clone the request because it can only be used once
                const fetchRequest = event.request.clone();

                return fetch(fetchRequest)
                    .then(response => {
                        // Check if response is valid
                        if (!response || response.status !== 200 || response.type !== 'basic') {
                            return response;
                        }

                        // Clone the response because it can only be used once
                        const responseToCache = response.clone();

                        // Don't cache API responses (they change frequently)
                        if (!event.request.url.includes('/api/')) {
                            caches.open(CACHE_NAME)
                                .then(cache => {
                                    cache.put(event.request, responseToCache);
                                });
                        }

                        return response;
                    })
                    .catch(error => {
                        console.log('Service Worker: Fetch failed, serving offline page', error);
                        
                        // For API requests, return a custom offline response
                        if (event.request.url.includes('/api/')) {
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
                                        'Content-Type': 'application/json'
                                    }
                                }
                            );
                        }

                        // For HTML requests, try to serve cached index
                        if (event.request.headers.get('accept').includes('text/html')) {
                            return caches.match('/');
                        }
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
        icon: '/icons/icon-192x192.png',
        badge: '/icons/badge-72x72.png',
        tag: 'weather-update',
        renotify: true,
        data: data.url || '/',
        actions: [
            {
                action: 'view',
                title: 'Bekijken',
                icon: '/icons/view-action.png'
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