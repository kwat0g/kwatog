# QC additional scenario fixes — 6 October 2026

The approved additional audit findings are implemented. Inspection entry remains PASS/FAIL per requirement, with a problem description for FAIL. These changes protect the stock and recovery processes behind that interface.

## Changes

- Base-piece quantities are validated in the existing unit conversion service and shared stock movement boundary. Direct GRN acceptance, converted receiving, quarantine holds and releases reject fractional pieces. Bulk units retain decimal quantities.
- New direct and finalized-draft receipt lines receive an internal `RCV-` lot when no lot was supplied. Supplied lots and historical receipt evidence are preserved. This prevents a late incident from claiming an unrelated receipt in the same bin.
- Historical untracked accepted stock under an active late incident cannot escape its logical hold through a normal transfer. A source-linked quarantine hold remains permitted.
- Late incoming NCRs and their originally passed inspections are available in the quarantine quality selector. The NCR page exposes **Retry missing quarantine holds** after warehouse setup or reservations are corrected. Retry preserves the original incident quantity, counts existing held/released records, skips previously released stock in its release bin and never creates another hold after all original quantity is accounted for. Missing physical stock leaves an explicit error and the logical hold intact.
- Use-as-is retention of a known defect requires separate authorized concession/customer sign-off evidence in both NCR settlement and quarantine release. The reference cannot be the ordinary reinspection reference. Existing independent reviewer rules remain. Critical inspection failures and critical late incidents cannot be conceded. Normal passed customer-return restocking does not require concession evidence.
- Approval references are displayed on the record. Existing reviewer and release actor/time fields provide attribution. The system records the referenced approval; it does not independently verify an external customer document.

## Verification

Backend tests use only `ogami_test_pricing_20261004`. Headless browser tests use fresh records in `ogami_test_audit_20261002` at `http://localhost:18080`; no live business records were seeded.

- Six original additional probes were converted into permanent incoming MRB regressions with corrected acceptance criteria for intentional concessions.
- 80 adjacent backend tests / 429 assertions passed, including receiving, unit conversion, transfers, quarantine, stale releases, supplier returns and split receipts.
- 42 incoming and batch recovery tests / 209 assertions passed after extending concession evidence to the NCR path.
- Final critical-concession and split-lot recovery checks: 2 tests / 9 assertions passed after the final changes.
- Final incoming MRB rerun: 32 tests / 124 assertions passed, including partial-release retry across two bins.
- Six real Chromium browser tests passed: three five-stage/layout/access tests, two failure persistence and independent review tests, and one quarantine retry/concession release test.
- TypeScript, targeted ESLint, production build and PHP style verification passed. Browser selector mistakes were corrected against actual rendered headings/comboboxes; no application behavior was weakened to satisfy tests.

Two additive migrations store nullable approval references on MRB and NCR records. Both were applied to the isolated browser database and live database. Historical approvals are not invented. Rollback refuses to discard recorded approval evidence. The rebuilt frontend is published and the queue service is active.

The earlier `report.md` and standalone probe file preserve the pre-fix audit baseline. Their failures describe the old implementation; use the permanent test suite for current behavior.
