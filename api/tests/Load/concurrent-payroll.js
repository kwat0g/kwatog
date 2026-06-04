/**
 * concurrent-payroll.js
 *
 * Simulates concurrent access to the payroll module — a critical financial
 * operation that must be serialized correctly under load.
 *
 * This script deliberately uses a LOW VU count (10) because payroll
 * endpoints acquire row-level locks and involve heavy DB computation.
 * The goal is to verify:
 *   1. No 500 Internal Server Errors under concurrent access
 *   2. Proper serialization — responses are either 200 OK or 403 Forbidden
 *      (if another period is being finalized), never 500
 *   3. Response bodies are valid JSON with the expected structure
 *
 * Pass-criteria (enforced by thresholds):
 *   p95 response time < 5 000 ms   (payroll is a heavier operation)
 *   Error rate        < 1 %
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { BASE_URL, getAuthCookie } from './config.js';

export const options = {
  vus: 10,
  duration: '120s',
  thresholds: {
    // Payroll is allowed up to 5 s at p95
    http_req_duration: ['p(95)<5000'],
    // Fewer than 1 % of requests may fail at the HTTP transport level
    http_req_failed: ['rate<0.01'],
  },
};

export default function () {
  // ------------------------------------------------------------------ auth --
  const sessionCookie = getAuthCookie(BASE_URL);
  if (!sessionCookie) {
    console.error('VU authentication failed — skipping iteration');
    return;
  }

  const headers = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    Cookie: sessionCookie,
  };

  // -------------------------------------------------- list payroll periods --
  const periodsRes = http.get(
    `${BASE_URL}/api/v1/payroll/periods?page=1&per_page=10`,
    { headers },
  );

  // Acceptable statuses:
  //   200 — success
  //   403 — user lacks payroll.view permission (expected for non-payroll users)
  //   No 500s allowed — that would indicate a serialization failure
  const periodsOk = check(periodsRes, {
    'payroll/periods no server error': (r) =>
      r.status !== 500 && r.status !== 502 && r.status !== 503,
    'payroll/periods status 200 or 403': (r) =>
      r.status === 200 || r.status === 403,
    'payroll/periods response time < 5000ms': (r) =>
      r.timings.duration < 5000,
    'payroll/periods valid JSON body': (r) => {
      try {
        JSON.parse(r.body);
        return true;
      } catch {
        return false;
      }
    },
  });

  // Only check response structure when we actually have access
  if (periodsRes.status === 200) {
    check(periodsRes, {
      'payroll/periods has data array': (r) => {
        try {
          const body = JSON.parse(r.body);
          return Array.isArray(body.data);
        } catch {
          return false;
        }
      },
      'payroll/periods has meta pagination': (r) => {
        try {
          const body = JSON.parse(r.body);
          return (
            body.meta !== undefined &&
            typeof body.meta.total === 'number' &&
            typeof body.meta.per_page === 'number'
          );
        } catch {
          return false;
        }
      },
    });

    // If there are periods, inspect the latest one
    let latestPeriodId = null;
    try {
      const body = JSON.parse(periodsRes.body);
      if (body.data && body.data.length > 0) {
        latestPeriodId = body.data[0].id;
      }
    } catch {
      // ignore parse errors — already checked above
    }

    if (latestPeriodId) {
      const detailRes = http.get(
        `${BASE_URL}/api/v1/payroll/periods/${latestPeriodId}`,
        { headers },
      );

      check(detailRes, {
        'payroll period detail no server error': (r) =>
          r.status !== 500 && r.status !== 502 && r.status !== 503,
        'payroll period detail 200 or 403': (r) =>
          r.status === 200 || r.status === 403,
        'payroll period detail response time < 5000ms': (r) =>
          r.timings.duration < 5000,
        'payroll period detail has status field': (r) => {
          if (r.status !== 200) return true; // skip structural check for 403
          try {
            const body = JSON.parse(r.body);
            const period = body.data || body;
            return period.status !== undefined;
          } catch {
            return false;
          }
        },
      });

      sleep(1);
    }
  }

  // Simulate realistic think-time between payroll operations
  sleep(2);
}
