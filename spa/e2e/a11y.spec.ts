/**
 * W1.4 — accessibility gate (axe-core over Playwright).
 *
 * WHY THIS FILE EXISTS
 * Until now "WCAG 2.2 AA" was an assertion in the plan. The capture harness
 * checks exactly one a11y property — whether an interactive element has an
 * accessible name, read from Chromium's accessibility tree — and it cannot see
 * colour contrast, landmarks, heading order, table markup, label association or
 * the ~90 other rules axe implements. This is the measured version of the claim.
 *
 * WHAT IT GATES, AND WHAT IT DELIBERATELY DOES NOT
 * `critical` and `serious` violations fail the run: those are the impacts where
 * a real user is blocked (unlabelled controls, missing names, contrast below
 * 3:1 on text, broken table semantics). `moderate` and `minor` are printed and
 * attached but do not fail, because a gate that fails on every minor rule is a
 * gate somebody disables within a week. Nothing is blanket-excluded: if a rule
 * has to be turned off it goes in INTENTIONAL_EXCLUSIONS below with its reason,
 * one entry per line, so the next reader can argue with it.
 *
 * WHY system_admin
 * `usePermission()` short-circuits for that role, so every module's pages render.
 * One account per module would measure the permission guards instead of the
 * pages — a different (also useful) question, covered elsewhere.
 *
 * WHY NOT `waitForLoadState('networkidle')`
 * 53 queries in this app poll on an interval (measured: `refetchInterval`
 * appears in 53 files; the sidebar badge query polls unconditionally). A page
 * carrying one never goes quiet, and the QC list timed out at 30s waiting for a
 * silence that does not come. Readiness is therefore a RENDERED signal — the
 * page heading plus loading skeletons gone — not a quiet network.
 */
import AxeBuilder from '@axe-core/playwright';
import { type TestInfo } from '@playwright/test';
import { test, expect, type Page } from './fixtures';
import { USERS, mockAuth, type MockUser } from './helpers';

const AUDIT_USER: MockUser = {
  ...USERS.finance,
  id: 'a11y-audit',
  name: 'A11y Audit',
  email: 'a11y@ogami.test',
  roleSlug: 'system_admin',
  roleName: 'System Administrator',
  permissions: [],
  employee: null,
};

/**
 * Intentionally exempt rules, each with the reason it is exempt.
 *
 * Empty on purpose — an entry here should be a decision the team can re-examine,
 * not a shortcut past a red run. If one is added, say what a human would do
 * instead and why that is not possible here.
 */
const INTENTIONAL_EXCLUSIONS: Array<{ id: string; reason: string }> = [];

/** Impacts that stop the build. See the header. */
const BLOCKING = new Set(['critical', 'serious']);
const isBlocking = (impact: string | null | undefined): boolean =>
  impact != null && BLOCKING.has(impact);

/**
 * Empty-but-well-formed payloads for the endpoints these surfaces call.
 *
 * "Empty" is not the same as "absent": the shared `apiFallback` answers an
 * unmocked endpoint with `{}`, which makes `r.data.data` undefined and TanStack
 * Query then reports `Query data cannot be undefined` — a console error the
 * diagnostics fixture (correctly) fails on. Each body here has the exact shape
 * the API sends, so the page renders its real empty state instead of crashing.
 *
 * Shapes are copied from the api modules, not invented:
 *   `src/api/hr/employees.ts`, `src/api/hr/departments.ts`,
 *   `src/api/quality/inspections.ts`, `src/api/businessPolicies.ts`,
 *   `src/api/badges.ts`, `src/api/crm/products.ts`.
 */
const PAGINATED_EMPTY = { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } };

