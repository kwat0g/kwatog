# Action Plan — Quality / NCR + CAPA (M057)

Status: ✅ Partially Fixed — remainder 📋 Plan Ready (IATF-gated)
Last updated: 2026-09-01 (re-audit session 4)

## 2026-09-01 — re-audit plan

The 2026-08-25 plan below was **executed** and, measured for the first time on
2026-09-01, works: 15 of its 16 findings no longer reproduce (F-013 remains).
That plan is preserved verbatim at the bottom for history. This is the new one.

### Done this session (containment — refuses a false signal, changes no policy)

| Order | Finding | Work | Scope | Session |
|---:|---|---|---|---|
| 1 | N-001 | `ncr:escalate` distinguishes "nothing to do" from "everything threw": `advanceOne()` returns one of four outcomes, `runWithOutcome()` tallies them, the command prints all five counters and exits non-zero when any delivery failed. An unstaffed tier warns without failing. `run(): int` kept for compatibility. | Small | ✅ done |
| 2 | N-008 | `->withTrashed()` on `PATCH /quality/ncr-templates/{id}/restore`. Confirmed 404 → 200. | Small | ✅ done |
| 3 | N-009 | Regression coverage for the Pareto drill-down row-mapping branch (service + HTTP), which had only ever been asserted empty. Pass-either-way lock. | Small | ✅ done |

### Deferred — every remaining item is an IATF-auditable decision or cross-module

| Order | Finding | Ordered work | Scope | Session |
|---:|---|---|---|---|
| 4 | N-002 (was F-013) | Decide whether `production_manager` is an NCR observer or an actor, then make tier 2 of the SLA escalation match. As seeded, tier 2 notifies a role that holds only `quality.ncr.view` and therefore **403s on the one action that clears the escalation** (`POST /ncrs/{ncr}/actions`, measured). Either grant the minimum manage permission or retarget tier 2 in `quality.ncr.escalation_roles`. Do not "fix" this by widening the route. | Small | separate-recommended |
| 5 | N-003 | Freeze a closed NCR. Add an observer **and** a PostgreSQL trigger (the `journal-ledger` pairing) refusing post-closure mutation and hard delete of `non_conformance_reports` / `ncr_actions`. **Must be a per-column allow-list.** `NcrService::close()` writes the row twice — status first, then `replacement_work_order_id`/`rework_work_order_id` in a second `save()` where `OLD.status` is already `closed` — and `EffectivenessService` legitimately writes `effectiveness_*`, `verified_*` and `next_effectiveness_check_at` to closed rows. A trigger keyed naively on `OLD.status` breaks both. Consider `SoftDeletes` in the same change so an erroneous NCR can be voided without vanishing. | Medium | separate-recommended |
| 6 | N-004 | Decide the relationship between an NCR disposition and the Inventory MRB. Today they are two independent registers: `NcrService::close()` moves no stock (measured: `stock_movements` 0 → 0 on a 40-piece `scrap` close), while `QuarantineService::release()` already switches on this module's `NcrDisposition` enum and emits `Transfer`/`Scrap`/`ReturnToVendor`. `material_review_records.ncr_id` is nullable and nothing reconciles the two dispositions, so an MRB can be released `use_as_is` while its NCR says `scrap`. Cross-module (Inventory) **and** a change to what a disposition means. | Large | separate-recommended |
| 7 | N-005 | Add the concession record `use_as_is` requires (who granted it, when, against what authority) and a vendor reference so `return_to_supplier` can name the supplier and reach supplier performance. `non_conformance_reports` has no `concession`/`approval`/`grant` column and no `vendor`/`supplier` column. For incoming-QC NCRs the vendor is derivable via inspection → GRN → PO. Touches supplier-performance. | Medium | separate-recommended |
| 8 | N-006 | Decide what counts as CAPA effectiveness evidence. Today the only gate is a non-empty free-text note, `ncr_actions` has no attachment column, and verifying every action `not_applicable` rolls the NCR up to `effectiveness_status = effective` (measured). Either exclude `not_applicable` from the "Effective" roll-up, or give it its own NCR-level verdict, or require evidence. | Small | separate-recommended |
| 9 | N-007 | Decide whether the causer may absolve itself. Measured: one `qc_inspector` created an NCR, set its disposition, wrote both CAPA actions, closed it (`created_by == closed_by`) and recorded the effectiveness verdict on its own corrective action (`performed_by == verified_by`). `qc_inspector` holds the entire Quality module and tier 1 of the escalation targets the same role. | Small | separate-recommended |
| 10 | SPA-1 | `spa/src/pages/quality/dashboard.tsx:40` calls `ncrsApi.list()` (`quality.ncr.view`) from a page guarded on `quality.view`, with no `can()` gate and **no error branch** (`:192-199`), so a 403 renders as "0 total". Fix the guard or gate the panel; add the error state. | Small | separate-recommended (shared SPA files) |
| 11 | SPA-2 | `/quality/ncr-templates*` is guarded on `quality.ncr.manage` while its read endpoints are `quality.ncr.view` — blocks readers the backend would serve (measured: `production_manager` gets 200 from `GET /ncr-templates`) and a manage-only holder 403s on the page's own list call. Same shape at `/quality/ncrs/new`, which calls two `.view` endpoints behind a `.manage` guard. | Small | separate-recommended (shared SPA files) |
| 12 | SPA-3 | `/quality/dashboard` — the only Defect Pareto surface and the sole consumer of all three `quality/analytics/*` routes — has **no Sidebar entry**; reachable only via the "Quality" breadcrumb. `/quality/ncr-templates` likewise, with one inbound link buried in the New-NCR form. | Small | separate-recommended (`Sidebar.tsx` is shared) |
| 13 | DOC-1 | `docs/USER-MANUAL.md:212-215` is three sentences for a feature with 12 NCR routes, 8 template routes and 4 pages: disposition, CAPA authoring, effectiveness verification, the due-check queue, bulk close, cancel, assignees, templates and the Pareto page are all undocumented. `docs/QA-MATRIX.md:57-58` has no NCR rows. | Medium | separate-recommended |
| 14 | DOC-2 | `docs/SCHEMA.md:377,380` documents enums that no longer exist — `source (incoming/in_process/outgoing/customer)` vs the real `inspection_fail|customer_complaint`, and an action set without `containment` — plus columns the resources do not emit. The whole CAPA effectiveness column set is undocumented. | Small | separate-recommended |
| 15 | SHARED | `DocumentSequenceService::generate()` raced 4 of 8 concurrent callers into a `23505` in another session's measurement. `document_sequences` correctly has **no** `ncr` row until the first generate and then creates one; numbering and monthly reset verified. Not fixed here — `Common` scope. | — | out of scope |

