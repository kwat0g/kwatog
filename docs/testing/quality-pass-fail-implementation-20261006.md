# Quality PASS/FAIL implementation evidence

> Superseded in part by [Specification references with PASS/FAIL capture](quality-specification-reference-20261006.md): product specification fields are restored, and new QC uses checklist capture from existing approved numeric definitions. The results below document the earlier implementation.
New checklist specifications, supplier/item plans and inspections use written acceptance criteria and one PASS/FAIL decision per check. All five stages share the atomic checklist contract. A batch of 100 pieces with three checks creates three result rows, rather than 300 measurement entries.

## Delivered behavior

| Area | Result |
|---|---|
| Authoring | Check name, acceptance criterion/reference and Critical toggle. No nominal, tolerance or measurement-unit controls. Supplier scope and sampling stay in the quality plan. |
| Capture | Required coverage attestation; failure details appear after FAIL. Physical defects require one unique piece count, or one affected lot for bulk material. Document-only failures permit zero physical defects. |
| Large checklists | Local search, Needs attention and an explicit attestation before PASS remaining checks. Hidden checks remain included in submission; FAIL is never overwritten by the bulk action. |
| Evidence | Criteria and revisions remain pinned. Complete submission, draft, review and cancellation use a result version under source-first row locks. Stale/terminal writes are rejected. |
| Verdict | Any failed check or physical defect produces FAIL. Criticality comes from failed critical checks. A legacy positive acceptance count cannot convert a new FAIL to PASS. |
| Warehouse | Canceled-only QC cannot accept stock. Incoming bulk retains its exact receipt quantity/UoM; defective-lot counts are never subtracted from kilograms. Reviewed MRB sorting uses base-unit quantities and retains the original FAIL. |
| Sources | Receipt line, WO/output, product and return type/received quantities are checked. Source changes before submission or review require reconciliation. |
| Production and returns | Existing hold/recovery, WO closure and return-disposition gates remain in force. Replacement retargets the RMA link while preserving the predecessor. No automatic new WO, restock or credit was introduced. |
| Certificates | New certificates print check, criterion, decision, actual coverage, revision, inspector and checker. Historical numerical evidence remains available. New inspections do not claim Cp/Cpk. |
| Migration | Additive schema defaults existing records to measurement capture. Destructive rollback refuses populated checklist evidence. |
| Replacement | Explicit reason, open legacy inspection, no known failure/NCR/released stock, approved checklist definition. Retry returns the same successor. Old rows remain preserved and linked. |
| Interface | Details above the checklist, record flow/linked records/activity preserved, semantic warnings, 44px decision targets, mobile layout, error recovery and existing unsaved-change guard. |

## Verification

Checks ran serially against `ogami_test_pricing_20261004`, never against live business data. Browser transactions used the isolated application `http://localhost:18080` and `ogami_test_audit_20261002`.

- Backend regression: **201 tests, 843 assertions passed**. Includes checklist capture/lifecycle/migration, legacy specification/measurement/review contracts, incoming QC/MRB, WO QC/rework, certificate integrity, analytics and return handoff.
- Final boundary regression after review fixes: **21 tests, 124 assertions passed**. Includes accepted HTTP numeric representations and retained return history/link replacement.
- Frontend: **32 tests passed** across authoring, inspection detail, historical checklist capture, new checklist decisions and incoming-plan authoring.
- Palette contrast: **93 tests passed**.
- Token discipline: **913 files checked; clean**.
- Targeted PHP formatting and frontend lint passed.
- Production TypeScript/Vite build passed; final build result is retained with the logs below. Whitespace checks passed.
- **Three real headless Playwright tests passed**, exercising all five stages, actual WO/RMA sources, 125.500 KG incoming source, independent review, maker rejection, terminal retry rejection, certificate download, desktop/mobile layout, 44px targets and dark/floor authoring. Additional role contexts verify employee, Warehouse and PPC cannot record or review QC evidence.

The browser suite uses real API/database responses without intercepted transaction mocks. Independent review is performed by an authenticated browser request context; capture and layout run through the visible UI. Warehouse/MRB stock quantities and retry/GL behavior are verified by backend integration tests, rather than claiming a manual browser walkthrough of every downstream module. Customer/supplier portal navigation and multi-process database stress testing were not added to this focused browser suite.

## Retained artifacts and reproduction

[Certificate PDF](evidence/quality-pass-fail-20261006/checklist-certificate.pdf), [rendered certificate](evidence/quality-pass-fail-20261006/checklist-certificate.png) and [extracted certificate text](evidence/quality-pass-fail-20261006/checklist-certificate.txt) demonstrate three check decisions and a planned sample of 20 pieces from a source of 100. No Nominal or Actual Range table is printed.

[Isolated fixture script](evidence/quality-pass-fail-20261006/seed-browser.php) refuses any database other than `ogami_test_audit_20261002` and requires checklist capture enabled. It creates unique test accounts/records, without changing existing account passwords. These credentials are disposable isolated fixtures.

```bash
docker exec -i ogami-audit-20261002-api php < docs/testing/evidence/quality-pass-fail-20261006/seed-browser.php > /tmp/qc-checklist-fixture.json
cd spa
QC_CHECKLIST_FIXTURE=/tmp/qc-checklist-fixture.json E2E_BASE_URL=http://localhost:18080 ./node_modules/.bin/playwright test -c playwright.real.config.ts quality-checklist-capture.spec.ts --project=real-chromium
```

[Real browser suite](../../spa/e2e-real/quality-checklist-capture.spec.ts) reuses the existing headless configuration and explicitly rejects a base URL outside the isolated application. Test logs, screenshots and focused review evidence are stored under `docs/testing/evidence/quality-pass-fail-20261006/` and `/tmp/qc-pass-fail-20261006/`.

## Rollout and limits

The additive migration is applied to live and isolated applications. New checklist authoring is enabled in the live application; the managed queue worker was signaled to reload compatible code and configuration; the compatible options contract and schema presence were verified without creating live business records. Enabling authoring does not automatically rewrite a product's current numeric specification or an existing supplier plan. Publish the approved written checklist revision first. New inspections then pin that revision; eligible open legacy records can use the explicit replacement action. Known failures continue through recovery, and completed historical records retain their evidence.

Incoming default checks reuse existing configured acceptance instructions. No drawing, gauge, tolerance or approval was invented for live products. No live demonstration orders, QC results, stock movements or password resets were created.

Pause new creation using `QC_CHECKLIST_CAPTURE_ENABLED=false` if necessary, while continuing to serve existing checklist records with this compatible application. Do not restore an older binary or drop the additive columns after checklist evidence exists.

The approved plan's task implementation is complete. Detailed test suggestions were consolidated into existing suites and the real-browser harness rather than creating a parallel framework or duplicating fixtures. No new dependencies were added to the application.
