const CACHE_VERSION = 'qa-system-v1';
const APP_SHELL = [
  './',
  './index.php',
  './views/feed.php',
  './assets/css/index.css',
  './assets/css/global.css',
  './assets/css/auth.css',
  './assets/css/dashboard.css',
  './assets/css/modules.css',
  './assets/js/ame-activity.js',
  './assets/js/pwa.js',
  './assets/img/QAO_logo.png',
  './assets/img/NBSC_logo.png',
  './assets/img/pwa-icon-192.png',
  './assets/img/pwa-icon-512.png'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_VERSION)
      .then(cache => cache.addAll(APP_SHELL))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(key => key !== CACHE_VERSION).map(key => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);

  if (request.method !== 'GET' || url.pathname.includes('/api/')) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then(response => {
          const copy = response.clone();
          caches.open(CACHE_VERSION).then(cache => cache.put(request, copy));
          return response;
        })
        .catch(() => caches.match(request).then(cached => cached || caches.match('./views/feed.php') || caches.match('./index.php')))
    );
    return;
  }

  event.respondWith(
    caches.match(request).then(cached => {
      if (cached) {
        return cached;
      }

      return fetch(request).then(response => {
        if (!response || response.status !== 200 || response.type === 'opaque') {
          return response;
        }

        const copy = response.clone();
        caches.open(CACHE_VERSION).then(cache => cache.put(request, copy));
        return response;
      });
    })
  );
});
