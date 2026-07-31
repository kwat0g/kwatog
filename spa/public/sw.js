const CACHE_NAME = 'ogami-pwa-v2';
const PRECACHE_URLS = [
  '/factory-manifest.webmanifest',
  '/driver-manifest.webmanifest',
  '/driver-icon-192.png',
  '/driver-icon-512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_URLS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key.startsWith('ogami-') && key !== CACHE_NAME)
          .map((key) => caches.delete(key))
      )
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;
  const url = new URL(event.request.url);

  // Only cache same-origin application assets. API/CSRF responses and Vite's
  // development module graph must always come from the network. The latter is
  // defense in depth: sw-register.ts does not install this worker in dev, but
  // an older registered worker can control the first page after an upgrade.
  if (url.origin !== self.location.origin) return;
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/sanctum/')) return;
  if (
    url.pathname.startsWith('/src/') ||
    url.pathname.startsWith('/@') ||
    url.pathname.startsWith('/node_modules/')
  ) return;

  // HTML navigations are network-first so a deployment cannot be paired with
  // an obsolete index document. Fall back to the last successful shell only
  // when the terminal is genuinely offline.
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          if (response.ok) {
            const clone = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put('/', clone));
          }
          return response;
        })
        .catch(() => caches.match('/'))
    );
    return;
  }

  event.respondWith(
    caches.match(event.request).then((cached) => {
      const fetched = fetch(event.request).then((response) => {
        if (response.ok) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
        }
        return response;
      });
      return cached || fetched;
    })
  );
});
