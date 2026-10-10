import { afterEach, describe, expect, it, vi } from 'vitest';
import { createRequestKey } from './requestKey';

afterEach(() => vi.unstubAllGlobals());

describe('operation identity on HTTP deployments', () => {
  it('generates valid independent UUIDs when randomUUID is unavailable', () => {
    const getRandomValues = globalThis.crypto.getRandomValues.bind(globalThis.crypto);
    vi.stubGlobal('crypto', { getRandomValues });
    const first = createRequestKey();
    const second = createRequestKey();
    expect(first).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    expect(second).not.toBe(first);
  });
});
