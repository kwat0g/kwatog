import assert from 'node:assert/strict';
import test from 'node:test';
import { authSetupCommand, processJourneyCommand } from './playwright-commands.mjs';

test('auth setup command selects only the real-login project', () => {
  assert.deepEqual(authSetupCommand(), [
    'run', '--rm', '--no-deps', 'playwright', 'npx', 'playwright', 'test',
    '-c', 'playwright.process.config.ts', '--project=auth-setup', '--no-deps',
  ]);
});

test('journey command requires a selector and suppresses the auth project dependency', () => {
  assert.deepEqual(processJourneyCommand('HR02-HAPPY'), [
    'run', '--rm', '--no-deps', 'playwright', 'npx', 'playwright', 'test',
    '-c', 'playwright.process.config.ts', '--project=chromium-process', '--no-deps', '--grep', 'HR02-HAPPY',
  ]);
  assert.throws(() => processJourneyCommand(''), /valid journey selector/);
  assert.throws(() => processJourneyCommand('['), /valid journey selector/);
  assert.throws(() => processJourneyCommand('HR02.*'), /valid journey selector/);
  assert.throws(() => processJourneyCommand('.'), /valid journey selector/);
});

test('floor journey runs in the headless tablet touch project', () => {
  assert.deepEqual(processJourneyCommand('PROD01-HAPPY'), [
    'run', '--rm', '--no-deps', 'playwright', 'npx', 'playwright', 'test',
    '-c', 'playwright.process.config.ts', '--project=tablet-floor', '--no-deps', '--grep', 'PROD01-HAPPY',
  ]);
});
