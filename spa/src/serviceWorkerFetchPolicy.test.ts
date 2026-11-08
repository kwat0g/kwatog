import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { runInNewContext } from 'node:vm';
import { describe, expect, it, vi } from 'vitest';

type RequestLike = { method: string; url: string; mode: string };
type FetchEventLike = { request: RequestLike; respondWith: (response: Promise<unknown>) => void };

function loadWorker(caches: unknown, fetch: ReturnType<typeof vi.fn>, buildId?: string) {
  const listeners = new Map<string, (event: FetchEventLike) => void>();
  const workerUrl = buildId
    ? `https://ogamiph.local/sw.js?build=${encodeURIComponent(buildId)}`
    : 'https://ogamiph.local/sw.js';
  const self = {
    location: { origin: 'https://ogamiph.local', href: workerUrl },
    addEventListener: (name: string, listener: (event: FetchEventLike) => void) => listeners.set(name, listener),
    skipWaiting: vi.fn(),
    clients: { claim: vi.fn() },
  };
  const source = readFileSync(resolve(process.cwd(), 'public/sw.js'), 'utf8');
  runInNewContext(source, { self, caches, fetch, URL, Promise });
  return listeners;
}

function loadFetchHandler(caches: unknown, fetch: ReturnType<typeof vi.fn>, buildId?: string) {
  const listeners = loadWorker(caches, fetch, buildId);
  const handler = listeners.get('fetch');
  if (!handler) throw new Error('Service worker did not register a fetch handler.');
  return handler;
}

describe('service worker cache policy', () => {
  it.each(['/api/v1/orders', '/sanctum/csrf-cookie'])(
    'does not intercept transactional or CSRF requests at %s',
    (path) => {
      const cacheMatch = vi.fn();
      const caches = { match: cacheMatch, open: vi.fn() };
      const fetch = vi.fn();
      const handler = loadFetchHandler(caches, fetch);
      const respondWith = vi.fn();

      handler({
        request: { method: 'GET', url: `https://ogamiph.local${path}`, mode: 'cors' },
        respondWith,
      });

      expect(respondWith).not.toHaveBeenCalled();
      expect(caches.open).not.toHaveBeenCalled();
      expect(cacheMatch).not.toHaveBeenCalled();
      expect(fetch).not.toHaveBeenCalled();
    },
  );

  it('never stores same-origin private page and download responses', () => {
    const cacheMatch = vi.fn().mockResolvedValue(undefined);
    const fetch = vi.fn();
    const handler = loadFetchHandler({ match: cacheMatch, open: vi.fn() }, fetch);
    const respondWith = vi.fn();

    handler({
      request: { method: 'GET', url: 'https://ogamiph.local/payroll/payslips/download/123', mode: 'cors' },
      respondWith,
    });

    expect(respondWith).not.toHaveBeenCalled();
    expect(cacheMatch).not.toHaveBeenCalled();
    expect(fetch).not.toHaveBeenCalled();
  });

  it('keeps the runtime cache path for immutable Vite assets', async () => {
    const cacheMatch = vi.fn().mockResolvedValue(undefined);
    const cache = { match: cacheMatch, put: vi.fn() };
    const globalMatch = vi.fn().mockResolvedValue(undefined);
    const caches = { match: globalMatch, open: vi.fn().mockResolvedValue(cache) };
    const response = { ok: true, clone: vi.fn(() => ({})) };
    const fetch = vi.fn().mockResolvedValue(response);
    const handler = loadFetchHandler(caches, fetch);
    let cachedResponse: unknown;

    handler({
      request: { method: 'GET', url: 'https://ogamiph.local/assets/app-abc123.js', mode: 'cors' },
      respondWith: (promise) => { cachedResponse = promise; },
    });

    await expect(cachedResponse).resolves.toBe(response);
    expect(cacheMatch).toHaveBeenCalledTimes(1);
    expect(globalMatch).not.toHaveBeenCalled();
    expect(caches.open).toHaveBeenCalledWith('ogami-pwa-v4');
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it('serves a cached immutable asset without issuing a network request', async () => {
    const cachedAsset = { cached: true };
    const cache = { match: vi.fn().mockResolvedValue(cachedAsset), put: vi.fn() };
    const caches = { open: vi.fn().mockResolvedValue(cache) };
    const fetch = vi.fn();
    const handler = loadFetchHandler(caches, fetch);
    let response: unknown;

    handler({
      request: { method: 'GET', url: 'https://ogamiph.local/assets/app-abc123.js', mode: 'cors' },
      respondWith: (promise) => { response = promise; },
    });

    await expect(response).resolves.toBe(cachedAsset);
    expect(fetch).not.toHaveBeenCalled();
    expect(cache.put).not.toHaveBeenCalled();
  });

  it('uses a cache namespace tied to the registered frontend build', async () => {
    const cachedAsset = { cached: true };
    const cache = { match: vi.fn().mockResolvedValue(cachedAsset), put: vi.fn() };
    const caches = { open: vi.fn().mockResolvedValue(cache) };
    const fetch = vi.fn();
    const handler = loadFetchHandler(caches, fetch, 'release-20261031');
    let response: unknown;

    handler({
      request: { method: 'GET', url: 'https://ogamiph.local/assets/app-abc123.js', mode: 'cors' },
      respondWith: (promise) => { response = promise; },
    });

    await expect(response).resolves.toBe(cachedAsset);
    expect(caches.open).toHaveBeenCalledWith('ogami-pwa-release-20261031');
    expect(fetch).not.toHaveBeenCalled();
  });

  it('uses only the app-owned cache for the offline navigation shell', async () => {
    const offlineShell = { cachedByOgami: true };
    const cache = { match: vi.fn().mockResolvedValue(offlineShell), put: vi.fn() };
    const globalMatch = vi.fn().mockResolvedValue({ cachedByAnotherApp: true });
    const caches = { match: globalMatch, open: vi.fn().mockResolvedValue(cache) };
    const fetch = vi.fn().mockRejectedValue(new Error('offline'));
    const handler = loadFetchHandler(caches, fetch);
    let response: unknown;

    handler({
      request: { method: 'GET', url: 'https://ogamiph.local/factory', mode: 'navigate' },
      respondWith: (promise) => { response = promise; },
    });

    await expect(response).resolves.toBe(offlineShell);
    expect(caches.open).toHaveBeenCalledWith('ogami-pwa-v4');
    expect(cache.match).toHaveBeenCalledWith('/');
    expect(globalMatch).not.toHaveBeenCalled();
  });

  it('deletes all previous Ogami cache versions during activation', async () => {
    const deleteCache = vi.fn().mockResolvedValue(true);
    const caches = {
      keys: vi.fn().mockResolvedValue(['ogami-pwa-v1', 'ogami-pwa-v2', 'ogami-pwa-v3', 'other-app-cache']),
      delete: deleteCache,
    };
    const activate = loadWorker(caches, vi.fn()).get('activate');
    if (!activate) throw new Error('Service worker did not register an activate handler.');
    let activation: Promise<unknown> | undefined;

    activate({
      waitUntil: (promise: Promise<unknown>) => { activation = promise; },
    } as unknown as FetchEventLike);
    await activation;

    expect(deleteCache.mock.calls.map(([name]) => name)).toEqual(['ogami-pwa-v1', 'ogami-pwa-v2', 'ogami-pwa-v3']);
  });
});
