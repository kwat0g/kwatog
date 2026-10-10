# Quality inspection verification — 6 October 2026

Validation only. No product code, production inspections, stock quantities, specifications, or user manual-test orders were changed.

## Scope and results

The Quality backend suite executed 401 tests with 1,721 assertions: 400 passed and one errored. The inspection frontend suite passed all 42 tests. The backend error reproduced in an independent one-test run. The final real headless browser run passed all 15 stage/scenario combinations, all 30 unauthorized write/review checks across three roles, and its desktop/mobile overflow checks. It reported no page errors or HTTP 500 responses.

Real headless Chromium uses the isolated application at `http://localhost:18080` and database `ogami_test_audit_20261002`. Backend integration tests use the separate database `ogami_test_pricing_20261004`. Browser requests use the actual UI, API, session authentication, and database; there are no mocked API routes. Fixtures are new disposable records created through the existing specification, item-plan, and inspection services. Source WO/output, GRN, and return documents are seeded, so these checks do not claim to execute their entire upstream order lifecycle.

Each browser stage is tested with an all-PASS result, an out-of-limit size with physical defects, and a document-only FAIL. In-process PASS/FAIL finalizes directly under current policy. Incoming GRN, outgoing output, customer return, and supplier return results require independent review.

| Stage | Source fixture | Coverage |
| --- | --- | --- |
| Incoming | GRN line and saved numeric supplier plan | Exact 125.500 KG retained; one lot decision; no invented piece count |
| In-process | Open WO and saved product specification | Planned sample coverage; unproduced WO target is not presented as completed stock |
| Outgoing | WO output of 100 pieces | Full coverage; independent review; passing certificate; failed certificate refused |
| Customer return | Received return with 100 pieces | Received quantity controls coverage; independent review |
| Supplier return | Received return with 100 pieces | Received quantity controls coverage; independent review |

Shared checks cover empty actual size, inclusive lower/upper limits, excessive precision rejection, automatic FAIL outside limits, required problem descriptions, actual evidence saved with draft decisions, draft recovery after reload, stale version refusal, duplicate submission refusal, attribute checks without numerical measurements, one unique defective piece failing several checks, zero physical defects for document-only failure, no PASS override of failed evidence, zero accepted quantity while awaiting review or failed, one NCR per failure, and terminal forms without submission controls. Warehouse, PPC, and employee accounts are each refused result writes and reviews for all five stages. Desktop 1440 px and mobile 390 px checks include overflow assertions and screenshots.

The wider backend suite also covers changed-source quantities, specification revision preservation, maker/checker separation across multiple authors, partial returns, rework and replacement ownership, late defects, whole-piece validation, quarantine, sorting, delivery release limits, missing stock receipts, and WO automatic closure gates.

## Confirmed findings

1. **Outgoing QC recovery conflicts with the closed-WO guard.** `TriggerOutgoingQC` accepts a delayed completion event after a WO becomes closed; `InspectionService::createForOutput()` ultimately rejects creation for that closed source. The failing test is `OutgoingQcIdempotencyTest::test_delayed_completion_event_still_creates_inspection_after_close`. Normal closure correctly requires final QC for every output, so this concerns legacy/inconsistent closed records with missing QC. The missing-QC sweep uses the same creation path and cannot repair this case automatically. Existing finalized inspection reuse is unaffected. Resolve the recovery policy without weakening ordinary WO closure or late-defect history rules.

2. **Throttled authentication bootstrap can resemble session expiry.** An initial rapid real-browser run exceeded API request limits. The screen redirected to sign-in with “Your session expired” and a separate “Too many requests” notice. `authStore.bootstrap()` catches all errors and clears authenticated state, including a temporary 429. The final verification harness spaces stages by 10 seconds; production rate limits remain unchanged. A future fix should preserve identity on transient bootstrap failures and show retry guidance.

No product fixes were made during this validation request. Original browser harness failures caused by missing failed-review remarks were corrected in the harness; fixture generators were corrected for identifier lengths and random warehouse-code collisions. These are distinguished from the two observed product issues above.

## Reproduction

```bash
docker exec -e QC_CHECKLIST_CAPTURE_ENABLED=false ogami-api php vendor/bin/phpunit -c phpunit.pricing.20261004.xml --do-not-cache-result tests/Feature/Quality
cd spa
npx vitest run src/pages/quality/inspections
```

Browser fixture creation must use only the existing isolated audit container. The seed explicitly checks its database and capture configuration and commits fixtures transactionally.

```bash
docker cp docs/testing/evidence/quality-all-stages-20261006/seed-browser.php ogami-audit-20261002-api:/tmp/qc-all-stages-seed.php
docker exec ogami-audit-20261002-api php /tmp/qc-all-stages-seed.php > docs/testing/evidence/quality-all-stages-20261006/fixture.json
node docs/testing/evidence/quality-all-stages-20261006/browser.cjs
```

Evidence: [backend run](evidence/quality-all-stages-20261006/backend.log), [independent closed-WO reproduction](evidence/quality-all-stages-20261006/closed-wo-replay.log), [frontend run](evidence/quality-all-stages-20261006/frontend.log), [browser run](evidence/quality-all-stages-20261006/browser.log), [browser results](evidence/quality-all-stages-20261006/browser-result.json).

Physical discovery still depends on performed inspection and honest coverage confirmation. One saved size reading does not prove every piece has that exact measurement. Concurrent stale snapshots are tested; these checks do not establish multi-process load performance.
