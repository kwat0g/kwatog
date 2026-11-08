import { afterEach, describe, expect, it, vi } from 'vitest';
import { clearPwaCaches } from '@/sw-register';

describe('clearPwaCaches', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('removes all Ogami-owned shell caches without deleting another app cache', async () => {
    const deleteCache = vi.fn().mockResolvedValue(true);
    vi.stubGlobal('caches', {
      keys: vi.fn().mockResolvedValue(['ogami-pwa-v1', 'ogami-pwa-v2', 'other-app-cache']),
      delete: deleteCache,
    } as unknown as CacheStorage);

    await expect(clearPwaCaches()).resolves.toBe(true);

    expect(deleteCache.mock.calls.map(([name]) => name)).toEqual(['ogami-pwa-v1', 'ogami-pwa-v2']);
  });

  it('reports when an app cache could not be cleared', async () => {
    vi.stubGlobal('caches', {
      keys: vi.fn().mockResolvedValue(['ogami-pwa-v2']),
      delete: vi.fn().mockRejectedValue(new Error('storage unavailable')),
    } as unknown as CacheStorage);

    await expect(clearPwaCaches()).resolves.toBe(false);
  });

  it('succeeds when Cache Storage is unavailable', async () => {
    vi.stubGlobal('caches', undefined);

    await expect(clearPwaCaches()).resolves.toBe(true);
  });
});
