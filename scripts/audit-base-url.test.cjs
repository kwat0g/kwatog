const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const root = path.resolve(__dirname, '..');
const scripts = [
  'dynamic-spa-route-audit.js',
  'defense-smoke-walk.js',
  'role-dashboard-audit.js',
  'panel-gate-browser-check.js',
  'role-permission-audit.js',
];

test('browser audit scripts require an explicit isolated base URL before loading a browser', () => {
  const stubs = fs.mkdtempSync(path.join(os.tmpdir(), 'ogami-audit-browser-stub-'));

  try {
    for (const name of ['playwright', 'playwright-core']) {
      const directory = path.join(stubs, name);
      fs.mkdirSync(directory);
      fs.writeFileSync(path.join(directory, 'index.js'), "throw new Error('browser loaded before base URL validation');");
    }

    for (const script of scripts) {
      const result = spawnSync(process.execPath, [path.join(__dirname, script)], {
        cwd: root,
        encoding: 'utf8',
        timeout: 5000,
        env: { ...process.env, BASE_URL: '', NODE_PATH: stubs, AUDIT_PASSWORD: 'test-only' },
      });
      const output = `${result.stdout}\n${result.stderr}`;

      assert.notEqual(result.status, 0, `${script} should reject a missing base URL`);
      assert.match(output, /BASE_URL must point to an isolated test application/, script);
      assert.doesNotMatch(output, /browser loaded before base URL validation/, script);
    }
  } finally {
    fs.rmSync(stubs, { recursive: true, force: true });
  }
});

test('live Playwright config requires an isolated base URL and selects its real suite', () => {
  const cli = path.join(root, 'spa', 'node_modules', 'playwright', 'cli.js');
  const args = ['test', '-c', 'playwright.live.config.ts', '--list'];
  const cwd = path.join(root, 'spa');
  const env = { ...process.env };
  delete env.E2E_BASE_URL;

  const missingBase = spawnSync(process.execPath, [cli, ...args], {
    cwd,
    encoding: 'utf8',
    timeout: 15_000,
    env,
  });
  const missingOutput = `${missingBase.stdout}\n${missingBase.stderr}`;

  assert.notEqual(missingBase.status, 0);
  assert.match(missingOutput, /Set E2E_BASE_URL to the isolated test application/);

  const isolatedBase = spawnSync(process.execPath, [cli, ...args], {
    cwd,
    encoding: 'utf8',
    timeout: 15_000,
    env: { ...env, E2E_BASE_URL: 'http://127.0.0.1:5257' },
  });

  assert.equal(isolatedBase.status, 0, isolatedBase.stderr);
  assert.match(isolatedBase.stdout, /live-procurement\.spec\.ts/);
});
