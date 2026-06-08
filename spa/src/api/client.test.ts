import { describe, it, expect } from 'vitest';
import { client, unwrappingClient } from '@/api/client';

// Smoke tests for the axios client. The interceptors do real work in
// production (toast, redirects, log capture) and we don't want to dive
// into mocking them here — we just verify the base configuration that
// every request inherits.
describe('api/client', () => {
  it('targets the /api/v1 prefix with credentials', () => {
    expect(client.defaults.baseURL).toBe('/api/v1');
    expect(client.defaults.withCredentials).toBe(true);
  });

  it('sends JSON accept + ajax marker headers', () => {
    expect(client.defaults.headers.Accept).toBe('application/json');
    expect(client.defaults.headers['X-Requested-With']).toBe('XMLHttpRequest');
  });

  it('has a 30s default timeout', () => {
    expect(client.defaults.timeout).toBe(30_000);
  });

  it('unwrappingClient shares the same base configuration', () => {
    expect(unwrappingClient.defaults.baseURL).toBe('/api/v1');
    expect(unwrappingClient.defaults.withCredentials).toBe(true);
    expect(unwrappingClient.defaults.timeout).toBe(30_000);
  });
});
