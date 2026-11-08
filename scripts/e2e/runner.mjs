#!/usr/bin/env node
import { randomBytes } from 'node:crypto';
import { chmod, readFile, stat, unlink, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { assertE2EEnvironment, mergeRunnerEnvironment } from './environment-guard.mjs';
import { clearTransientRedis } from './baseline.mjs';
import { composeUpArgs, hasCompleteRunningStack, hasHealthyRunningStack } from './stack-readiness.mjs';
import { authSetupCommand, processJourneyCommand, validateJourneyId } from './playwright-commands.mjs';
import { runWithProjectLock } from './runner-lock.mjs';
import { composeArgs } from './compose-identity.mjs';
import { sanitizeTraceArtifacts } from './trace-artifacts.mjs';
import { assertNoActivePhpTest } from './php-test-guard.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const envFile = path.join(root, '.env.e2e');
const templateFile = path.join(root, '.env.e2e.example');
const environmentProject = 'ogami-erp-e2e';
const projectLock = path.join(os.tmpdir(), 'ogami-erp-e2e-runner.lock');
const serializedCommands = new Set([
  'init', 'up', 'wait', 'seed', 'reset', 'rotate', 'test', 'test-split', 'clock', 'schedule', 'drain', 'down', 'destroy',
]);

function usage() {
  process.stdout.write(`OGAMI isolated E2E environment\n\nUsage: node scripts/e2e/runner.mjs <command>\n\nCommands:\n  init       Create a private .env.e2e with generated secrets\n  config     Validate the guarded environment and Compose configuration\n  up         Build and detach the isolated E2E stack\n  wait       Wait for every long-running E2E service to become healthy\n  seed       Reset schema and seed reference data plus E2E actor accounts\n  reset      Destroy E2E containers/volumes, restart, and seed a baseline\n  rotate     Regenerate E2E credentials and reset the E2E baseline\n  test       Seed a fresh baseline then run Playwright; optional --grep <journey-id>\n  test-split Seed, UI-authenticate selected actors, restart services preserving volumes, then run one journey\n  clock      Set the E2E clock (ISO-8601) or clear it with \"reset\"\n  schedule   Trigger one scheduled-task evaluation using the E2E clock\n  drain      Drain an E2E queue: <redis|redis_broadcasts|redis_dss> [queue]\n  down       Stop E2E containers and preserve their volumes\n  destroy    Stop E2E containers and remove only the dedicated E2E volumes\n`);
}

function parseEnv(source) {
  const values = {};
  for (const line of source.split(/\r?\n/)) {
    const match = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/);
    if (!match || match[1].startsWith('#')) continue;
    let value = match[2];
    if (value.startsWith('"') && value.endsWith('"')) value = value.slice(1, -1).replace(/\\"/g, '"').replace(/\\\\/g, '\\');
    else if (value.startsWith("'") && value.endsWith("'")) value = value.slice(1, -1);
    values[match[1]] = value;
  }
  return values;
}

function quoteEnv(value) {
  return `"${value.replaceAll('\\', '\\\\').replaceAll('"', '\\"')}"`;
}

function randomHex(bytes = 32) {
  return randomBytes(bytes).toString('hex');
}

async function initializeEnv() {
  try {
    const existing = await readFile(envFile, 'utf8');
    const values = parseEnv(existing);
    if (values.E2E_ENV !== 'true' || values.E2E_DB_NAME !== 'ogami_e2e' || values.E2E_COMPOSE_PROJECT !== environmentProject) {
      throw new Error('Existing .env.e2e does not match the dedicated E2E identifiers; it was left unchanged.');
    }
    const additions = [];
    if (!values.DB_READER_USERNAME) additions.push('DB_READER_USERNAME=ogami_e2e_reader');
    if (!values.DB_READER_PASSWORD) additions.push(`DB_READER_PASSWORD=${randomHex(32)}`);
    if (additions.length === 0) {
      throw new Error('.env.e2e already exists and has the current required fields; it was left unchanged.');
    }
    await writeFile(envFile, `${existing.trimEnd()}\n${additions.join('\n')}\n`, { mode: 0o600 });
    await chmod(envFile, 0o600);
    process.stdout.write('Updated the private .env.e2e with newly required E2E-only credentials.\n');
    return;
  } catch (error) {
    if (error.code !== 'ENOENT') throw error;
  }

  try {
    await stat(envFile);
    throw new Error('.env.e2e already exists; it was left unchanged. Remove it yourself if you intend to regenerate the E2E credentials.');
  } catch (error) {
    if (error.code !== 'ENOENT') throw error;
  }

  const template = await readFile(templateFile, 'utf8');
  const reverbKey = `ogami_e2e_${randomHex(16)}`;
  const replacements = {
    E2E_ACCOUNT_PASSWORD: randomBytes(32).toString('base64url'),
    E2E_CONTROL_TOKEN: randomHex(32),
    APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
    DB_PASSWORD: randomHex(32),
    DB_READER_PASSWORD: randomHex(32),
    REVERB_APP_KEY: reverbKey,
    REVERB_APP_SECRET: randomHex(32),
    VITE_REVERB_APP_KEY: reverbKey,
  };

  let output = template;
  for (const [key, value] of Object.entries(replacements)) {
    const encoded = key === 'APP_KEY' ? quoteEnv(value) : value;
    output = output.replace(new RegExp(`^${key}=.*$`, 'm'), `${key}=${encoded}`);
  }
  if (/=\s*REPLACE_/.test(output)) throw new Error('The E2E environment template has an unfilled placeholder.');

  await writeFile(envFile, output, { flag: 'wx', mode: 0o600 });
  await chmod(envFile, 0o600);
  process.stdout.write('Created .env.e2e with E2E-only credentials (mode 0600). Keep it private.\n');
}

async function loadGuardedEnv() {
  let source;
  try {
    source = await readFile(envFile, 'utf8');
  } catch (error) {
    if (error.code === 'ENOENT') throw new Error('Missing .env.e2e. Run the init command first.');
    throw error;
  }
  const values = parseEnv(source);
  const environment = mergeRunnerEnvironment(process.env, values);
  const guarded = assertE2EEnvironment(environment);
  return { values, environment, guarded };
}

function run(command, args, environment, { inherit = true } = {}) {
  const result = spawnSync(command, args, {
    cwd: root,
    env: environment,
    stdio: inherit ? 'inherit' : 'pipe',
  });
  if (result.error) throw result.error;
  if (result.status !== 0) process.exitCode = result.status ?? 1;
  return result.status === 0;
}

function assertNoConcurrentPhpTest() {
  const result = spawnSync('ps', ['-eo', 'args'], {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'ignore'],
  });
  if (result.error || result.status !== 0) {
    throw new Error('Unable to inspect active processes; refusing to start E2E work.');
  }
  assertNoActivePhpTest(result.stdout);
}

