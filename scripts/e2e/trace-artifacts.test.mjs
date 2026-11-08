import assert from 'node:assert/strict';
import test from 'node:test';
import { sanitizeTraceArtifacts } from './trace-artifacts.mjs';

test('sanitizes then checks the Playwright process output directory', () => {
  const calls = [];
  const environment = { E2E_ENV: 'true' };
  const ok = sanitizeTraceArtifacts((command, args, receivedEnvironment) => {
    calls.push({ command, args, receivedEnvironment });
    return true;
  }, environment);

  assert.equal(ok, true);
  assert.deepEqual(calls, [
    {
      command: 'python3',
      args: ['scripts/e2e/sanitize_traces.py', '--sanitize', 'spa/test-results/process'],
      receivedEnvironment: environment,
    },
    {
      command: 'python3',
      args: ['scripts/e2e/sanitize_traces.py', '--check', 'spa/test-results/process'],
      receivedEnvironment: environment,
    },
  ]);
});

test('stops when sanitization fails and does not run a misleading clean check', () => {
  const calls = [];
  const ok = sanitizeTraceArtifacts((command, args) => {
    calls.push({ command, args });
    return false;
  }, {});

  assert.equal(ok, false);
  assert.equal(calls.length, 1);
  assert.deepEqual(calls[0].args, ['scripts/e2e/sanitize_traces.py', '--sanitize', 'spa/test-results/process']);
});