const AUDIT_MOCKS: Record<string, unknown> = {
  /* Layout-level: the sidebar polls this on every authenticated page. */
  '/api/v1/dashboards/badges': { data: {} },
  '/api/v1/business-policies': {
    data: { functional_currency_code: 'PHP', vat_status: 'VAT Registered', vat_rate: '0.12' },
  },
  /* The auth shell's brand lockup takes its name from here (unwrappingClient
   * returns the payload itself). Without it the lockup renders empty and the
   * link to "/" has no text — see the AuthLayout fix below for the fallback. */
  '/api/v1/landing/contact': {
    data: {
      legal_name: 'Philippine Ogami Corporation',
      address: 'FCIE, Dasmariñas, Cavite, Philippines',
      phone: '+63 46 123 4567',
      sales_email: 'sales@ogami.test',
      company_email: 'info@ogami.test',
      public_url: null,
      latitude: null,
      longitude: null,
    },
  },
  '/api/v1/hr/employees': PAGINATED_EMPTY,
  '/api/v1/hr/employees/options': { data: { statuses: [], employment_types: [], departments: [] } },
  '/api/v1/hr/employees/status-counts': { data: { counts: {} } },
  '/api/v1/hr/departments/tree': { data: [] },
  '/api/v1/quality/inspections': {
    ...PAGINATED_EMPTY,
    meta: {
      ...PAGINATED_EMPTY.meta,
      status_counts: { to_inspect: 0, awaiting_review: 0, failed: 0, all: 0 },
    },
  },
  '/api/v1/quality/inspections/options': {
    data: { stages: [], statuses: [], entity_types: [], measurement_results: [], sampling_methods: [] },
  },
  '/api/v1/quality/ncrs/options': {
    data: { sources: [], severities: [], statuses: [], actions: [], dispositions: [], default_disposition: '' },
  },
  '/api/v1/quality/ncr-templates/active': { ...PAGINATED_EMPTY },
  '/api/v1/crm/products': PAGINATED_EMPTY,
};

/** Register the mocks above. Later registrations win, so a spec-specific mock
 *  for the same path (added after this call) still overrides these. */
/**
 * The real signed-out answer: 401 from the session endpoint.
 *
 * That is what the API returns, and it is what makes the store settle on "not
 * signed in". A 200 with an empty body instead leaves a truthy-but-empty user
 * behind — a state the API cannot produce — and the dashboard route then mounts
 * and reads `user.role.slug`, which throws. The fixture's diagnostics assert no
 * unexpected error responses, so the expected 401 is declared, not suppressed.
 */
async function mockSignedOut(page: Page, testInfo: TestInfo): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/v1/auth/user',
    (route) =>
      route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Unauthenticated.' }),
      }),
  );
  testInfo.annotations.push({
    type: 'expected-http-response',
    description: '401 GET /api/v1/auth/user',
  });
}

async function mockAuditApi(page: Page): Promise<void> {
  for (const [path, body] of Object.entries(AUDIT_MOCKS)) {
    await page.route(
      (url) => url.pathname === path,
      async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
          body: JSON.stringify(body),
        });
      },
    );
  }
}

interface Surface {
  name: string;
  path: string;
  /** Mock a session first. `/sign-in` is audited as a signed-out visitor. */
  auth: boolean;
}

/** The surfaces the design system actually differs across: form, table, list,
 *  empty state, and the touch shell (whose floor palette has its own contrast
 *  obligations). Adding a route here is how a page joins the gate. */
const SURFACES: Surface[] = [
  { name: 'sign-in (signed out)', path: '/sign-in', auth: false },
  { name: 'dashboard (admin landing)', path: '/dashboard', auth: true },
  { name: 'employee list (table + filters)', path: '/hr/employees', auth: true },
  { name: 'QC inspections (list)', path: '/quality/inspections', auth: true },
  { name: 'QC new NCR (form)', path: '/quality/ncrs/new', auth: true },
  { name: 'floor tablet (touch shell)', path: '/factory', auth: true },
  /* W1.2 — every shared primitive in one place, in every state. The other
   * surfaces audit the kit as the product uses it; this one audits the states
   * a single page does not happen to exercise (disabled, error, empty). */
  { name: 'component gallery (the shared kit)', path: '/admin/component-gallery', auth: true },
];

/** Render the page and run axe. Returns every violation, unfiltered. */
async function audit(page: Page, surface: Surface, testInfo: TestInfo) {
  await mockAuditApi(page);
  if (surface.auth) {
    await mockAuth(page, AUDIT_USER);
  } else {
    await mockSignedOut(page, testInfo);
  }

  await page.goto(surface.path, { waitUntil: 'load' });
  /* The page heading is part of the chrome, so it exists as soon as the route
   * renders — and its presence is what separates "rendered" from "still on the
   * router fallback". */
  await page.waitForSelector('h1', { state: 'visible', timeout: 20_000 });
  /* Then a paint's worth of settle for data-dependent layout (tables, charts).
   * Deliberately short: axe should measure what a reader sees immediately, not
   * a page held open for seconds. */
  await page.waitForTimeout(400);
  /* Fonts change metrics and therefore contrast antialiasing. The diagnostics
   * fixture awaits this too. */
  await page.evaluate(async () => {
    await document.fonts.ready;
  });

  const results = await axeBuilder(page).analyze();
  return results.violations;
}

