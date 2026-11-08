const requiredServices = [
  'api',
  'db',
  'mailpit',
  'nginx',
  'queue-broadcasts',
  'queue-dss',
  'queue-main',
  'queue-outbox',
  'redis',
  'redis-cache',
  'reverb',
  'scheduler',
  'spa',
];

export function hasCompleteRunningStack(output) {
  const running = new Set(String(output).trim().split(/\s+/).filter(Boolean));
  return requiredServices.every((service) => running.has(service));
}

export function hasHealthyRunningStack(output) {
  try {
    const entries = String(output).trim().split(/\r?\n/).filter(Boolean).map((line) => JSON.parse(line));
    const services = new Map(entries.map((entry) => [entry.Service, entry]));
    return requiredServices.every((service) => {
      const entry = services.get(service);
      return entry?.State === 'running' && (!entry.Health || entry.Health === 'healthy');
    });
  } catch {
    return false;
  }
}

export function composeUpArgs(buildRequired, wait = true) {
  const build = buildRequired ? ['--build'] : [];
  const healthWait = wait ? ['--wait', '--wait-timeout', '180'] : [];
  return ['up', '--detach', ...build, ...healthWait];
}
