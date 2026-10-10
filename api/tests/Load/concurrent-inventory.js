/**
 * concurrent-inventory.js
 *
 * Simulates concurrent authenticated clients querying inventory.
 * Setup authenticates once, then each virtual user repeatedly hits the two
 * most common read endpoints:
 *   - GET /api/v1/inventory/stock-levels   (paginated list)
 *   - GET /api/v1/inventory/items          (search by item code)
 *
 * Pass-criteria (enforced by thresholds):
 *   p95 response time < 2 000 ms
 *   Error rate        < 1 %
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { BASE_URL, getAuthCookie, getStatefulHeaders, loadTestEmail } from './config.js';

export const options = {
  vus: 200,
  duration: '60s',
  thresholds: {
    // 95th-percentile response time must stay under 2 s
    http_req_duration: ['p(95)<2000'],
    // Fewer than 1 % of requests may fail
    http_req_failed: ['rate<0.01'],
  },
};

// Item code prefixes used for realistic search simulation
const ITEM_CODE_PREFIXES = [
  'RAW-', 'FG-', 'WIP-', 'PKG-', 'TOOL-',
  'RESIN-', 'PIGM-', 'INSERT-', 'BUSH-', 'CAP-',
];

// Guests share a 60-request/minute IP cap during setup; 50 logins leave headroom.
// At the default 200 VUs this shares four read-only sessions per user, below
// the authenticated 300-request/minute per-user limit.
const SESSION_COUNT = 50;

/** Return a random integer between min and max (inclusive). */
function randInt(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

/** Pick a random element from an array. */
function sample(arr) {
  return arr[Math.floor(Math.random() * arr.length)];
}

/** Generate a plausible item code search term. */
function randomItemCode() {
  return sample(ITEM_CODE_PREFIXES) + String(randInt(1000, 9999));
}

export function setup() {
  const sessions = [];
  for (let userId = 1; userId <= SESSION_COUNT; userId++) {
    const email = loadTestEmail('warehouse', userId);
    const cookie = getAuthCookie(BASE_URL, email);
    if (!cookie) {
      throw new Error(`Load-test authentication failed for ${email}`);
    }
    sessions.push({ email, cookie });
  }
  return { sessions };
}

export default function (data) {
  const { cookie: sessionCookie } = data.sessions[(__VU - 1) % data.sessions.length];

  const headers = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    ...getStatefulHeaders(BASE_URL),
    Cookie: sessionCookie,
  };

  // --------------------------------------------------------- request loop --
  for (let i = 0; i < 5; i++) {
    // 1. Paginated stock-levels list
    const stockRes = http.get(
      `${BASE_URL}/api/v1/inventory/stock-levels?page=1&per_page=25`,
      { headers },
    );

    check(stockRes, {
      'stock-levels status 200': (r) => r.status === 200,
      'stock-levels response time < 2000ms': (r) => r.timings.duration < 2000,
      'stock-levels has data key': (r) => {
        try {
          const body = JSON.parse(r.body);
          return body.data !== undefined;
        } catch {
          return false;
        }
      },
    });

    sleep(randInt(1, 3));

    // 2. Item search by random code
    const searchCode = randomItemCode();
    const itemRes = http.get(
      `${BASE_URL}/api/v1/inventory/items?search=${encodeURIComponent(searchCode)}&page=1&per_page=25`,
      { headers },
    );

    check(itemRes, {
      'items status 200': (r) => r.status === 200,
      'items response time < 2000ms': (r) => r.timings.duration < 2000,
      'items returns array': (r) => {
        try {
          const body = JSON.parse(r.body);
          return Array.isArray(body.data);
        } catch {
          return false;
        }
      },
    });

    sleep(randInt(1, 3));
  }
}