function requireDocker(environment) {
  if (!run('docker', composeArgs(['config', '--quiet']), environment)) {
    throw new Error('Docker Compose rejected the E2E configuration.');
  }
}

function startStack(environment, { wait = true, build = true } = {}) {
  assertNoConcurrentPhpTest();
  requireDocker(environment);
  return run('docker', composeArgs(composeUpArgs(build, wait)), environment);
}

function requireRunningStack(environment) {
  const result = spawnSync('docker', composeArgs(['ps', '--status', 'running', '--services']), {
    cwd: root,
    env: environment,
    encoding: 'utf8',
  });
  if (result.error || result.status !== 0 || !hasCompleteRunningStack(result.stdout)) {
    throw new Error('The isolated E2E stack is not fully running. Use the guarded runner up command first.');
  }
}

async function waitForHealthyStack(environment) {
  const deadline = Date.now() + 180_000;
  while (Date.now() < deadline) {
    const result = spawnSync('docker', composeArgs(['ps', '--format', 'json']), {
      cwd: root,
      env: environment,
      encoding: 'utf8',
    });
    if (!result.error && result.status === 0 && hasHealthyRunningStack(result.stdout)) return;
    await new Promise((resolve) => setTimeout(resolve, 1_000));
  }
  throw new Error('Timed out waiting for every long-running E2E Compose service to be healthy.');
}

async function requireReadyStack(environment) {
  requireRunningStack(environment);
  await waitForHealthyStack(environment);
}

function seedBaseline(environment) {
  assertNoConcurrentPhpTest();
  if (!clearTransientRedis(environment, run, composeArgs)) return false;
  const artisan = ['exec', '--no-TTY', 'api', 'php', 'artisan'];
  assertNoConcurrentPhpTest();
  if (!run('docker', composeArgs([...artisan, 'migrate:fresh', '--force', '--seed']), environment)) return false;
  assertNoConcurrentPhpTest();
  return run('docker', composeArgs([...artisan, 'db:seed', '--class=Database\\Seeders\\E2EAccountSeeder', '--force']), environment);
}

