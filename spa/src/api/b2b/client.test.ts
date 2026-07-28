import { describe, expect, it } from 'vitest';
import { createPortalClient } from './client';

describe('createPortalClient', () => {
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
});
