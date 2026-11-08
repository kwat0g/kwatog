import test from 'node:test';
import assert from 'node:assert/strict';
import { composeUpArgs, hasCompleteRunningStack, hasHealthyRunningStack } from './stack-readiness.mjs';

const required = [
  'api', 'db', 'mailpit', 'nginx', 'queue-broadcasts', 'queue-dss',
  'queue-main', 'redis', 'redis-cache', 'reverb', 'scheduler', 'spa',
];

test('reuses the E2E stack only when every long-running service is present', () => {
  assert.equal(hasCompleteRunningStack(required.join('\n')), true);
  assert.equal(hasCompleteRunningStack([...required.slice(1)].join('\n')), false);
  assert.equal(hasCompleteRunningStack(`${required.join('\n')}\nold-service`), true);
});

test('accepts a complete running Compose health snapshot and rejects starting or unhealthy services', () => {
  const healthy = required.map((Service) => JSON.stringify({ Service, State: 'running', Health: 'healthy' })).join('\n');
  assert.equal(hasHealthyRunningStack(healthy), true);

  const starting = required.map((Service) => JSON.stringify({ Service, State: Service === 'nginx' ? 'starting' : 'running', Health: 'healthy' })).join('\n');
  assert.equal(hasHealthyRunningStack(starting), false);

  const unhealthy = required.map((Service) => JSON.stringify({ Service, State: 'running', Health: Service === 'api' ? 'unhealthy' : 'healthy' })).join('\n');
  assert.equal(hasHealthyRunningStack(unhealthy), false);
  assert.equal(hasHealthyRunningStack('not compose json'), false);
});

test('can health-wait on an already built stack without rebuilding images', () => {
  const args = composeUpArgs(false, true);
  assert.deepEqual(args, ['up', '--detach', '--wait', '--wait-timeout', '180']);
  assert.equal(args.includes('--build'), false);
});

test('builds the dedicated stack when services need to be created', () => {
  const args = composeUpArgs(true, true);
  assert.deepEqual(args, ['up', '--detach', '--build', '--wait', '--wait-timeout', '180']);
});

test('can build and detach without holding the caller on Compose health checks', () => {
  assert.deepEqual(composeUpArgs(true, false), ['up', '--detach', '--build']);
});
