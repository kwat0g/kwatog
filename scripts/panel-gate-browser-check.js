/**
 * Panel gating, asserted in a real browser.
 *
 * scripts/role-dashboard-audit.js proves no dashboard THROWS. That is not the
 * same as proving the gate works: a page that renders "Cash on hand ₱0.00" to a
 * role with no accounting grant passes that audit while leaking exactly what
 * PanelGate exists to withhold, and a page that silently drops a panel its role
 * IS entitled to also passes.
 *
 * So this checks the difference itself, on one page seen by two roles:
 *   production@ogami.test → dashboard/plant-manager: no financial snapshot
 *   admin@ogami.test      → the same page, WITH it
 * and the restored delivery read:
 *   warehouse@ogami.test  → dashboard/warehouse: the outgoing queue is back
 *
 * Run: node scripts/panel-gate-browser-check.js   (needs the stack up)
 */
const { chromium } = require('playwright');

const BASE = (process.env.BASE_URL || 'http://localhost').replace(/\/$/, '');
const PASSWORD = process.env.AUDIT_PASSWORD || 'password';

/** Money labels from the Plant Manager financial snapshot (Task D2). */
const FINANCE_MARKERS = ['Cash on hand', 'AR Outstanding', 'AP Outstanding', 'Revenue MTD'];

async function login(page, email) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  // The login page carries an animated hero, and a cold Vite dev server can take
  // seconds to transform it — wait for the field rather than assuming it is there.
  const emailField = page.locator('input[type="email"]');
  await emailField.waitFor({ state: 'visible', timeout: 60_000 });
  await emailField.fill(email);
  await page.locator('input[type="password"]').fill(PASSWORD);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL((url) => url.pathname !== '/login', { timeout: 30_000 });
}

/** Settles the dashboard: its panels arrive from a second request after mount. */
async function open(page, path) {
  await page.goto(`${BASE}${path}`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  await page.locator('body').waitFor({ state: 'visible', timeout: 10_000 });
  // An absent panel and an unrendered page look identical in body text, so a
  // "no money here" assertion is vacuous unless the dashboard has actually
  // painted. Wait for a heading the page always carries before reading it.
  await page
    .getByRole('heading', { level: 1 })
    .first()
    .waitFor({ state: 'visible', timeout: 30_000 });
  await page.waitForTimeout(3500);
  return page.locator('body').innerText();
}

async function asRole(browser, email, path) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  try {
    await login(page, email);
    return await open(page, path);
  } finally {
    await context.close();
  }
}

(async () => {
  const browser = await chromium.launch();
  const failures = [];
  const checks = [];

  const record = (name, ok, detail) => {
    checks.push({ name, ok, detail });
    if (!ok) failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
  };

  try {
    // ── The leak that panel gating closed ────────────────────────────────
    const plantAsProduction = await asRole(browser, 'production@ogami.test', '/dashboard/plant-manager');
    const leaked = FINANCE_MARKERS.filter((m) => plantAsProduction.includes(m));
    record(
      'production_manager sees no money on the plant dashboard',
      leaked.length === 0,
      leaked.length ? `leaked: ${leaked.join(', ')}` : '',
    );
    // …and still gets the dashboard it is there for, so the gate did not just
    // blank the page — and so the assertion above cannot pass merely because
    // nothing rendered. `Top Defects` is NOT a usable marker here: it lives
    // inside the collapsed DashTail and is absent from visible text by design.
    record(
      'production_manager keeps its own plant panels',
      plantAsProduction.includes('Machine Status Breakdown'),
      'Machine Status Breakdown did not render, so the leak check above proves nothing',
    );

    // ── Same page, a grant that permits it ───────────────────────────────
    const plantAsAdmin = await asRole(browser, 'admin@ogami.test', '/dashboard/plant-manager');
    const adminSees = FINANCE_MARKERS.filter((m) => plantAsAdmin.includes(m));
    record(
      'an accounting-entitled viewer DOES see the financial snapshot',
      adminSees.length === FINANCE_MARKERS.length,
      `missing: ${FINANCE_MARKERS.filter((m) => !plantAsAdmin.includes(m)).join(', ')}`,
    );

    // ── The narrow delivery read, restored ───────────────────────────────
    const warehouse = await asRole(browser, 'warehouse@ogami.test', '/dashboard/warehouse');
    record(
      'warehouse_staff sees its outgoing delivery queue',
      warehouse.includes('Outgoing'),
      'the Outgoing panel did not render',
    );
    record(
      'warehouse_staff still sees its inventory panels',
      warehouse.includes('Incoming'),
      'the Incoming panel did not render',
    );
  } finally {
    await browser.close();
  }

  for (const c of checks) {
    console.log(`${c.ok ? 'PASS' : 'FAIL'} ${c.name}${c.ok || !c.detail ? '' : ` — ${c.detail}`}`);
  }
  console.log(`\n=== ${checks.length} panel-gate checks; ${failures.length} failure(s) ===`);
  process.exit(failures.length ? 1 : 0);
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
