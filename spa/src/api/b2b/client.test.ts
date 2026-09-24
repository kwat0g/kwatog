import { beforeEach, describe, expect, it, vi } from 'vitest';
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
    const customer = createPortalClient('customer');

    expect(supplier.client).not.toBe(customer.client);
    expect(supplier.client.defaults.headers.common.Authorization).toBeUndefined();
    expect(customer.client.defaults.headers.common.Authorization).toBeUndefined();
  });

  it('routes an expired password to that portal realm password-change page', async () => {
    const { client } = createPortalClient('customer');
    const assign = vi.fn();
    vi.stubGlobal('window', { location: { pathname: '/portal/customer/orders', assign } });
    const reject = client.interceptors.response.handlers?.[0]?.rejected as ((error: unknown) => Promise<never>) | undefined;
    const error = Object.assign(new Error('Password expired'), {
      isAxiosError: true,
      response: { status: 403, data: { code: 'password_expired' } },
    });

    try {
      await expect(reject!(error)).rejects.toBe(error);
      expect(assign).toHaveBeenCalledWith('/portal/customer/change-password');
    } finally {
      vi.unstubAllGlobals();
    }
  });
});
