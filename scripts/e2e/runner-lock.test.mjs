import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import test from 'node:test';
import { runWithProjectLock } from './runner-lock.mjs';

const directory = path.dirname(fileURLToPath(import.meta.url));
const lockModuleUrl = pathToFileURL(path.join(directory, 'runner-lock.mjs')).href;

test('project lock runs one guarded command to completion', () => {
  const tempDirectory = mkdtempSync(path.join(os.tmpdir(), 'ogami-e2e-lock-'));
  try {
    const result = runWithProjectLock(
      path.join(tempDirectory, 'runner.lock'),
      process.execPath,
      ['-e', 'process.exit(0)'],
      { stdio: 'ignore' },
    );
    assert.deepEqual(result, { busy: false, status: 0 });
  } finally {
    rmSync(tempDirectory, { recursive: true, force: true });
  }
});

test('project lock refuses a second guarded command while one is active', () => {
  const tempDirectory = mkdtempSync(path.join(os.tmpdir(), 'ogami-e2e-lock-'));
  const lockPath = path.join(tempDirectory, 'runner.lock');
  const nestedProgram = [
    `import { runWithProjectLock } from ${JSON.stringify(lockModuleUrl)};`,
    `const result = runWithProjectLock(${JSON.stringify(lockPath)}, process.execPath, ['-e', 'process.exit(0)'], { stdio: 'ignore' });`,
    'process.exit(result.busy ? 75 : 1);',
  ].join('\n');

  try {
    const result = spawnSync(
      'flock',
      ['--nonblock', lockPath, process.execPath, '--input-type=module', '-e', nestedProgram],
      { stdio: 'ignore' },
    );
    assert.equal(result.error, undefined);
    assert.equal(result.status, 75);
  } finally {
    rmSync(tempDirectory, { recursive: true, force: true });
  }
});
