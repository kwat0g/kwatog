# M021 — Payroll period processing action plan

Date: 2026-08-24  
Status: 📋 Plan Ready  
Overall recommendation: separate implementation work; no production-code fixes in this audit session

The focused Docker suite passed (264 tests, 771 assertions), but the highest-risk issues require financial policy, transaction/locking changes, migration review, permission design, and concurrency/publication tests. Work should be split into reviewable changes with explicit rollback and worker verification.

## Ordered implementation plan

### 1. M021-F01 — Fail closed when de minimis data is unavailable

- Classification/severity: Broken, P1
- Scope: medium; calculator, payroll error/state contract, approval/finalization gates, tests
- Recommendation: separate session
- Distinguish a valid zero taxable excess from an unavailable de minimis calculation. Persist a visible employee/period error or manual-review state and block approval/finalization until the dependency is recovered or an authorized override is recorded.
- Tests: missing table/settings, service exception, retry recovery, visible error row, approval block, finalization block, and legitimate zero-result cases.

### 2. M021-F02 — Make approved-adjustment application atomic

- Classification/severity: Broken, P1
- Scope: medium/large; calculator transaction/lock order, adjustment schema/application key, tests
- Recommendation: separate session
- Claim an approved adjustment under a stable lock order or enforce a unique payroll-period/adjustment application key. Re-read before deduction creation, make the losing concurrent worker safe, and preserve recomputation/reversal behavior.
- Tests: two-connection concurrent application, retry after deadlock/rollback, same-period recompute, different-period overlap, rejection/approval race, reversal, and audit attribution.

### 3. M021-F03 — Define and enforce disbursement evidence policy

- Classification/severity: Incomplete, P1
- Scope: large; proof request/controller, period state machine, bank-file contract, migration, finance policy, tests
- Recommendation: separate session with finance owners
- Decide whether a period may be manually evidenced, bank-file settled, or partially settled. Require valid proof metadata and file existence, reconcile proof totals to payable net, require generated bank artifacts where policy requires them, and represent partial settlement explicitly rather than treating any proof as full Disbursed.
- Tests: omitted/zero/mismatched amounts, partial proof sets, duplicate proofs, missing artifact, failed upload, GL pending, bank-file generated, full reconciliation, repeated close, and void/retention behavior.

### 4. M021-F04 — Repair proof archive/restore and evidence retention

- Classification/severity: Broken, P1
- Scope: medium; enum-aware state guard, trashed binding, private-file retention/archive, controller/service/UI/tests
- Recommendation: bundle with the disbursement evidence session only if one owner can keep the policy coherent
- Prevent archive after the policy-defined terminal state, intentionally resolve trashed proofs, and choose whether restoration retains the original private file or uses an immutable archive. Align detail-page copy, API behavior, and audit events.
- Tests: archive before/after disbursement, restore authorization, deleted-model route binding, missing physical file, restore success, and audit-history visibility.

### 5. M021-F05 — Make bank artifacts explicit and idempotent

- Classification/severity: Incomplete, P1
- Scope: medium/large; bank service, record schema, download route, artifact lifecycle, tests
- Recommendation: separate session
- Move generation behind an explicit mutation or idempotent command, persist one current artifact per period/format/version, and make download read-only. Define regeneration, revocation, retention, and concurrent-request semantics. Reduce period-lock duration around file I/O after correctness is preserved.
- Tests: repeated download, concurrent download, event replay, failed write cleanup, regeneration, revoked artifact, authorization, and builder/database total equality.

### 6. M021-F06 — Stage and validate statutory contribution table versions

- Classification/severity: Incomplete, P1
- Scope: large; import service, table schema/versioning, activation transaction, CRUD permissions, tests
- Recommendation: separate session with payroll/statutory owners
- Parse exact decimals, validate the complete effective-dated set for gaps, overlaps, duplicates, bounds, agency, and expected row coverage, then activate only after the full version passes. Preserve the prior active version on any failure, add database uniqueness, and record actor/version/audit metadata. Decide whether row-level CRUD remains compatible with versioned publication.
- Tests: malformed row, partial upload, duplicate bracket, overlap/gap, effective-date boundary, prior-version preservation, activate/deactivate/retry, rollback, and concurrent payroll read during activation.

### 7. M021-F07 — Separate adjustment permissions from maker-checker policy

- Classification/severity: Incomplete, P2
- Scope: small/medium; permission seeder, routes, service gates, UI gates, authorization tests
- Recommendation: separate authorization session
- Define view, create, approve, reject, and apply capabilities. Keep self-approval rejection but require a dedicated checker permission/role for approval and rejection. Roll out seed data and frontend gates together.
- Tests: HR maker, finance checker, same-user rejection, cross-user approval, read-only viewer, unauthorized route access, and direct API bypass attempts.

### 8. M021-F08 — Bound payroll and bank populations without weakening correctness

- Classification/severity: Incomplete, P2
- Scope: medium; query chunking/streaming, transaction boundaries, worker memory/lock metrics, tests
- Recommendation: separate performance/operability session
- Measure realistic employee counts, chunk available employees and payroll rows where safe, stream artifact output, and avoid holding the period lock during non-database I/O unless the artifact claim requires it. Preserve claim fencing and total reconciliation.
- Tests: large population, memory ceiling, lock wait, worker retry, partial chunk failure, and exact aggregate totals.

### 9. M021-F09 — Remove float boundaries from payroll presentation and artifacts

- Classification/severity: Incomplete, P2
- Scope: small/medium; summary DTOs, variance/preview, CSV/bank builders, shared money policy, tests
- Recommendation: bundle with a financial-output hardening session
- Keep decimal strings through serialization and artifact generation, centralize scale/rounding rules, and compare displayed/exported values with persisted totals.
- Tests: large totals, fractional-cent inputs, negative/zero values, format-specific rounding, preview-vs-download equality, and locale/injection-safe CSV output.

## Cross-module decisions to record

- Whether de minimis/statutory dependency failure blocks the whole period, only affected employees, or requires a controlled override.
- Whether proof totals represent net payroll, bank-file settlement, manual partial settlement, or another finance-approved amount.
- Whether archived proof files are retained indefinitely, moved to immutable storage, or explicitly non-restorable.
- Which roles may create, approve, reject, finalize, disburse, void, retry, import statutory tables, and download payroll artifacts.
- Whether government contribution schedules are row-managed or versioned as atomic sets, and how an active version is rolled back.

## Session decision

No production-code implementation is authorized for this audit session. The majority of findings are separate-recommended because they change financial state semantics, concurrency guarantees, publication artifacts, statutory configuration, or authorization policy. Small presentation and query improvements should not be applied alone while the P1 controls remain open.

## Definition of done for the next implementation session

- De minimis and statutory-table dependency failures are visible and cannot silently produce or publish incorrect payroll.
- Approved adjustments have an atomic one-application guarantee with two-connection coverage.
- Disbursement state reflects reconciled, policy-approved evidence, including partial settlement if supported.
- Proof archive/restore preserves or intentionally retires the underlying evidence artifact.
- Bank generation has an idempotent artifact record and read-only download path.
- Statutory table activation is all-or-nothing, exact-decimal, versioned, and reversible.
- Permission matrix, migrations/backfills, deployment order, worker restart, rollback, and restore evidence are documented before release.
