const phpunitProcess = /(?:^|[\s/])phpunit(?:[./\s]|$)/i;
const laravelTestProcess = /(?:^|[\s/])php(?:[0-9]+(?:\.[0-9]+)*)?\s+artisan\s+test(?:\s|$)/i;

export function assertNoActivePhpTest(processList) {
  const active = String(processList).split(/\r?\n/).some((line) => (
    phpunitProcess.test(line) || laravelTestProcess.test(line)
  ));
  if (active) {
    throw new Error('A PHP test process is active; refusing to start E2E work.');
  }
}
