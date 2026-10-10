import http from 'k6/http';

export const BASE_URL = __ENV.BASE_URL || 'http://localhost:18080';
const LOAD_TEST_PASSWORD = 'password';

export function loadTestEmail(role, userId) {
  return `audit-load-${role}-${String(userId).padStart(3, '0')}@ogami.test`;
}

export function getStatefulHeaders(baseUrl) {
  const origin = baseUrl.replace(/\/+$/, '');
  return {
    Origin: origin,
    Referer: `${origin}/`,
  };
}

/**
 * Authenticate using Laravel Sanctum SPA mode (HTTP-only cookies).
 * 1. GET /sanctum/csrf-cookie — extracts XSRF-TOKEN from Set-Cookie header
 * 2. POST /api/v1/auth/login — sends credentials + X-XSRF-TOKEN header
 * 3. Returns cookies from the jar for subsequent requests
 *
 * @param {string} baseUrl
 * @param {string} email
 * @returns {string} cookie string for subsequent requests
 */
export function getAuthCookie(baseUrl, email) {
  if (!email) {
    throw new Error('Load-test user email is required');
  }

  const jar = http.cookieJar();
  jar.clear(baseUrl);
  const statefulHeaders = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    ...getStatefulHeaders(baseUrl),
  };

  // Step 1: fetch CSRF cookie
  const csrfRes = http.get(`${baseUrl}/sanctum/csrf-cookie`, {
    headers: statefulHeaders,
  });

  // Extract XSRF-TOKEN value from Set-Cookie headers
  let xsrfToken = '';
  const setCookieHeader = csrfRes.headers['Set-Cookie'] || '';
  const xsrfMatch = setCookieHeader.match(/XSRF-TOKEN=([^;]+)/);
  if (xsrfMatch) {
    // URL-decode the token (Laravel URL-encodes the "=" padding characters)
    xsrfToken = decodeURIComponent(xsrfMatch[1]);
  }

  // Step 2: log in with credentials and CSRF token
  const loginRes = http.post(
    `${baseUrl}/api/v1/auth/login`,
    JSON.stringify({ email, password: LOAD_TEST_PASSWORD }),
    {
      headers: {
        ...statefulHeaders,
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': xsrfToken,
      },
    },
  );

  if (loginRes.status !== 200) {
    console.error(
      `Login failed: HTTP ${loginRes.status} — ${loginRes.body}`,
    );
    return '';
  }

  // Step 3: return cookies from the jar without assuming the session-cookie name.
  const cookies = jar.cookiesForURL(baseUrl);
  const parts = [];
  for (const [name, values] of Object.entries(cookies)) {
    for (const v of values) {
      parts.push(`${name}=${v}`);
    }
  }
  return parts.join('; ');
}
