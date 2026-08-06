export const meta = {
  name: 'ogami-full-audit',
  description: 'Full-system audit of Ogami ERP: gaps, bugs, security, stuck processes, dead features',
  phases: [
    { title: 'Audit', detail: '12 parallel deep audits across chains, security, bugs, dead features' },
    { title: 'Verify', detail: 'adversarial re-verification of every finding against real code' },
    { title: 'Synthesize', detail: 'merge, dedupe, rank, and write the report' },
  ],
}

const ROOT = '/home/kwat0g/Desktop/kwatog'

const COMMON = `
You are auditing the Ogami ERP monorepo at ${ROOT}.

Layout:
- Backend: api/ (Laravel 11, PHP 8.3). Modular monolith: api/app/Modules/<Module>/{Controllers,Models,Services,Requests,Resources,Jobs,routes.php}
- Shared: api/app/Common/{Traits,Services,Enums,Middleware}
- Migrations: api/database/migrations (279 files, numbered 0001_.. plus some timestamp style)
- Seeders: api/database/seeders
- Tests: api/tests (242 files, ~1181 test methods)
- Frontend: spa/src/{pages,routes,api,components,hooks,stores,types}
- Routes registered in spa/src/routes/*Routes.tsx via lazy() imports
- Project rules: ${ROOT}/CLAUDE.md  (READ THIS FIRST - it defines the 3 business chains, security rules, conventions)
- Process docs: docs/PROCESS-FLOWS.md, docs/SCHEMA.md, docs/PATTERNS.md

CRITICAL - avoid stale findings:
A prior audit ran 2026-07-27 and REPAIRED many defects; read docs/SYSTEM-AUDIT-2026-07-27.md
"Defects repaired" section and do NOT re-report anything already fixed there.
There is also uncommitted work-in-progress (git diff) hardening Accounting/Inventory/Dashboard
services - check \`git diff\` before claiming something is missing.

RULES OF EVIDENCE (non-negotiable):
- Every finding MUST cite a real file path + line number you actually read.
- Quote the actual offending code in \`evidence\`. Never paraphrase code you did not open.
- Before reporting something as missing, grep hard for it under a different name. Most
  "missing" things exist with different naming. Report missing ONLY after 3+ failed searches,
  and list what you searched in \`evidence\`.
- Prefer FEWER, REAL findings over many speculative ones. A wrong finding is worse than no finding.
- \`confidence\` must be "confirmed" only if you traced the full code path and can state the exact
  inputs that trigger the defect.

Use bash freely (rg/grep/sed/awk, git log, git diff). Docker stack is RUNNING: you may run
\`cd ${ROOT} && docker compose exec -T api php artisan <cmd>\` and
\`docker compose exec -T api php artisan test --filter='X'\` to verify hypotheses.
Do NOT modify any file. Read-only audit.
`

const FINDING_SCHEMA = {
  type: 'object',
  properties: {
    area: { type: 'string' },
    searched: { type: 'string', description: 'what you grepped/read, 2-3 sentences' },
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          title: { type: 'string', description: 'one line, specific, names the defect' },
          category: { type: 'string', enum: ['bug', 'security', 'stuck-process', 'process-gap', 'missing-feature', 'dead-feature', 'data-integrity', 'ux-defect', 'perf'] },
          severity: { type: 'string', enum: ['P0', 'P1', 'P2', 'P3'] },
          module: { type: 'string' },
          file: { type: 'string', description: 'repo-relative path' },
          line: { type: 'integer' },
          evidence: { type: 'string', description: 'quoted real code or exact grep results proving it' },
          scenario: { type: 'string', description: 'concrete real-world sequence: who does what, then what breaks' },
          impact: { type: 'string' },
          fix: { type: 'string', description: 'specific, minimal, names files/functions to change' },
          confidence: { type: 'string', enum: ['confirmed', 'likely', 'speculative'] },
        },
        required: ['title', 'category', 'severity', 'module', 'file', 'line', 'evidence', 'scenario', 'impact', 'fix', 'confidence'],
      },
    },
  },
  required: ['area', 'searched', 'findings'],
}

