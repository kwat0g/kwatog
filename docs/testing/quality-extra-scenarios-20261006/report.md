# Additional QC scenario audit — 6 October 2026

**Status:** Approved fixes are implemented. This file preserves the pre-fix baseline; see [implementation and verification](implementation.md) for current behavior.

This audit extends the completed QC source-lifecycle work. It tests the current code and records remaining gaps; it does not change live business rules or stock data. Backend fixtures use only `ogami_test_pricing_20261004`; browser fixtures use only `ogami_test_audit_20261002` at `http://localhost:18080`.

## Confirmed findings, ordered by impact

| Priority | Scenario and observed result | Responsible code | Recommended smallest fix |
| --- | --- | --- | --- |
| High | Accept 100 PCS without a lot number; report a late defect while quarantine setup is unavailable; transfer all stock into another normal bin. Hold at the destination becomes **0.000**, although the incident remains open. This can make suspect goods available for subsequent issue. | `QualityStockHoldService.php:123`; `StockTransferService.php:24` | Block transfers of logically held untracked stock until it receives a controlled physical hold. Automatically assign an internal receipt lot for new receipts when no supplier lot is supplied. Preserve historical evidence. |
| High | An authorized caller can partially accept **99.500 PCS** from a 100-piece passed receipt. Real stock and PO accepted totals receive the fractional quantity. | `GrnService.php:818` | Enforce whole PCS at the shared acceptance/movement boundary, including direct service callers. Keep bulk units decimal-safe. |
| Medium | A quarantine hold accepts **0.500 PCS** and moves half a piece into quarantine. The earlier incoming MRB sorted-good guard does not cover this stock-hold path. | `QuarantineService.php:382`; `StoreMrbRequest.php:54` | Reuse whole-piece validation for hold creation and releases; reject before stock mutation. |
| Medium | A late incident against an originally **passed** incoming inspection correctly holds stock logically when quarantine setup is unavailable. After setup is restored, the MRB quality selector still lists only failed inspections/NCRs, so the valid late incident is absent. | `QuarantineService.php:316`, `QuarantineService.php:327` | Include active late incoming incidents and their original passed inspections in eligible hold choices. Offer an explicit retry of missing physical holds. |
| Medium | Without a receipt lot, adding 10 unrelated units of the same item to the bin causes a late incident for the original 100 units to claim **110.000** suspect units. This is fail-closed but interrupts unrelated work and blurs incident quantities. | `NcrService.php:236` | Generate an internal receipt lot for new untracked receipts. Historical mixed stock needs controlled reconciliation rather than invented retrospective provenance. |

### Concession policy risk — behavior confirmed, not a failed safety invariant

A late report of one cracked piece can be followed by independent `use_as_is` release of the entire 100-piece hold with a generic full-reinspection evidence reference. This is an explicit concession, so allowing release without repair is intentional; the probe expecting repair was too strict. However, the release does not require separate concession authorization, customer sign-off, or recording which reported defect was accepted. The enum documents customer sign-off, while the release request accepts only ordinary notes/evidence. Require a concise concession approval/reference when retaining known defects; critical failures must continue to block concessions. Do not silently redefine every concession as a repair.

Responsible code: `QuarantineService.php:647`, `ReleaseMrbRequest.php:33`, `NcrDisposition.php:12`.

## Proven working scenarios

- 59 existing regression tests / 386 assertions passed: stale completion and cancellation, result authors, review queues, cancelled inspection reuse, repeated MRB release with stale model instances, held-stock adjustment protection, split supplier redelivery receipts, supplier-return lifecycle and credits, customer cross-document quantity limits, recovery proof, delivery limits, process gates and incoming handoff retries.
- 17 checklist lifecycle tests / 143 assertions passed, including two new adversarial checks: a partial return changed from 40 to 50 while awaiting review cannot be approved with old evidence, and an inspector who contributed a draft cannot approve the result authored by another inspector.
- A selected unrelated tracked lot cannot be substituted into the original late incident. The selected-lot provenance guard holds when the receipt has its lot ID.
- Three real headless Playwright tests passed covering all five stages, independent review, repeat submission rejection, certificate generation, Warehouse/PPC/employee write restrictions and desktop/mobile/dark/floor layouts.

Two additional real headless failure-path browser tests passed: outgoing one-piece/two-check defects remain counted once; incoming missing-certificate failures require no fabricated piece count; failed drafts survive reload; checker PASS overrides are rejected without changing evidence; independent FAIL creates exactly one NCR; failed records cannot produce certificates or be resubmitted. Total browser checks: five passed. Browser harness initially assumed NCR was embedded in the inspection response; it was corrected to verify the actual filtered NCR endpoint.

## Reproduction and limits

`QcAdditionalIncomingProbeTest.php` is an audit artifact outside the normal test suite. It reuses the existing disposable incoming fixture. Its six probes produce five expected failures and one passing tracked-lot guard. Four failures are implementation gaps; the fifth is the intentionally strict concession-policy probe described above.

Run it inside the API container after copying the artifact:

```bash
docker cp docs/testing/quality-extra-scenarios-20261006/QcAdditionalIncomingProbeTest.php ogami-api:/tmp/QcAdditionalIncomingProbeTest.php
docker exec -e QC_CHECKLIST_CAPTURE_ENABLED=false ogami-api php vendor/bin/phpunit -c phpunit.pricing.20261004.xml --filter test_extra_ /tmp/QcAdditionalIncomingProbeTest.php
```

The stale-instance race tests verify competing snapshots and replay protection, not simultaneous multi-process load. Physical defect discovery remains human evidence; PASS/FAIL does not produce measured Cp/Cpk. No production changes were deployed during this additional audit. GPT-6-Sol delegation was attempted but failed with deployment HTTP 404; root performed the audit and tests directly.
