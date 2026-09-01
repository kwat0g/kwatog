# M043 — import-shipments-customs fix log

No application source fixes were applied during the 2026-08-25 audit session. The module remains 📋 Plan Ready because the dominant work requires decisions around PO/shipment/GRN ownership, customs evidence gates, supplier-portal convergence, landed-cost accounting, and archive/file retention.

Verification recorded in `audit-report.md`:

- Focused shipment, status-concurrency, ImpEx PDF, and private document suite: 26 tests, 53 assertions passed.
- SPA TypeScript typecheck passed.
- Migration status and Supply Chain route inventory were checked.
- SPA API-route audit was attempted but the existing script failed before producing results with `ERR_STREAM_NULL_VALUES`.

The ordered remediation work is in `action-plan.md`.

## 2026-08-25 implementation session

No application source fixes were applied. The first ordered plan item
(`M043-F001/F002`) is blocked on a human decision about the authoritative
PO → shipment → customs-evidence → GRN/AP lifecycle: permitted PO states,
multiple shipment legs, required customs evidence, and the durable GRN
handoff/linkage contract. The later items are intentionally pending because
they depend on that contract or on separate business decisions:

- `M043-F007`: supplier-portal shipment/document source of truth and
  reconciliation/provenance policy.
- `M043-F004`: landed-cost inputs, cent allocation, inventory valuation, and
  AP treatment.
- `M043-F003`: whether shipment Incoterm overrides or inherits the PO value.
- `M043-F006`: terminal archive policy and document-file retention/recovery.
- `M043-F005`: container ownership and lifecycle in the shipment journey.
- `M043-F008`: terminal document versioning and mutation policy.

Before/after: application files unchanged; module remains `🔁 Needs Re-audit`
pending those decisions. No finding was marked fixed or re-verified.

## 2026-09-01 re-audit session — 7 contained fixes applied

Environment re-verified before and during: `docker compose ps` showed only `ogami-db`
and `ogami-redis` running (api/queue/reverb/spa/nginx stopped, as in the prior session);
`docker compose exec -T db psql -U ogami -d postgres -c "select 1;"` returned a row.
Own database `ogami_test_impex`, every run via
`docker compose run --rm --no-deps -e DB_DATABASE=ogami_test_impex api …`.

**Mid-session the host OOM-killed a suite run (exit 137) and took Postgres into crash
recovery.** The next run reported **160 failed with ZERO assertions in 7.6s** — the tell
from CLAUDE.md that the database is gone, not that the code is broken. The underlying
error was `SQLSTATE[08006] … FATAL: the database system is starting up`. Recovery took
50s; after it the same suite passed. **The zero-assertion run is not a result and is not
reported as one.** Subsequent runs used `php -d memory_limit=512M`.

| baseline | after |
|---|---|
| `tests/Feature/SupplyChain` — **109 passed / 304 assertions / exit 0** | **160 passed / 477 assertions / exit 0** |

The 51 added tests / 173 added assertions are `ImportShipmentCustomsAuditTest`.
`phpstan analyse app/Modules/SupplyChain --memory-limit=1G` → **no errors**.
Pint: 4 of the changed files fail at HEAD already; rule lists extracted from
`git show 33c43e8a:api/<path>` and diffed programmatically → **NEW (mine only): []**
(one real new rule, `fully_qualified_strict_types` in `ShipmentController`, was fixed by
importing `UploadedFile`). `ShipmentService.php` and the new test file pass Pint outright.

### Fixed, with before/after measured over HTTP