const VERIFY_SCHEMA = {
  type: 'object',
  properties: {
    area: { type: 'string' },
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          title: { type: 'string' },
          category: { type: 'string' },
          severity: { type: 'string', enum: ['P0', 'P1', 'P2', 'P3'] },
          module: { type: 'string' },
          file: { type: 'string' },
          line: { type: 'integer' },
          evidence: { type: 'string' },
          scenario: { type: 'string' },
          impact: { type: 'string' },
          fix: { type: 'string' },
          verdict: { type: 'string', enum: ['CONFIRMED', 'PLAUSIBLE', 'REJECTED'] },
          verdict_reason: { type: 'string', description: 'what you re-read and why it stands or falls' },
        },
        required: ['title', 'category', 'severity', 'module', 'file', 'line', 'evidence', 'scenario', 'impact', 'fix', 'verdict', 'verdict_reason'],
      },
    },
  },
  required: ['area', 'findings'],
}

const AREAS = [
  {
    id: 'chain1-otc',
    label: 'Chain 1 Order-to-Cash stuck states',
    prompt: `Audit CHAIN 1 (ORDER TO CASH) end-to-end for STUCK PROCESSES and process gaps:
CRM Sales Order -> MRP Plan -> MRP II Schedule -> Work Order -> in-process QC -> Finished Goods
-> outgoing AQL QC -> Delivery -> Customer Confirm -> Invoice -> Collection -> GL.

Read the real services: api/app/Modules/CRM/Services/*, MRP/Services/*, Production/Services/*,
Quality/Services/*, SupplyChain/Services/*, Accounting/Services/{InvoiceService,PaymentService,*}.

For EVERY state machine in that path, build the actual transition table from the code (grep for
enum classes in api/app/Modules/*/Enums and for status writes: forceFill(['status'.., ->status =).
Then hunt for:
- TERMINAL DEAD ENDS: a status a record can enter with NO code path out (no service method
  transitions out of it, no UI action). e.g. partially-delivered SO that can never close;
  WO stuck 'in_progress' when machine breaks; SO with cancelled WO; delivery rejected by customer.
- MISSING REVERSALS: no way to cancel/void/amend after a downstream doc exists.
- QUANTITY LEAKS: partial delivery / over-delivery / short-shipment arithmetic that never
  reconciles SO qty vs delivered qty vs invoiced qty.
- ORPHANED WAITS: records awaiting an approval whose approver role does not exist / is inactive.
Report each as category 'stuck-process' or 'process-gap' with the exact real-world scenario.`,
  },
  {
    id: 'chain2-ptp',
    label: 'Chain 2 Procure-to-Pay stuck states',
    prompt: `Audit CHAIN 2 (PROCURE TO PAY) end-to-end for STUCK PROCESSES and process gaps:
Material shortage (MRP) -> Purchase Request -> Approval -> PO -> Supplier -> Shipment (ImpEx)
-> GRN receive -> incoming QC -> Inventory -> Bill -> Payment -> GL.

Read api/app/Modules/Purchasing/Services/*, Inventory/Services/{GrnService,GrnGlPostingService,*},
SupplyChain/Services/*, Accounting/Services/{BillService,PaymentService,*}, Quality (incoming QC),
ReturnManagement/Services/*.

Hunt specifically for:
- Rejected incoming QC: does the GRN/PO/inventory/bill all reconcile? Can a rejected GRN be
  returned to supplier, and does PO received-qty reverse? What if the bill was already posted?
- Partial receipt then supplier cancels remainder: can the PO close? Any 'partially_received'
  dead end?
- 3-way match failure (PO vs GRN vs Bill price/qty mismatch): what happens? Is there an
  exception queue with an exit path, or does the bill just never post?
- PR approved but no vendor / vendor deactivated mid-flow.
- Over-receipt beyond PO qty; negative stock; weighted-average-cost recompute on reversal.
- Budget enforcement blocking a PR/PO with no override path (check BudgetEnforcementService,
  note there is uncommitted WIP there - read \`git diff\`).
Report each with the exact real-world scenario.`,
  },
  {
    id: 'chain3-htr',
    label: 'Chain 3 Hire-to-Retire stuck states',
    prompt: `Audit CHAIN 3 (HIRE TO RETIRE) end-to-end for STUCK PROCESSES and process gaps:
Hire -> Profile -> Shift assignment -> Biometric CSV -> DTR computation -> Leave/OT approvals
-> Payroll -> Payslip -> Bank file -> GL -> Separation -> Clearance -> Final pay.

Read api/app/Modules/HR/Services/*, Attendance/Services/*, Leave/Services/*, Payroll/Services/*,
Loans/Services/*.

Hunt specifically for:
- Employee separates mid-payroll-period: does payroll still compute? Final pay include unpaid
  loan balance, unused leave conversion, 13th month pro-rata? Is there a path where clearance
  blocks final pay forever?
- Retro leave approval AFTER payroll finalized (CLAUDE.md says "never unlock finalized, adjust
  next period") - is that adjustment path actually implemented, or is the correction lost?
- Biometric CSV with missing time-out / overnight shift crossing midnight / duplicate punches /
  employee with no shift assigned. What does DTR do - silently zero, or throw?
- Leave balance going negative; leave spanning two payroll periods; holiday inside leave.
- OT rules from CLAUDE.md: min 30min, max 4hrs, night diff 10% only 22:00-06:00, extended shift
  6AM-6PM auto-OT. Verify each is really enforced in code and correctly.
- Loans: zero interest, max 1 month salary, max 1 loan + 1 CA concurrent - verify enforced.
  What if employee separates with outstanding loan?
Report each with the exact real-world scenario.`,
  },
  {
    id: 'sec-authz',
    label: 'Security: authorization / IDOR / privilege escalation',
    prompt: `Audit AUTHORIZATION and access control. This is a security audit - be rigorous.

1. Enumerate EVERY route across all api/app/Modules/*/routes.php. For each, determine whether a
   permission middleware or FormRequest::authorize() actually gates it. Produce the list of routes
   with NO effective authorization check. (Beware: some gate inside the controller/service - verify.)
2. IDOR / horizontal privilege: find endpoints that take a hash id and load a record without
   scoping to the caller's tenant/employee/department. Especially:
   - Self-service (HR/Controllers/SelfServiceController) - can employee A read B's payslip/DTR/leave?
   - B2B portal (api/app/Modules/B2B/**) - can supplier X see supplier Y's POs/invoices? can
     customer X see customer Y's SOs/deliveries? Check the guard scoping on every B2B query.
   - Driver / Edge / factory-floor guards (api/app/Modules/Edge/**).
3. Approval workflow bypass: can a user approve their own request? approve out of order? skip a
   level? re-approve? Check ApprovalService + every *Service that calls it.
4. Privilege escalation: can a non-admin grant themselves permissions / edit their own role /
   create a user with a higher role? Check Admin/Controllers/{UserController,RoleController} and
   any permission-grant/revoke path.
5. Mass assignment: models that still have sensitive fields in \$fillable (status, approved_by,
   posted_at, amounts, role_id, is_active, permissions).
Report each with the concrete attack: which account, which request, what they get.`,
  },
  {
    id: 'sec-appsec',
    label: 'Security: injection, files, secrets, session, crypto',
    prompt: `Audit application security beyond authz.

1. SQL injection: every DB::raw / whereRaw / selectRaw / orderByRaw / havingRaw across api/app.
   For each, prove whether user input can reach it. Sorting/filtering params are the classic hole
   (e.g. ->orderBy(\$request->sort)) - check every list endpoint's sort/filter handling.
2. File upload/download: validate MIME server-side? random filenames? stored outside web root?
   served through a permission-checked controller? path traversal in any download/preview route?
   Check Quality documents, delivery photos, attachments, payroll bank files, exports.
3. Secrets/config: grep for hardcoded credentials, API keys, tokens in api/ and spa/ (exclude
   .env.example). Check APP_DEBUG, APP_KEY handling, docker-compose.prod.yml, nginx config for
   the security headers CLAUDE.md mandates. Do NOT print secret values - name the file+key only.
4. Session/auth: rate limiting on auth + sensitive routes, account lockout (5 fails/15min),
   password policy/history/expiry, session lifetime per role, logout invalidation, CSRF on all
   mutating routes, cookie flags. Verify each claim in CLAUDE.md is REALLY implemented.
5. Data exposure: API Resources leaking raw integer \`id\`, unmasked sensitive PII (sss_no, tin,
   bank_account_no, salary) to unauthorized roles, stack traces, verbose validation echoing input.
   Grep Resources for \`'id' => \$this->id\` (raw) vs hash_id.
6. XSS: dangerouslySetInnerHTML in spa/src, unescaped user content in Blade PDF templates
   (api/resources/views/pdf/**).
Report each with the concrete exploit path.`,
  },
  {
    id: 'bugs-money',
    label: 'Bugs: money, GL, transactions, idempotency',
    prompt: `Audit for real BUGS in financial + transactional correctness.

1. Every service method that writes money or GL must be in DB::transaction(). Find writes that
   are NOT (grep Accounting/Inventory/Payroll/Purchasing services for multi-write methods without
   a transaction wrapper). Report only where a partial failure leaves inconsistent state.
2. Double-entry integrity: does every JournalEntry post balanced (debits==credits)? Is it asserted
   in code? Find posting paths that could create unbalanced JEs (rounding, partial allocation,
   VAT split, FX). Check Accounting/Services/JournalEntryService + every *GlPostingService.
3. Money math: float usage on money, round() vs decimal, division without rounding policy,
   VAT 12% computation, withholding tax, allocation remainders that lose centavos, negative
   amounts, currency mixing.
4. Idempotency: can the same invoice/payment/GRN/output/payroll be posted twice? Look for missing
   status guards before posting, missing unique constraints, retry-unsafe jobs (queued listeners
   without a dedupe key), webhook/ingest endpoints.
5. Race conditions: concurrent stock issue driving negative stock, two users approving the same
   doc, document-sequence collisions (check Common/Services/DocumentSequenceService for locking),
   weighted-average-cost recompute under concurrency. Look for read-then-write without
   lockForUpdate/atomic update.
6. Reversals: void/credit-note/payment-reversal paths that do not reverse the GL, or reverse it
   twice.
Report only defects you can trigger with a stated sequence of user actions.`,
  },
  {
    id: 'bugs-logic',
    label: 'Bugs: cross-module logic, enums, dates, N+1',
    prompt: `Audit for logic bugs across modules (non-financial).

1. Enum/status mismatches: string literals compared against enum-backed columns, statuses written
   that no enum case covers, DB check constraints vs enum drift, frontend union types that do not
   match the PHP enum (compare spa/src/types/** against api/app/Modules/*/Enums/**). Grep for
   status comparisons using raw strings.
2. Null/empty handling: division by zero (OEE, scrap rate, yield %, ABC classification,
   supplier performance, forecast accuracy, EOQ/reorder point), first()/->id on possibly-null,
   sum of empty collection, date diff on null dates. Check Dashboard/Forecasting/Production
   /Inventory/Maintenance analytics services - these are the usual suspects.
3. Date/timezone: Asia/Manila vs UTC mixing, ->toDateString() on a datetime crossing midnight,
   period boundary off-by-one (semi-monthly payroll 1-15/16-EOM, month-end, fiscal year),
   date comparisons as strings, DST-free but leap-year/Feb boundaries.
4. GROUP BY / SQL correctness: relations with a default orderBy used in aggregate queries
   (CLAUDE.md documents this trap with NonConformanceReport::actions()) - find OTHER relations
   with default orderBy() and any aggregate query over them. Verify against PostgreSQL semantics.
5. N+1 and unbounded queries: list endpoints without eager loading, ->get() with no pagination on
   tables that grow (audit_logs, notifications, attendance_logs, stock_movements), exports that
   load everything into memory.
6. Scheduled jobs (api/routes/console.php): overlapping runs without withoutOverlapping, jobs
   that fail silently, cron entries referencing commands that no longer exist (verify each
   command class exists), jobs with no failure alerting.
Report each with the triggering condition.`,
  },
  {
    id: 'dead-features',
    label: 'Dead / nonsense features safe to remove',
    prompt: `Find features that exist but are NONSENSE, VANITY, or DEAD - candidates for removal.
The user explicitly named two: the admin "compare roles" page (spa/src/pages/admin/roles/compare.tsx)
and the "SoD" page (spa/src/pages/admin/sod/**). Assess those and find the rest.

Method - for each candidate produce a removal-safety verdict:
1. Enumerate SPA pages (329 in spa/src/pages) and check each is reachable: registered in
   spa/src/routes/*Routes.tsx AND linked from Sidebar.tsx / command palette / another page.
   Pages that are registered but unreachable from any nav = dead.
2. Enumerate API routes with no SPA caller (grep the endpoint path across spa/src/api/**).
3. Find backend services/commands/models with zero references outside their own file + tests.
4. Find "analysis theatre" screens: read-only pages that display a computed matrix/score nobody
   acts on, with no downstream effect on any of the 3 chains. Judge honestly - a screen that only
   exists to look impressive in a demo but drives no decision and blocks no transaction is a
   removal candidate.
5. Duplicate/overlapping features: two pages doing the same job, two services computing the same
   metric differently.
6. Unused DB tables (migration exists, no model or no reads), unused enums, unused permissions
   (seeded in RolePermissionSeeder but never referenced by any route/middleware/authorize()).

For EVERY removal candidate you MUST determine and state in \`fix\`:
- exact blast radius: every file to delete/edit (pages, routes, api client, sidebar entry,
  controller, service, model, migration, seeded permissions, tests)
- whether removal breaks a process: does ANY service/gate/enforcement path depend on it?
  (e.g. SodService.check() may be called by a real enforcement gate - grep before condemning)
- verdict: SAFE-REMOVE / REMOVE-WITH-MIGRATION / KEEP (and why)
category = 'dead-feature'. severity = P3 unless it actively misleads a user (then P2).
Be conservative: if anything real depends on it, say KEEP.`,
  },
  {
    id: 'missing-core',
    label: 'Missing features vs declared scope',
    prompt: `Find MISSING features/processes that the system claims or needs.

Sources of truth for what SHOULD exist:
- ${ROOT}/CLAUDE.md (17 modules table, 3 chains, KEY BUSINESS RULES, and the explicit
  "NOT BUILDING" cut-scope list - do NOT report anything on the NOT BUILDING list as missing)
- docs/TASKS.md, docs/NEW-TASKS-V2.md, docs/GAP-ANALYSIS.md, docs/PROCESS-FLOWS.md
- docs/SCHEMA.md vs actual migrations

Method:
1. For each of the 17 modules, list the CRUD + lifecycle operations that exist, then name the
   ones a real user would need and cannot do. Focus on operations whose absence STOPS work:
   no edit after submit, no cancel, no reprint, no bulk action where volume demands it,
   no way to correct a data-entry error.
2. Business rules in CLAUDE.md declared but not implemented anywhere (verify by grep, 3+ searches).
3. Notifications/alerts: which chain events have no notification, so a human never learns a
   record needs them (silent queues = the #1 cause of stuck ERP processes).
4. Reports/documents a manufacturer legally or operationally needs that have no generator
   (payslip, BIR forms, Certificate of Conformance, delivery receipt, official receipt,
   inventory valuation, aging). Verify against api/resources/views/pdf/** and any export service.
5. Master-data lifecycle: can you deactivate a vendor/customer/item/employee that has open
   transactions? Is there any referential guard, or does deactivation strand documents?
Report category 'missing-feature' or 'process-gap'. State clearly what you searched to prove absence.`,
  },
  {
    id: 'quality-iatf',
    label: 'IATF 16949 quality chain integrity',
    prompt: `Audit the IATF 16949 quality thread - the thesis differentiator. Four touchpoints:
incoming QC (after GRN), in-process QC, outgoing AQL QC (AQL 0.65 Level II), and the NCR
feedback loop. Read api/app/Modules/Quality/** fully plus its callers.

Verify by reading real code:
1. Does EVERY product have enforced inspection specs (dimensions + tolerances)? What happens at
   inspection time if specs are missing - block, or silently pass?
2. AQL 0.65 Level II sampling: is the sample-size table actually implemented and correct
   (lot size -> sample size -> accept/reject number)? Find the table in code and spot-check 3 rows
   against the real ANSI/ASQ Z1.4 values. An invented table is a P1 finding.
3. Tolerance evaluation: measurement vs spec min/max - inclusive/exclusive boundary, unit
   mismatch, missing measurement treated as pass, non-numeric parameter types.
4. NCR loop: failure -> NCR -> corrective action WO -> replacement WO auto-generated -> defect
   data to Pareto. Trace each hop in code. Any hop that is not wired = broken traceability = P1.
   Can an NCR be closed with no disposition / no corrective action / no verification?
5. Certificate of Conformance: auto-generated from inspection data for every shipment? What if
   the shipment has no inspection - does it silently emit a CoC (that is a serious P0/P1: shipping
   an unbacked conformance certificate to Toyota).
6. Traceability: can you go from a customer complaint back to lot -> WO -> machine -> operator ->
   material GRN -> supplier? Find the break in that chain.
7. Blocking power: can finished goods be DELIVERED while outgoing QC failed or is pending?
   Can rejected material be ISSUED to production? These are the two gates that matter most -
   read the actual code that gates them, do not assume.`,
  },
  {
    id: 'frontend',
    label: 'Frontend defects, guards, states, dead ends',
    prompt: `Audit the SPA (spa/src, 329 pages) for real defects. Use docs/PATTERNS.md as the
project standard and CLAUDE.md's mandatory frontend rules.

1. Route guard coverage: every route must be wrapped AuthGuard + ModuleGuard + PermissionGuard.
   Enumerate routes in spa/src/routes/*Routes.tsx and list the ones missing a PermissionGuard or
   with a permission string that does not exist in the backend seeder (grep the slug in
   api/database/seeders/RolePermissionSeeder.php - a typo'd permission = permanently 403 page =
   a real stuck process). This mismatch check is the highest-value part of this audit - do it
   exhaustively for every guard string.
2. Feature-toggle/nav: routes registered but absent from Sidebar.tsx and command palette
   (unreachable), or nav entries pointing at non-existent routes (404 for the user).
3. The 5 required list states (loading skeleton / error+retry / empty / data / stale) - find list
   pages missing error or empty handling. Report the worst offenders, not all of them.
4. Forms: Zod schema fields that do not match backend validation rules (too permissive = 422 the
   user cannot decipher; too strict = cannot submit valid data). Spot-check the highest-traffic
   forms (SO, PO, PR, WO, employee, invoice, payroll) against their FormRequest rules.
5. Mutations without invalidateQueries (stale UI after write = user thinks it failed and
   double-submits), missing pending-disable on submit (double submit = duplicate documents).
6. UI dead ends: a status chip the UI renders but offers no action for; a detail page whose
   primary action is hidden for the role that actually performs it; pagination/filter that
   silently drops results.
Report with the exact page file + line.`,
  },
  {
    id: 'data-ops',
    label: 'Data integrity, migrations, ops readiness',
    prompt: `Audit data integrity and operational readiness.

1. Migrations (api/database/migrations, 279 files): missing FK constraints on *_id columns,
   missing indexes on columns used in WHERE/JOIN/ORDER BY of hot queries, missing unique
   constraints where the code assumes uniqueness (document numbers, employee_no, period+employee),
   nullable columns the code assumes non-null, money columns not decimal(15,2), enum columns
   whose DB constraint drifted from the PHP enum, down() methods that would lose data.
   Report the ones that actually matter, ranked.
2. Soft deletes: queries that forget withTrashed/without it where needed - a soft-deleted vendor
   /item/employee still referenced by open documents, causing null relations and blank UI or
   500s. Find where a soft-deleted parent breaks a child listing.
3. Cascade behavior: onDelete('cascade') that would silently destroy financial history; missing
   restrict where deletion should be blocked.
4. Seeders: does a fresh migrate+seed produce a working system? Any seeder depending on data
   another seeder creates later (order dependency), duplicate slugs, or permissions referenced by
   routes but never seeded. Verify RolePermissionSeeder covers every 'permission:x' middleware
   string used in api/app/Modules/*/routes.php - list mismatches exhaustively (both directions).
5. Queue/realtime ops: failed_jobs handling, queue worker restart safety, jobs that lose data on
   failure, Reverb/websocket auth, notification delivery failure paths.
6. Backup/restore: docs/RESTORE-DRILL.md vs reality; anything in docker-compose.prod.yml that
   would lose data on redeploy (volumes, migrations on boot, cache/config baked wrong).
Report the findings that would actually bite in production.`,
  },
]

