import { beforeEach, describe, expect, it } from 'vitest';
import { createPortalClient } from './client';

describe('createPortalClient', () => {
  beforeEach(() => window.sessionStorage.clear());

  it('defaults to credentialed cookie requests without browser token storage', () => {
    const { client } = createPortalClient();

    expect(client.defaults.withCredentials).toBe(true);
    expect(client.defaults.timeout).toBe(30_000);
    expect(client.defaults.headers.common.Authorization).toBeUndefined();
    expect(window.sessionStorage.length).toBe(0);
  });

  it('creates isolated clients for each portal', () => {
    const supplier = createPortalClient();
    const customer = createPortalClient();

    expect(supplier.client).not.toBe(customer.client);
    expect(supplier.client.defaults.headers.common.Authorization).toBeUndefined();
    expect(customer.client.defaults.headers.common.Authorization).toBeUndefined();
  });
});