### Acceptance gates (new)

- `ncr:escalate` exits non-zero and names the failure count when no candidate could be delivered; exits zero and says `0 considered` when idle. ✅ met
- An archived NCR template can be restored through its own route. ✅ met
- The Pareto drill-down row-mapping branch is exercised by a test that would fail if it returned raw integer ids. ✅ met
- A closed NCR cannot be re-opened, re-numbered or deleted by any path, including raw SQL — while the CAPA loop can still write its verdict. ⛔ open (N-003)
- Setting a disposition has a defined, reversible material consequence, or the docs state that the MRB is the sole stock authority. ⛔ open (N-004)
- `use_as_is` names its concession grantor; `return_to_supplier` names its supplier. ⛔ open (N-005)
- Every SLA escalation tier reaches a role that can clear it. ⛔ open (N-002)

---

## 2026-08-25 plan (executed; preserved for history)

Status at the time: 📋 Plan Ready. No source fixes were applied during that audit.
Everything below was implemented in the follow-up session and verified on 2026-09-01.

| Order | Findings | Ordered work | Scope | Session |
|---:|---|---|---|---|
| 1 | F-001, F-002 | Define escalation-eligible states; claim/lock candidates; persist a durable tier-delivery/idempotency record; handle empty audiences and notification failures; add in-progress, failure, and concurrency tests. | Large | separate-recommended |
| 2 | F-003, F-004, F-005 | Make inspection-linked NCR creation database-idempotent; replace description-prefix recurrence with a canonical structured defect signature; move recurrence linking/notifications to retryable after-commit work. | Large | separate-recommended |
| 3 | F-006 | Add CAPA transition rules and lock the NCR/action during verification. Permit only corrective/preventive actions on eligible closed NCRs and reject invalid/repeated transitions. | Medium | separate-recommended |
| 4 | F-007, F-014, F-015 | Design notification cadence/deduplication and links; expose owner/due-date assignment; add full CAPA service/controller tests including rollup and repeat-verification cases. | Large | separate-recommended |
| 5 | F-008, F-010 | Build the CAPA due/overdue queue, detail verification panel, and bulk-close selection/result workflow with permission-aware UI and cache invalidation. | Large | separate-recommended |
| 6 | F-009, F-016 | Normalize return-to-supplier notifications to the standard typed payload and post-commit delivery; make required scrap/rework work-order creation fail visibly or become a durable retryable state. | Large | separate-recommended |
| 7 | F-013 | Confirm whether production managers are observers or NCR/CAPA actors; align permissions, alert recipients, and route behavior with that decision. | Small | separate-recommended |
| 8 | F-011, F-012 | Add typed list-query validation and reconcile generated/frontend enum/resource contracts. | Small | same-session-ok |

### Acceptance gates (2026-08-25) — all met except the F-013 decision

- An NCR with containment only remains eligible for the intended escalation policy after entering `in_progress`. ✅
- A tier is not consumed without a durable delivery/outbox record, and repeated/concurrent runs are idempotent. ✅
- Concurrent inspection failure handling yields exactly one NCR per inspection. ✅
- Auto-generated equivalent defects link as recurrences using structured/canonical data. ✅
- CAPA verification rejects containment/open/terminal-invalid transitions and records a valid audit history. ✅
- Due alerts are deduplicated, actionable, and linked to the NCR/action. ✅
- QC and the approved manager role can discover and complete CAPA verification in the SPA. ⛔ see N-002
- Scrap/rework close cannot silently lose its required production work order. ✅
- Focused tests run against a reachable PostgreSQL service and cover each acceptance gate. ✅ (132 passed / 371 assertions)
