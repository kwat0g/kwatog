export function clearTransientRedis(environment, run, composeArgs) {
  if (environment.E2E_ENV !== 'true'
      || environment.E2E_DB_NAME !== 'ogami_e2e'
      || environment.E2E_COMPOSE_PROJECT !== 'ogami-erp-e2e') {
    throw new Error('Redis baseline reset requires the dedicated E2E environment.');
  }

  for (const service of ['redis', 'redis-cache']) {
    const args = composeArgs(['exec', '--no-TTY', service, 'redis-cli', 'FLUSHALL', 'SYNC']);
    if (!run('docker', args, environment)) return false;
  }

  return true;
}
