# Return Management acceptance and regression tests

Updated 2026-09-25. The two defects from the initial audit are fixed, and the real headless journeys now reach settlement and case closure. RFQ implementation was outside this work.

## Fresh retest — 2026-09-25, 07:07 PHT

Repeated the tests after the fixes using fresh databases and new source documents. **No new failures or application changes were needed.**

| Check | Result |
|---|---|
| Return Management, customer portal and targeted Quality backend suites | 162 passed, 910 assertions |
| Headless desktop/mobile UI regressions with mocked APIs | 9 passed |
| Real-account supplier/customer lifecycle, including credit issuance and closure | 30 checkpoints passed; zero findings or browser errors |
| Separate customer restock through Customer Service's disposition form | 16 checkpoints passed; zero findings or browser errors |
| Real customer/internal case-to-return navigation | 4 checkpoints passed, including two logins |

The real runs combine browser form actions with authenticated API handoffs; checkpoints include repeated account logins. The backend suite also covers no-charge replacement delivery and supplier credit/application. Cash refunds and live email delivery remain outside this test scope.

Fresh databases: `ogami_test_return_retest_0707` for backend regressions and `ogami_test_return_browser_retest_0707` for browser workflows. Backend log: `/tmp/return-retest-0707-backend.log`; UI log: `/tmp/return-retest-0707-ui.log`. Machine-readable reports and screenshots: `/tmp/ogami-return-retest-0707/` and `/tmp/ogami-return-retest-0707-restock/`. The customer mobile completion screenshot was inspected. Temporary test servers were stopped after the run.

## Real headless results

Playwright Chromium used real cookie sign-ins, CSRF protection and separate role accounts against PostgreSQL `ogami_test_return_browser_e2e_0925`. No API responses were mocked. The tests combine browser form interactions with authenticated API calls for operational handoffs. Mail uses the array driver; broadcasts use the log driver.

- **30 successful checkpoints, zero findings**: supplier shortage, supplier defect return, customer shortage/defect report, physical return, Finance credit issuance and case closure.
- **16 successful checkpoints, zero findings** in a separate customer shipment: Customer Service performs the restock disposition through the actual form and location picker, completes the RMA, and Finance issues the credits. Checkpoints include account logins and overlap between the runs; these are not 46 distinct test cases.

| Scenario | Verified outcome |
|---|---|
| Supplier shipment missing 10 kg | Purchasing reports against the PO; supplier acknowledges; unauthorized settlement is denied |
| Supplier sends 4 kg then 6 kg | Independent incoming QC; cumulative 4/6 remaining then 10/0; early closure blocked; allocation retry counts goods once |
| QC user enters and completes inspection results | Same user cannot approve; independent Production Manager can |
| Previously accepted raw material has a 1 kg defect | GRN-linked case, two approval steps, 0.4 + 0.6 kg receipts, return-to-supplier disposition, completion and case closure |
| Receipt is marked final with agreed goods outstanding | HTTP 422 before mutation; partial receipt remains available |
| Client retries an already saved installment | One receipt and one quantity increment |
| Customer reports 100 expected / 90 received / 2 damaged | 10 missing and 2 defective; only 2 enter physical return; PDF evidence uploads on a 390 px viewport |
| Customer opens another party's case | HTTP 403 |
| Customer Service requests information | Customer reads and replies through the portal |
| Customer returns damaged goods in two installments | 1 + 1 receipts, quarantine, product inspection, independent review, disposition and completion |
| Scrap and restock outcomes | Both exercised on separate customer shipments; completion does not repeat stock movement |
| Customer Service records restock | Actual disposition form and location picker work without granting general inventory access |
| Finance settlement | 250 shortage + 50 physical-return credit **before VAT**; Finance finalizes both; closure is blocked beforehand |
| Customer revisits closed case | Resolved status, actual returned quantity and both issued credits are visible |
| Timeline and runtime | Creation/event timestamps agree; no uncaught browser errors; mobile screenshot inspected |

## Additional fixes and coverage

