import { beforeEach, describe, expect, it } from 'vitest';
import { createPortalClient } from './client';

describe('createPortalClient', () => {
  beforeEach(() => window.sessionStorage.clear());

  it('attaches and clears the bearer token', () => {
    const { client, setToken } = createPortalClient();

    setToken('portal-token');
    expect(client.defaults.headers.common.Authorization).toBe('Bearer portal-token');

    setToken(null);
    expect(client.defaults.headers.common.Authorization).toBeUndefined();
  });

  it('creates isolated clients for each portal', () => {
    const supplier = createPortalClient();
    const customer = createPortalClient();

    supplier.setToken('supplier-token');

    expect(supplier.client.defaults.headers.common.Authorization).toBe('Bearer supplier-token');
    expect(customer.client.defaults.headers.common.Authorization).toBeUndefined();
  });

  it('restores a portal token after a hard navigation in the same tab', () => {
    const firstClient = createPortalClient('supplier-token');
    firstClient.setToken('persisted-token');

    const reloadedClient = createPortalClient('supplier-token');

    expect(reloadedClient.client.defaults.headers.common.Authorization).toBe('Bearer persisted-token');
    reloadedClient.setToken(null);
    expect(window.sessionStorage.getItem('supplier-token')).toBeNull();
  });
});
