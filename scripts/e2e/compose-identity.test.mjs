import assert from 'node:assert/strict';
import test from 'node:test';
import { composeArgs, E2E_RUNTIME_PROJECT } from './compose-identity.mjs';

test('uses the fixed isolated runtime label while keeping a fixed Compose argument list', () => {
  assert.equal(E2E_RUNTIME_PROJECT, 'ogami-erp-e2e-session');
  assert.deepEqual(composeArgs(['ps', '--format', 'json']), [
    'compose', '--project-name', 'ogami-erp-e2e-session', '--env-file', '.env.e2e',
    '--file', 'docker-compose.e2e.yml', 'ps', '--format', 'json',
  ]);
});