async function main() {
  const command = process.argv[2];
  if (!command || command === '--help' || command === '-h') {
    usage();
    return;
  }

  if (serializedCommands.has(command) && process.env.E2E_RUNNER_LOCKED !== 'true') {
    const result = runWithProjectLock(
      projectLock,
      process.execPath,
      [fileURLToPath(import.meta.url), ...process.argv.slice(2)],
      {
        cwd: root,
        env: { ...process.env, E2E_RUNNER_LOCKED: 'true' },
        stdio: 'inherit',
      },
    );
    if (result.busy) throw new Error('Another guarded E2E runner command is active; refusing concurrent operation.');
    process.exitCode = result.status;
    return;
  }

  if (command === 'init') {
    await initializeEnv();
    return;
  }

  if (command === 'rotate') {
    const { environment } = await loadGuardedEnv();
    if (!run('docker', composeArgs(['down', '--volumes', '--remove-orphans']), environment)) return;
    await unlink(envFile);
    await initializeEnv();
    const { environment: rotatedEnvironment } = await loadGuardedEnv();
    if (!startStack(rotatedEnvironment, { wait: false })) return;
    await requireReadyStack(rotatedEnvironment);
    seedBaseline(rotatedEnvironment);
    return;
  }

  const { environment } = await loadGuardedEnv();
  if (command === 'config') {
    requireDocker(environment);
    process.stdout.write('E2E guard and Compose configuration passed. No containers were started.\n');
    return;
  }
  if (command === 'up') {
    startStack(environment, { wait: false });
    return;
  }
  if (command === 'wait') {
    await requireReadyStack(environment);
    return;
  }
  if (command === 'seed') {
    await requireReadyStack(environment);
    if (!seedBaseline(environment)) return;
    return;
  }
  if (command === 'reset') {
    if (!run('docker', composeArgs(['down', '--volumes', '--remove-orphans']), environment)) return;
    if (!startStack(environment, { wait: false })) return;
    await requireReadyStack(environment);
    seedBaseline(environment);
    return;
  }
  if (command === 'test') {
    const testArgs = process.argv.slice(3);
    let grep = null;
    if (testArgs.length > 0) {
      if (testArgs.length !== 2 || testArgs[0] !== '--grep') {
        throw new Error('The test command accepts only an optional --grep <journey-id> selector.');
      }
      grep = validateJourneyId(testArgs[1]);
    }
    try {
      assertNoConcurrentPhpTest();
      await requireReadyStack(environment);
    } catch (error) {
      throw new Error(`${error.message} Run the guarded up and wait commands before testing.`);
    }
    if (!seedBaseline(environment)) return;
    assertNoConcurrentPhpTest();
    if (!run('docker', composeArgs(['run', '--build', '--rm', '--no-deps', 'playwright', 'npx', 'tsc', '-p', 'tsconfig.process-e2e.json']), environment)) return;
    const playwrightCommand = ['run', '--rm', '--no-deps', 'playwright', 'npx', 'playwright', 'test', '-c', 'playwright.process.config.ts'];
    if (grep !== null) playwrightCommand.push('--grep', grep);
    assertNoConcurrentPhpTest();
    run('docker', composeArgs(playwrightCommand), environment);
    sanitizeTraceArtifacts(run, environment);
    return;
  }
  if (command === 'test-split') {
    const testArgs = process.argv.slice(3);
    if (testArgs.length !== 2 || testArgs[0] !== '--grep') {
      throw new Error('The test-split command requires --grep <journey-id>.');
    }
    const journeyCommand = processJourneyCommand(testArgs[1]);
    if (!environment.E2E_AUTH_ACTORS?.trim()) {
      throw new Error('Set E2E_AUTH_ACTORS to the comma-separated actors required by this journey.');
    }
    assertNoConcurrentPhpTest();
    await requireReadyStack(environment);
    if (!seedBaseline(environment)) return;
    assertNoConcurrentPhpTest();
    if (!run('docker', composeArgs(['run', '--build', '--rm', '--no-deps', 'playwright', 'npx', 'tsc', '-p', 'tsconfig.process-e2e.json']), environment)) return;
    assertNoConcurrentPhpTest();
    if (!run('docker', composeArgs(authSetupCommand()), environment)) return;
    assertNoConcurrentPhpTest();
    if (!run('docker', composeArgs(['down', '--remove-orphans']), environment)) return;
    if (!startStack(environment, { wait: false, build: false })) return;
    await requireReadyStack(environment);
    assertNoConcurrentPhpTest();
    run('docker', composeArgs(journeyCommand), environment);
    sanitizeTraceArtifacts(run, environment);
    return;
  }
  if (command === 'clock') {
    const instant = process.argv[3];
    if (!instant) throw new Error('Provide an ISO-8601 instant or the word reset.');
    const args = instant === 'reset'
      ? ['exec', '--no-TTY', 'api', 'php', 'artisan', 'e2e:clock', '--clear']
      : ['exec', '--no-TTY', 'api', 'php', 'artisan', 'e2e:clock', instant];
    run('docker', composeArgs(args), environment);
    return;
  }
  if (command === 'schedule') {
    run('docker', composeArgs(['exec', '--no-TTY', 'api', 'php', 'artisan', 'e2e:run-scheduled']), environment);
    return;
  }
  if (command === 'drain') {
    const connection = process.argv[3] ?? 'redis';
    const queue = process.argv[4] ?? 'default';
    if (!['redis', 'redis_broadcasts', 'redis_dss'].includes(connection) || !/^[a-zA-Z0-9_,-]+$/.test(queue)) {
      throw new Error('Unsupported E2E queue connection or queue name.');
    }
    run('docker', composeArgs([
      'exec', '--no-TTY', 'api', 'php', 'artisan', 'e2e:drain-queue', connection, `--queue=${queue}`,
    ]), environment);
    return;
  }
  if (command === 'down' || command === 'destroy') {
    const args = command === 'destroy'
      ? ['down', '--volumes', '--remove-orphans']
      : ['down', '--remove-orphans'];
    run('docker', composeArgs(args), environment);
    return;
  }

  usage();
  process.exitCode = 2;
}

main().catch((error) => {
  process.stderr.write(`${error instanceof Error ? error.message : 'E2E runner failed.'}\n`);
  process.exitCode = 1;
});
