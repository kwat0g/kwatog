const { chromium, firefox } = require('playwright');

const BASE = (process.env.BASE_URL || 'http://localhost').replace(/\/$/, '');
const PASSWORD = process.env.AUDIT_PASSWORD || 'password';
const ROLES = [
  ['admin@ogami.test', '/dashboard/admin'],
  ['production@ogami.test', '/dashboard/plant-manager'],
  ['ppc@ogami.test', '/dashboard/ppc'],
  ['hr@ogami.test', '/dashboard/hr'],
  ['finance@ogami.test', '/dashboard/finance'],
  ['purchasing@ogami.test', '/dashboard/purchasing'],
  ['warehouse@ogami.test', '/dashboard/warehouse'],
  ['qc@ogami.test', '/dashboard/quality'],
  ['employee@ogami.test', '/dashboard/default'],
];

const VISIBLE_ERRORS = [
  /something went wrong/i,
  /unexpected error/i,
  /can't access property/i,
  /dispatcher is null/i,
  /failed to load/i,
  /page not found/i,
  /access denied/i,
  /not authorized/i,
];

async function login(page, email) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 20_000 });
  await page.locator('input[type="email"]').fill(email);
  await page.locator('input[type="password"]').fill(PASSWORD);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL((url) => url.pathname !== '/login', { timeout: 20_000 });
}

async function auditRole(browser, browserName, email, expectedPath) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  const browserErrors = [];
  const apiErrors = [];

  page.on('pageerror', (error) => browserErrors.push(String(error)));
  page.on('console', (message) => {
    if (message.type() === 'error') browserErrors.push(message.text());
  });
  page.on('response', (response) => {
    if (response.status() >= 400 && response.url().includes('/api/')) {
      apiErrors.push(`${response.status()} ${response.request().method()} ${response.url()}`);
    }
  });

  const problems = [];
  try {
    await login(page, email);
    browserErrors.length = 0;
    apiErrors.length = 0;
    await page.goto(`${BASE}${expectedPath}`, { waitUntil: 'domcontentloaded', timeout: 20_000 });
    await page.locator('body').waitFor({ state: 'visible', timeout: 5_000 });
    await page.waitForTimeout(1500);

    const finalPath = new URL(page.url()).pathname;
    const body = (await page.locator('body').innerText()).trim();
    if (finalPath !== expectedPath) problems.push(`redirected to ${finalPath}`);
    if (body.length < 40) problems.push('blank or near-empty dashboard');
    const visibleError = VISIBLE_ERRORS.find((pattern) => pattern.test(body));
    if (visibleError) problems.push(`visible error matching ${visibleError}`);
    if (browserErrors.length) problems.push(`${browserErrors.length} browser error(s)`);
    if (apiErrors.length) problems.push(`${apiErrors.length} failed API response(s)`);
  } catch (error) {
    problems.push(`audit exception: ${String(error.message || error)}`);
  } finally {
    await context.close();
  }

  const label = `${browserName} ${email} ${expectedPath}`;
  if (!problems.length) {
    console.log(`PASS ${label}`);
    return null;
  }
  return { label, problems, browserErrors, apiErrors };
}

(async () => {
  const failures = [];
  let checked = 0;

  for (const [browserName, browserType] of [['chromium', chromium], ['firefox', firefox]]) {
    const browser = await browserType.launch({ headless: true });
    try {
      for (const [email, path] of ROLES) {
        checked += 1;
        const failure = await auditRole(browser, browserName, email, path);
        if (failure) failures.push(failure);
      }
    } finally {
      await browser.close();
    }
  }

  console.log(`\n=== ${checked} role-dashboard checks; ${failures.length} failure(s) ===`);
  for (const failure of failures) {
    console.log(`\nFAIL ${failure.label}`);
    for (const problem of failure.problems) console.log(`  - ${problem}`);
    for (const error of failure.apiErrors) console.log(`    API ${error}`);
    for (const error of failure.browserErrors) console.log(`    BROWSER ${error.slice(0, 500)}`);
  }

  if (failures.length) process.exitCode = 1;
})().catch((error) => {
  console.error('FATAL', error);
  process.exit(1);
});
