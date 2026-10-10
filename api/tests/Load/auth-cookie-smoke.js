import http from 'k6/http';
import { check } from 'k6';
import { BASE_URL, getAuthCookie, getStatefulHeaders, loadTestEmail } from './config.js';

export const options = {
  vus: 5,
  iterations: 10,
  thresholds: {
    checks: ['rate==1'],
    http_req_failed: ['rate<0.01'],
  },
};

export function setup() {
  const sessions = [];
  for (let userId = 1; userId <= 5; userId++) {
    const email = loadTestEmail('warehouse', userId);
    const sessionCookie = getAuthCookie(BASE_URL, email);
    if (!sessionCookie) {
      throw new Error(`Load-test authentication failed for ${email}`);
    }
    sessions.push({ email, cookie: sessionCookie });
  }
  return { sessions };
}

export default function (data) {
  const session = data.sessions[(__VU - 1) % data.sessions.length];
  const response = http.get(`${BASE_URL}/api/v1/auth/user`, {
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...getStatefulHeaders(BASE_URL),
      Cookie: session.cookie,
    },
  });

  check(response, {
    'VU session belongs to its warehouse test account': (r) => {
      try {
        const user = JSON.parse(r.body).data;
        return r.status === 200 && user.email === session.email && user.role.slug === 'warehouse_staff';
      } catch {
        return false;
      }
    },
  });
}