| # | fix | before | after |
|---|---|---|---|
| A1 | eager-load `landedCosts.shipment` in `LandedCostService::calculate()` | `{"lines=1":200,"lines=2":500,"lines=3":500,"lines=2,freight=100":500}`, `LazyLoadingViolationException` | `{...:200,...:200,...:200,...:200}`; 0 queries at serialisation (N+1 gone) |
| A2 | `by_weight` refuses explicitly instead of `TypeError` | `TypeError: getItemWeights(): Return value must be of type Eloquent\Collection` → 500 | `BusinessRuleException` → **422** with a message naming the reason; nothing persisted |
| A3 | RFC 6266 `Content-Disposition` on document download | `attachment; filename="bl".pdf"` (header forged) | `attachment; filename="bl.pdf"; filename*=UTF-8''bl.pdf`; a CRLF name yields `filename="aX-Injected__1.pdf"` and **no** `X-Injected` header |
| A4 | bound the client filename length | 304-char name → **500** (22001) | **422**; a 255-char name still uploads |
| A5 | `decimal:0,N` + `max:` on container weight/volume | `1.999`→`2.00`, `1e3`→`1000.00`, `1e17`/`1e20`→**500** (22003) | all → **422**; `-1`→422, `0`→`0.00`, `25400.55`→`25400.55`, `67.500`→`67.500` exact |
| A6 | ETA ≥ ETD on `updateMeta` | `etd=2026-12-31, eta=2026-01-01` accepted (200) | **422** both-submitted **and** ETA-only against the stored ETD |
| A7 | `withTrashed()` on the 3 import restore routes | `{"shipment":404,"document":404,"container":404}` | `{"shipment":200,"document":200,"container":200}`; a bad hash still 404s |
| A8 | persist the submitted Incoterm on create | submitted `DDP` → response `null`, column `null` | response `"DDP"`, column `"DDP"`, PO's `FOB` untouched; `NOPE` still 422 |

`impex_officer` end to end, all 15 documented steps: was
`{… "landed_cost":500 …}` (14 of 15), now **all 200/201**.

### Deliberately NOT fixed — gated on a business decision

Listed in `action-plan.md` tranche B. In one line each, and why the split:

- **Apportionment does not reconcile** (7 lines over-allocate freight by `0.03`, 3 lines
  lose `0.01`, five components lose `0.05`). Fixing it changes a money figure and needs the
  residual rule agreed. Left as a *pass-either-way lock* in three tests so the exact
  measured residual is pinned.
- **No landed-cost input path at all** — nothing anywhere writes the five charge columns,
  so the feature computes `0.00` for every real shipment. Needs an accounting decision on
  whether it adjusts GRN unit cost / weighted-average cost. **Must follow the reconciliation
  fix**, or an unreconciled figure would corrupt a WAC calculation `goods-receiving`
  verified exact.
- **All 8 PO states accepted** for shipment creation vs GRN's three. Which set is correct
  depends on the unanswered multi-leg question; deferring for the same reason the prior
  session did rather than guessing and silently blocking a real workflow.
- **No customs evidence gate and no received→GRN handoff.** An empty shipment clears and is
  received in five 200s; `received` emits no event, no notification, no queued job, and
  `shipments` has no `%handoff%` column while `deliveries` has three.
- **Terminal-state immutability and file retention.** A received shipment's metadata,
  containers and documents are all still mutable; raw SQL rewrote `shipment_number` and
  `customs_clearance_date` and deleted the row; `pg_trigger` count = 0; archiving a shipment
  destroys document files while leaving the rows active.
- **Container / landed-cost UI**, **supplier-portal convergence**, **Incoterm PDF source of
  truth**.

### Open questions for a human

1. **Is archiving recoverable or permanent?** Shared with M044. A7 makes three advertised
   restore routes reach their target; if the answer is "permanent" they should be deleted
   instead, and the file-destruction behaviour becomes correct rather than a bug.
2. **What is the landed-cost residual rule**, and does landed cost adjust inventory
   valuation and AP, or is it analysis-only?
3. **Which import documents are mandatory** before `customs → cleared` and
   `cleared → received`?
4. **May one PO have several shipment legs**, and which PO states may open one?
5. **Does a shipment Incoterm override or inherit the PO's**, and which should the customs
   PDFs render?
6. **Should `by_weight` exist at all?** It is now refused because `items` has no weight
   column. Either capture item weights or drop the enum case.

### Scratch removed

`ogami_test_impex` dropped at end of session. The probe suite was renamed from the
scratch `ZzM043ImportAuditProbeTest` to `ImportShipmentCustomsAuditTest` and kept — it is
the HTTP-level coverage the module did not have.