- Inspection result authors are recorded under the inspection lock. Every evidence writer and completer is excluded from review; assignment alone no longer identifies the maker.
- Event timestamps use the application clock. The repair migration changes only legacy events with a provable database/application timezone offset and records their original values; imported or already corrected history is preserved.
- An unhandled cancelled/rejected RMA can be released from its case with an audit event so the agreement can be reviewed again.
- Private staff replies and staff evidence uploads preserve a pending customer information request. Evidence is clearly labelled as shared with case participants.
- Receipt input rejects malformed quantities cleanly. Legacy final-receipt retries and installment request keys remain idempotent.
- Customer Service owns final disposition/completion after Warehouse and Quality handoffs. Finance retains credit posting. Existing return permission slugs apply to both RMA types.
- Case/return links lead to the appropriate customer or internal pages.

The no-charge replacement HTTP regression exercises case agreement/approval, replacement SO confirmation, delivery creation, assignment, dispatch, signed proof, delivery confirmation and case closure. It verifies `invoice_handoff.status=not_required` and zero invoices. Its upstream completed production output and approved outgoing inspection are fixture prerequisites.

## Backend verification

- Full Return Management and customer portal suite: **139 passed, 829 assertions** on `ogami_test_return_case_fix`.
- Quality authorship and adjacent maker-checker/checklist regressions: **23 passed, 81 assertions** on `ogami_test_return_qc_fix`.
- The no-charge customer replacement lifecycle is included in the full suite; its focused run passed 22 assertions.
- Customer Service role boundaries: **1 passed, 19 assertions**, retaining separate receiving, QC and Finance permissions.
- Mocked-API headless UI regressions: **9 passed** (eight desktop, one mobile), covering intake, evidence retry, receipt retry, role actions, split redelivery selections and cancelled-RMA agreement recovery.
- TypeScript typecheck, targeted ESLint, PHP syntax, whitespace checks and production Vite build passed. The build artifact is `/tmp/ogami-return-build-final-0925`.
- Return migrations 190000, 200000, 210000 and 220000 are applied to the local development database. No production deployment was performed.

## Evidence and rerun

- Real API runner: `scripts/return-management-headless.cjs`
- Initial fixture: `api/tests/Browser/return_case_fixture.php`
- Separate customer shipment: `api/tests/Browser/return_case_customer_followup_fixture.php`
- Full run: `/tmp/ogami-return-headless-e2e/report.json`
- Customer Service run: `/tmp/ogami-return-headless-cs/report.json`
- Screenshots are beside those reports, including `customer-completed-credit.png` and `purchasing-partial.png`.
- Link verification: `/tmp/ogami-return-headless-cs/links-report.json` (four checkpoints including two logins).
- The original failing audit is retained at `/tmp/ogami-return-headless/report.json`.

Run the fixture only against a freshly migrated database whose name starts with `ogami_test_return_browser_`. Copy its JSON manifest from the API container to the host. Start a temporary API with that database, separate session/cache settings, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, and `BROADCAST_CONNECTION=log`. Use **`artisan serve --no-reload`** to preserve the database/session environment. Proxy a temporary SPA to that API and include its origin in `SANCTUM_STATEFUL_DOMAINS`.

```bash
NODE_PATH=./spa/node_modules \
RETURN_TEST_FIXTURE=/tmp/return-case-role-fixture-e2e.json \
RETURN_TEST_OUTPUT=/tmp/ogami-return-headless-e2e \
node scripts/return-management-headless.cjs
```

`RETURN_TEST_URL` defaults to `http://127.0.0.1:5210`. Use a fresh output directory with a fresh fixture. `RETURN_TEST_PHASE=links` verifies customer/internal case-to-return navigation on an existing run. `RETURN_TEST_PHASE=customer` resumes the customer portion and `RETURN_TEST_DISPOSITION=restock` selects restock for that customer run. The follow-up fixture needs the original fixture and an output state containing its supplier case ID for the cross-party access assertion.

## Limits and intentional controls

- Issued credit is not a cash refund. These customer browser sources had no invoice, so the credits remain available for later application; bank/cash refund processing was not exercised.
- Billed supplier credit/application and replacement PO handling are covered by backend lifecycle tests; the browser supplier return used an unbilled receipt.
- Rejected cases retain quantity and billing holds while review remains possible. A reviewed no-action resolution or withdrawal follows the existing authorization rules.
- Historical inspections without trustworthy author audit data cannot have missing authors reconstructed. The migration backfills known writers and preserves the assigned-inspector guard for older records.
- Live email delivery, production deployment and external supplier/customer systems were not exercised. Test accounts and operational writes stay in isolated databases. Temporary test servers were stopped after verification.
