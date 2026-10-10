# Specification references with PASS/FAIL capture — 2026-10-06

Product Inspection Specifications again support classification, UoM, nominal, minimum, maximum, critical checks and instructions. Numeric references are validated and preserved in immutable revisions. A written criterion, supporting instructions or actual acceptance limits is required; a check name alone does not invent an acceptance standard.

With checklist capture enabled, newly created inspections use one PASS/FAIL decision per requirement across all five stages, including existing numeric product specifications and supplier plans. Source quantities and approved sampling remain authoritative. For 100 pieces and three checks, three decisions cover the required sample; physical inspections still have to be performed. Full inspection means checking all 100 pieces, without entering 300 separate results.

FAIL requires a problem description. Physical defects require one unique defective-piece count, irrespective of how many requirements that piece fails. Any failed check holds the batch for the existing review and disposition process. It does not automatically approve the remaining pieces or return them to production. Bulk material keeps its original quantity and UoM and uses lot coverage.

Limits are read-only references in QC and printed on new certificates. Actual measurements remain null; Cp/Cpk is not fabricated from nominal values or limits. Existing inspection records retain their capture method, pinned revision and evidence. Eligible open legacy records can use the existing explicit replacement action; known failures cannot be replaced to bypass recovery.

## Verification

- Backend and chain verification passed: **98 tests**. The log is recorded in `evidence/quality-specification-reference-20261006/backend-final.log`.
- Ten frontend tests passed, including preserving signed specification values in publication, showing references without actual-value entry, and retaining failure input.
- Three real headless Playwright tests passed: all five QC stages, independent review, certificate download, Warehouse/PPC/employee access restrictions, and desktop/mobile/dark/floor layouts.
- TypeScript and production build passed. ESLint passed. Token audit checked 913 files. The interface detector reported no findings.
- Test records and accounts were created only in `ogami_test_audit_20261002`; disposable backend tests used `ogami_test_pricing_20261004`. No live business records were seeded.
- Live queue workers were gracefully signaled to reload compatible code and remain active. No schema change was needed for this revision.

## Reproduce browser verification

```sh
docker exec -i ogami-audit-20261002-api php < docs/testing/evidence/quality-specification-reference-20261006/seed-browser.php > /tmp/qc-spec-reference-fixture.json
cd spa
QC_CHECKLIST_FIXTURE=/tmp/qc-spec-reference-fixture.json E2E_BASE_URL=http://localhost:18080 ./node_modules/.bin/playwright test -c playwright.real.config.ts quality-checklist-capture.spec.ts --project=real-chromium
```

The fixture refuses other databases and requires checklist capture enabled. Existing products with checks that lack both limits and acceptance instructions require Quality to complete the specification before new QC can be created. Supplier-plan authoring retains its existing written-check interface; previously approved numeric supplier plans remain usable as references.
