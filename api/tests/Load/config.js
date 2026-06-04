import http from 'k6/http';

export const BASE_URL = __ENV.BASE_URL || 'http://localhost';
export const ADMIN_EMAIL = __ENV.ADMIN_EMAIL || 'admin@ogami.test';
export const ADMIN_PASSWORD = __ENV.ADMIN_PASSWORD || 'Password1!';

/**
 * Authenticate using Laravel Sanctum SPA mode (HTTP-only cookies).
 * 1. GET /sanctum/csrf-cookie — extracts XSRF-TOKEN from Set-Cookie header
 * 2. POST /api/v1/auth/login — sends credentials + X-XSRF-TOKEN header
 * 3. Returns the full session cookie string for use in subsequent requests
 *
 * @param {string} baseUrl
 * @param {string} [email]
 * @param {string} [password]
 * @returns {string} session cookie string (e.g. "laravel_session=abc123...")
 */
export function getAuthCookie(baseUrl, email, password) {
  const loginEmail = email || ADMIN_EMAIL;
  const loginPassword = password || ADMIN_PASSWORD;

  // Step 1: fetch CSRF cookie
  const csrfRes = http.get(`${baseUrl}/sanctum/csrf-cookie`, {
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
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
    JSON.stringify({ email: loginEmail, password: loginPassword }),
    {
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
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

  // Step 3: collect session cookie from login response
  const loginSetCookie = loginRes.headers['Set-Cookie'] || '';
  const sessionMatch = loginSetCookie.match(/(laravel_session=[^;]+)/);
  if (sessionMatch) {
    return sessionMatch[1];
  }

  // Fallback: return all cookies from the jar as a joined string so the
  // caller can pass them verbatim in a Cookie header.
  const jar = http.cookieJar();
  const cookies = jar.cookiesForURL(baseUrl);
  const parts = [];
  for (const [name, values] of Object.entries(cookies)) {
    for (const v of values) {
      parts.push(`${name}=${v}`);
    }
  }
  return parts.join('; ');
}