/** The gate's exact axe configuration, so the negative control below cannot
 *  validate a different one. */
function axeBuilder(page: Page) {
  let builder = new AxeBuilder({ page }).withTags([
    'wcag2a',
    'wcag2aa',
    'wcag21a',
    'wcag21aa',
    'wcag22aa',
  ]);
  for (const exclusion of INTENTIONAL_EXCLUSIONS) {
    builder = builder.disableRules(exclusion.id);
  }
  return builder;
}

/** One line per violation, with the first few offending selectors. */
function describe(violations: Awaited<ReturnType<typeof audit>>): string {
  return violations
    .map((v) => {
      const nodes = v.nodes.slice(0, 4).map((n) => n.target.join(' '));
      const more = v.nodes.length > nodes.length ? ` … +${v.nodes.length - nodes.length}` : '';
      return `  [${v.impact}] ${v.id}: ${v.help} — ${v.nodes.length} node(s)\n${nodes
        .map((n) => `      ${n}`)
        .join('\n')}${more}`;
    })
    .join('\n');
}

for (const surface of SURFACES) {
  test(`a11y — ${surface.name}`, async ({ page }, testInfo) => {
    const violations = await audit(page, surface, testInfo);

    /* Attach the full list either way: a passing run that carries two moderates
     * is information, and it is the difference between "clean" and "under the
     * threshold" the next time somebody asks. */
    await testInfo.attach(`axe-${surface.path.replace(/\W+/g, '-')}.json`, {
      body: JSON.stringify(violations, null, 2),
      contentType: 'application/json',
    });

    const blocking = violations.filter((v) => isBlocking(v.impact));
    const advisory = violations.filter((v) => !isBlocking(v.impact));

    if (advisory.length) {
      console.log(
        `${surface.path} — ${advisory.length} non-blocking violation(s):\n${describe(advisory)}`,
      );
    }

    expect(
      blocking,
      `critical/serious axe violations on ${surface.path}:\n${describe(blocking)}`,
    ).toEqual([]);
  });
}

test('a11y — the gate can see a planted violation (negative control)', async ({ page }, testInfo) => {
  await mockAuditApi(page);
  await mockSignedOut(page, testInfo);
  await page.goto('/sign-in', { waitUntil: 'load' });
  await page.waitForSelector('h1', { state: 'visible', timeout: 20_000 });

  /* A button with nothing to name it. If the gate cannot see THIS, it is
   * measuring nothing — a rule-less or mis-tagged axe run reports every page as
   * clean, which is the "the probe said fine" failure this programme has already
   * hit twice (C-001, C-003). The control runs the gate's own configuration.
   *
   * /sign-in, not a dashboard: a route that redirects after load would replace
   * the DOM mid-run and void the control silently. The presence check below is
   * what makes that impossible to miss. */
  await page.evaluate(() => {
    const planted = document.createElement('button');
    planted.id = 'planted-violation';
    document.body.appendChild(planted);
  });
  await expect(page.locator('#planted-violation'), 'the planted node left the DOM').toHaveCount(1);

  /* `.violations`, not the results object: mapping the AxeResults itself threw
   * "map is not a function" at runtime while the browser reported nothing —
   * exactly the failure this control exists to catch, in the control. The e2e
   * tsconfig below is what found it. */
  const violations = (await axeBuilder(page).analyze()).violations;
  expect(
    violations.map((v) => v.id),
    'axe did not see a button with no accessible name',
  ).toContain('button-name');
});

test('a11y — no intentional exclusions without a stated reason', () => {
  for (const exclusion of INTENTIONAL_EXCLUSIONS) {
    expect(exclusion.reason.length, `rule ${exclusion.id} needs a reason`).toBeGreaterThan(40);
  }
});
