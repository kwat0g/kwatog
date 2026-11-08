import assert from 'node:assert/strict';
import test from 'node:test';
import { clearTransientRedis } from './baseline.mjs';

test('clears only the isolated queue and cache Redis stores before seeding', () => {
  const calls = [];
  const environment = { E2E_ENV: 'true', E2E_DB_NAME: 'ogami_e2e', E2E_COMPOSE_PROJECT: 'ogami-erp-e2e' };
  const composeArgs = (args) => ['compose', '--project-name', 'ogami-erp-e2e', ...args];
  const run = (command, args, env) => {
    calls.push({ command, args, env });
    return true;
  };

  assert.equal(clearTransientRedis(environment, run, composeArgs), true);
  assert.deepEqual(calls, [
    {
      command: 'docker',
      args: ['compose', '--project-name', 'ogami-erp-e2e', 'exec', '--no-TTY', 'redis', 'redis-cli', 'FLUSHALL', 'SYNC'],
      env: environment,
    },
    {
      command: 'docker',
      args: ['compose', '--project-name', 'ogami-erp-e2e', 'exec', '--no-TTY', 'redis-cache', 'redis-cli', 'FLUSHALL', 'SYNC'],
      env: environment,
    },
  ]);
});

test('stops the baseline reset if clearing either E2E Redis store fails', () => {
  const calls = [];
  const composeArgs = (args) => args;
  const run = (_command, args) => {
    calls.push(args);
    return calls.length === 1;
  };

  assert.equal(clearTransientRedis({ E2E_ENV: 'true', E2E_DB_NAME: 'ogami_e2e', E2E_COMPOSE_PROJECT: 'ogami-erp-e2e' }, run, composeArgs), false);
  assert.equal(calls.length, 2);
  assert.equal(calls[1][2], 'redis-cache');
});

test('refuses to flush Redis unless the dedicated E2E identifiers are set', () => {
  const calls = [];
  assert.throws(() => clearTransientRedis(
    { E2E_ENV: 'false', E2E_DB_NAME: 'ogami', E2E_COMPOSE_PROJECT: 'dev' },
    (...args) => { calls.push(args); return true; },
    (args) => args,
  ), /dedicated E2E environment/);
  assert.equal(calls.length, 0);
});
