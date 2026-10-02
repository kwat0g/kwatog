import { expect, test } from '@playwright/test';

test('the real front door omits server version details', async ({ request }) => {
  const spa = await request.get('/');
  const api = await request.get('/api/v1/auth/user');
  const document = await request.get('/api/v1/documents/1/view');

  expect(spa.status()).toBe(200);
  expect(api.status()).toBe(401);
  expect(document.status()).toBe(401);
  for (const headers of [spa.headers(), api.headers(), document.headers()]) {
    expect(headers.server ?? '').not.toMatch(/\/\d/);
    expect(headers['x-powered-by']).toBeUndefined();
  }
});

test('the real front door keeps baseline headers and does not reflect hostile CORS origins', async ({ request }) => {
  const spa = await request.get('/');
  const headers = spa.headers();
  expect(headers['strict-transport-security']).toContain('max-age=31536000');
  expect(headers['x-frame-options']).toBe('DENY');
  expect(headers['x-content-type-options']).toBe('nosniff');
  expect(headers['content-security-policy']).toContain("object-src 'none'");

  const preflight = await request.fetch('/api/v1/auth/sign-in', {
    method: 'OPTIONS',
    headers: {
      Origin: 'https://evil.example',
      'Access-Control-Request-Method': 'POST',
    },
  });
  expect(preflight.status()).toBe(204);
  expect(preflight.headers()['access-control-allow-origin']).not.toBe('https://evil.example');
});