// Retry wrapper: API 529/overload and empty-200 responses killed 12 agents on the first run.
// agent() returns null after the runtime's own retries are exhausted, so add our own attempts.
async function tryAgent(prompt, opts, tries = 5) {
  for (let i = 1; i <= tries; i++) {
    const r = await agent(prompt, opts)
    if (r !== null && r !== undefined) return r
    if (i < tries) {
      log(`retry ${i}/${tries - 1} after API failure: ${opts.label}`)
      try {
        await new Promise((res) => setTimeout(res, 15000 * i))
      } catch {
        /* no timer in sandbox - retry immediately */
      }
    }
  }
  return null
}

phase('Audit')
log(`Auditing ${AREAS.length} areas in batches of 4 (was 12-wide -> API overload), each with an adversarial verify pass`)

async function runArea(area) {
  const audit = await tryAgent(`${COMMON}\n\n=== YOUR AREA: ${area.label} ===\n\n${area.prompt}`, {
    label: `audit: ${area.label}`,
    phase: 'Audit',
    schema: FINDING_SCHEMA,
    effort: 'high',
  })
  if (!audit) {
    log(`AUDIT STILL FAILED after retries: ${area.label}`)
    return null
  }
  return verifyArea(audit, area)
}

async function verifyArea(audit, area) {
  {
    if (!audit || !audit.findings || audit.findings.length === 0) return audit
    const payload = JSON.stringify(audit.findings, null, 1)
    const r = await tryAgent(
      `${COMMON}

=== YOUR JOB: ADVERSARIALLY VERIFY someone else's audit findings ===
Area: ${area.label}

Another auditor produced the findings below. You are the skeptic. Your reputation depends on
killing wrong findings, not on being agreeable. For EACH finding:
1. Open the cited file at the cited line. Does the quoted code actually exist there, verbatim?
   If the code is not there or is materially different -> REJECTED.
2. Re-derive the claim from the surrounding code. Look hard for the guard/check/transaction/
   scoping the auditor may have missed elsewhere (parent controller, middleware, FormRequest,
   model boot hook, DB constraint, event listener, frontend gate). If a real guard exists
   -> REJECTED.
3. Check it is not already fixed in docs/SYSTEM-AUDIT-2026-07-27.md or in uncommitted \`git diff\`
   -> REJECTED as stale if so.
4. Check the existing test suite: if api/tests already asserts the CORRECT behaviour the auditor
   claims is broken, run that test (\`docker compose exec -T api php artisan test --filter=X\`).
   Passing test proving correct behaviour -> REJECTED.
5. If it survives: verdict CONFIRMED only when you can state the exact trigger; else PLAUSIBLE.
   Fix the severity if the auditor inflated it. Rewrite \`scenario\` to be concrete and correct.
   Tighten \`fix\` to the minimal correct change with real file/function names.

Return ALL findings including REJECTED ones with your reason. Do not invent new findings.

FINDINGS TO VERIFY:
${payload}`,
      { label: `verify: ${area.label}`, phase: 'Verify', schema: VERIFY_SCHEMA, effort: 'high' },
    )
    if (!r) log(`VERIFY STILL FAILED after retries, keeping unverified: ${area.label}`)
    return r || audit
  }
}

