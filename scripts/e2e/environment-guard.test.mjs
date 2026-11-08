import assert from 'node:assert/strict';
import test from 'node:test';
import { assertE2EEnvironment, mergeRunnerEnvironment } from './environment-guard.mjs';

const safeEnvironment = {
  E2E_ENV: 'true',
  E2E_DB_NAME: 'ogami_e2e',
  E2E_COMPOSE_PROJECT: 'ogami-erp-e2e',
  E2E_BASE_URL: 'https://127.0.0.1:18443',
  APP_ENV: 'testing',
  DB_CONNECTION: 'pgsql',
  DB_HOST: 'db',
  DB_DATABASE: 'ogami_e2e',
};

test('accepts the dedicated E2E environment and returns only safe identifiers', () => {
  assert.deepEqual(assertE2EEnvironment(safeEnvironment), {
    baseURL: 'https://127.0.0.1:18443',
    database: 'ogami_e2e',
    project: 'ogami-erp-e2e',
  });
});

test('refuses to run without the explicit E2E flag', () => {
  assert.throws(() => assertE2EEnvironment({ ...safeEnvironment, E2E_ENV: 'false' }), /E2E_ENV/);
});

test('refuses any database other than the dedicated E2E database', () => {
  assert.throws(() => assertE2EEnvironment({ ...safeEnvironment, DB_DATABASE: 'ogami' }), /database/);
});

test('refuses an unlisted, remote or production-looking base URL', () => {
  assert.throws(() => assertE2EEnvironment({ ...safeEnvironment, E2E_BASE_URL: 'https://erp.example.com' }), /allowlist/);
});

test('refuses an unexpected Compose project even if the URL and database look safe', () => {
  assert.throws(() => assertE2EEnvironment({ ...safeEnvironment, E2E_COMPOSE_PROJECT: 'ogami' }), /project/);
});

test('accepts only the private in-Compose nginx origin for a browser container', () => {
  assert.equal(assertE2EEnvironment({ ...safeEnvironment, E2E_BASE_URL: 'https://nginx' }).baseURL, 'https://nginx');
  assert.throws(() => assertE2EEnvironment({ ...safeEnvironment, E2E_BASE_URL: 'http://nginx' }), /allowlist/);
  assert.throws(() => assertE2EEnvironment({ ...safeEnvironment, E2E_BASE_URL: 'http://api' }), /allowlist/);
});

test('allows an explicit actor selector to override its empty E2E file value only', () => {
  const merged = mergeRunnerEnvironment(
    { E2E_AUTH_ACTORS: 'employee,department_head', E2E_DB_NAME: 'unsafe' },
    { E2E_AUTH_ACTORS: '', E2E_DB_NAME: 'ogami_e2e' },
  );

  assert.equal(merged.E2E_AUTH_ACTORS, 'employee,department_head');
  assert.equal(merged.E2E_DB_NAME, 'ogami_e2e');
});
