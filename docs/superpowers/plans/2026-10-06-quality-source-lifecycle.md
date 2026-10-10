# Quality source lifecycle implementation plan

**Goal:** Resolve the fifteen approved audit findings without replacing existing stock, return, MRP, and quality ownership rules.

**Architecture:** Add guards at shared lifecycle services, preserve completed production versus quality closure, and reuse quarantine, outbox, return allocations, and delivery drafting. New checklist captures require full incoming/outgoing piece coverage; historical evidence remains unchanged.

**Stack:** Laravel, PostgreSQL, React, TypeScript, existing PHPUnit/Vitest/Playwright.

**Approved spec:** `docs/testing/quality-source-lifecycle-audit-20261006.md`.

## Execution constraints

- Preserve unrelated dirty workspace changes and existing records; no blanket reopening or invented measurements.
- Run backend database tests serially against `ogami_test_pricing_20261004`, never live production.
- Use GPT-6-Sol for subsequent subagents, per the user's latest instruction. The earlier frontend agent was stopped and its changes reviewed by root. GPT-6-Sol review deployment was unavailable in this session; root completed the review directly.
- Commit, merge, and destructive data migration are outside this execution.
- Keep PASS/FAIL per requirement; FAIL requires a problem, count pieces uniquely, retain bulk-material units.

## Tasks

- [x] Shared transition guards: prohibit cancellation after physical production, exclude cancelled manufacture from MRP credit, require an explicit short-completion reason, ignore cancelled incoming QC on retries, reject new closed-WO QC with an actionable late-defect message. Test the existing audit reproduction fixtures against the corrected outcomes.
- [x] Inspection coverage and recovery: new piece-based incoming/outgoing checklists use full batch coverage; in-process correction verifies the planned process sample and separately accounts for affected material. Legacy inspection policy stays pinned. Test large batches without creating per-piece rows, bulk units, and process recovery.
- [x] Incoming defects: hold remaining accepted receipt-lot quantities using existing quarantine records; expose holds and consumed-material traceability; use accepted-stock supplier return semantics. Reject unsupported local Scrap on pending receipts. Apply whole-piece MRB validation in shared services. Test no double reversal, stock hold, and fractional rejection.
- [x] Returns: explicitly reuse checked incoming evidence for reversed supplier goods; stage item-quality-plan return checks for other item-only physical lines; independent review for new return checks; controlled pre-disposition quantity splits preserve receipt/allocation/quarantine totals. Test source identity, mixed 99/1 disposition, duplicate submissions, and credits.
- [x] Automation: emit an approved-output event through existing outbox codec and reuse release-safe delivery drafting; source-specific internal notifications include relevant PPC/warehouse/purchasing and assigned repair personnel. Test stale-event rejection, idempotent drafts, and ownership.
- [x] UI and historical visibility: distinguish production finished, QC pending, rework, and replacement progress; show current holds on closed sources, old missing evidence, original/net receipt quantities, precise stock-bin guidance, and quantity-safe return split. Use current components, permissions, and semantic tokens.
- [x] Verification: invert audit probes into regression tests; run targeted backend suites serially, frontend tests/build/lint/token checks, headless role-account browser scenarios on disposable application, then inspect desktop/mobile captures and review the complete task diff. Update audit findings with actual implementation and evidence.

## Minimal policy decisions already authorized

Pending incoming receipts cannot select local Scrap until a distinct supplier-authorized disposal workflow exists; Return to supplier remains available. Production equipment is released when production finishes, rather than held through QC/replacement waiting. Good-stock release still requires independent evidence. Defective received quantities split before disposition, so existing one-owner movement/credit logic stays intact. Full checklist inspection records the attestation without fabricating numerical measurement observations.
