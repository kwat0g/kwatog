const requestedBuildId = new URL(self.location.href).searchParams.get('build') || '';
const CACHE_NAME = /^[a-zA-Z0-9._-]{1,80}$/.test(requestedBuildId)
  ? `ogami-pwa-${requestedBuildId}`
  : 'ogami-pwa-v4';
const PRECACHE_URLS = [
  '/factory-manifest.webmanifest',
  '/driver-manifest.webmanifest',
  '/driver-icon-192.png',
  '/driver-icon-512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    Promise.all([
      caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_URLS)),
      self.skipWaiting(),
    ])
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key.startsWith('ogami-') && key !== CACHE_NAME)
          .map((key) => caches.delete(key))
      )
    ).then(() => self.clients.claim())
  );
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
            void caches.open(CACHE_NAME)
              .then((cache) => cache.put('/', clone))
              .catch(() => undefined);
          }
          return response;
        })
        .catch(async () => {
          try {
            const cache = await caches.open(CACHE_NAME);
            return (await cache.match('/')) ?? Response.error();
          } catch {
            return Response.error();
          }
        })
    );
    return;
  }

  // Only Vite's versioned build output is safe for cache-first reuse. Other
  // same-origin GETs may be authenticated pages, exports, or signed files;
  // leave them network-only even when their URL is outside /api/.
  if (!url.pathname.startsWith('/assets/')) return;

  // Vite names production assets by content hash, so a cached URL is
  // immutable. Avoid a redundant network request on every cache hit; a new
  // index.html points at new hashes after deployment. If Cache Storage fails,
  // the asset still loads directly from the network.
  event.respondWith((async () => {
    let cache;
    try {
      cache = await caches.open(CACHE_NAME);
      const cached = await cache.match(event.request);
      if (cached) return cached;
    } catch {
      return fetch(event.request);
    }

    const response = await fetch(event.request);
    if (response.ok) {
      try {
        await cache.put(event.request, response.clone());
      } catch {
        // Cache quota or storage failures must not block the network response.
      }
    }
    return response;
  })());
});
