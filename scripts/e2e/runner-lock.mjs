import { spawnSync } from 'node:child_process';

export function runWithProjectLock(lockPath, executable, args, options = {}) {
  const result = spawnSync(
    'flock',
    ['--nonblock', '--conflict-exit-code', '75', lockPath, executable, ...args],
    options,
  );

  if (result.error) throw result.error;

  return {
    busy: result.status === 75,
    status: result.status ?? 1,
  };
}
