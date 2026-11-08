const EXPECTED_DATABASE = 'ogami_e2e';
const EXPECTED_PROJECT = 'ogami-erp-e2e';
const ALLOWED_BASE_URLS = new Set([
  'https://127.0.0.1:18443',
  'https://localhost:18443',
  'https://nginx',
]);

/**
 * Load the private E2E file as the source of environment identity and
 * credentials, while letting an explicit shell actor selector choose a
 * targeted setup run. No other shell variable can override the guarded file.
 */
export function mergeRunnerEnvironment(processEnvironment, fileEnvironment) {
  const merged = { ...processEnvironment, ...fileEnvironment };
  if (Object.hasOwn(processEnvironment, 'E2E_AUTH_ACTORS')) {
    merged.E2E_AUTH_ACTORS = processEnvironment.E2E_AUTH_ACTORS;
  }
  return merged;
}

/**
 * Fail closed unless the runner is pointed at the dedicated, disposable E2E
 * environment. Do not include arbitrary environment values in errors: callers
 * may pass credentials alongside the identifiers this function checks.
 */
export function assertE2EEnvironment(env = process.env) {
  if (env.E2E_ENV !== 'true') {
    throw new Error('Refusing to run: E2E_ENV must be exactly "true".');
  }
  if (env.E2E_DB_NAME !== EXPECTED_DATABASE || env.DB_DATABASE !== EXPECTED_DATABASE) {
    throw new Error(`Refusing to run: database must be ${EXPECTED_DATABASE}.`);
  }
  if (env.E2E_COMPOSE_PROJECT !== EXPECTED_PROJECT) {
    throw new Error(`Refusing to run: Compose project must be ${EXPECTED_PROJECT}.`);
  }
  if (env.APP_ENV !== 'testing' || env.DB_CONNECTION !== 'pgsql' || env.DB_HOST !== 'db') {
    throw new Error('Refusing to run: application and database services do not match the E2E profile.');
  }

  const baseURL = typeof env.E2E_BASE_URL === 'string'
    ? env.E2E_BASE_URL.trim().replace(/\/+$/, '')
    : '';
  if (!ALLOWED_BASE_URLS.has(baseURL)) {
    throw new Error('Refusing to run: E2E_BASE_URL is not in the local E2E allowlist.');
  }

  return {
    baseURL,
    database: EXPECTED_DATABASE,
    project: EXPECTED_PROJECT,
  };
}

export function assertE2EEnvironmentOrExit(env = process.env) {
  try {
    return assertE2EEnvironment(env);
  } catch (error) {
    process.stderr.write(`${error instanceof Error ? error.message : 'E2E environment guard failed.'}\n`);
    process.exitCode = 1;
    return null;
  }
}