// Batch 4-wide instead of 12-wide. Cached agents replay instantly regardless of batching.
const results = []
const BATCH = 4
for (let i = 0; i < AREAS.length; i += BATCH) {
  const slice = AREAS.slice(i, i + BATCH)
  log(`batch ${Math.floor(i / BATCH) + 1}: ${slice.map((a) => a.id).join(', ')}`)
  const out = await parallel(slice.map((a) => () => runArea(a)))
  results.push(...out)
}

phase('Synthesize')
const good = results.filter(Boolean)
const all = []
for (const r of good) {
  for (const f of r.findings || []) {
    if (f.verdict !== 'REJECTED') all.push({ ...f, area: r.area })
  }
}
log(`${all.length} surviving findings from ${good.length} areas`)

const bundle = JSON.stringify(all, null, 1)

const report = await agent(
  `${COMMON}

=== YOUR JOB: write the final audit report ===

${all.length} verified findings from 12 parallel audits are below (REJECTED ones already removed).

Write a single markdown report to ${ROOT}/docs/SYSTEM-AUDIT-2026-08-02.md. You MAY write that
one file (only that file). Structure:

# Ogami ERP — Full-System Audit (2026-08-02)
## Verdict
Blunt 1-paragraph state of the system: is any core chain broken? what is the worst thing found?
## P0 — must fix (broken / exploitable / stuck now)
## P1 — should fix before defense
## P2 / P3 — backlog
For each finding use this exact format:
### <ID> <title>
- **Category / Module:** ... | **Severity:** ... | **Confidence:** CONFIRMED|PLAUSIBLE
- **Where:** \`path:line\`
- **Evidence:** fenced code block
- **Real-world scenario:** ...
- **Impact:** ...
- **Fix:** ...
IDs: sequential per severity, e.g. P0-01, P1-07.
## Dead features — removal decisions
A table: Feature | Files | Depended on by | Verdict (SAFE-REMOVE / REMOVE-WITH-MIGRATION / KEEP)
Then a per-candidate blast-radius list for every SAFE-REMOVE.
## Stuck-process map
Table of every dead-end state found: Module | Record | Status it gets stuck in | How it enters |
Why no exit | Fix.
## Coverage and limits
What was audited, what was NOT, and which findings are PLAUSIBLE rather than CONFIRMED.

Rules: merge duplicates across areas (same root cause = one finding, cite all locations).
Rank strictly by real-world damage, not by how interesting it is. Keep the prose tight - this is
an engineering document, no filler. Do not soften findings. Do not add findings not in the input.

Then return (as your final text) a compact summary: total counts by severity and category, the
top 8 findings one line each, and the SAFE-REMOVE list.

FINDINGS:
${bundle}`,
  { label: 'write report', phase: 'Synthesize', effort: 'high' },
)

log('report written')
return { count: all.length, report }
