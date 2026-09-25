# Return Management review and implemented flow

Reviewed and implemented locally on 2026-09-25. See the [latest headless acceptance report](RETURN-MANAGEMENT-HEADLESS-TEST.md) for completed real role-account journeys and the fixes to QC self-approval and timeline timestamps.

## Findings

Ogami already had supplier returns for rejected receipts and previously accepted defective raw materials. Customer portal RMAs also existed. The missing piece was a shared, understandable path for reporting shortages and defects together and following them through resolution.

| Gap or risk | Implemented response |
|---|---|
| No complete shortage-reporting journey | One problem case from a customer delivery, Ogami goods receipt, or PO; wholly missing shipments do not need a GRN |
| One-line customer intake, duplicated reason limits | Multiple affected lines, source labels, optional photos/PDFs, lot/serial details, and compatible reason lengths |
| Duplicate claims through different source documents | Shared RMA/case quantity limits; PO and GRN shortages reconcile to the PO's unreceived base quantity; identical open reports reuse their case |
| System-created drafts could not be triaged normally | Authorized return managers can edit system/case drafts; ordinary personal drafts retain ownership checks |
| Approved returns could not be cancelled before handling | Cancellation before physical effects releases source reservations |
| Only one physical-return receipt | Idempotent installment receipts, cumulative/remaining quantities and final receipt control |
| Five kilograms could create five replacement bags | Purchase/base-unit conversion in replacement quantities, open obligations and PO acceptance status |
| Disputed goods could be invoiced | Delivery confirmation and invoice creation/finalization check unresolved cases; posted documents remain unchanged |
| A replacement could charge the customer again | Explicit approver action creates the case's zero-price SO; normal confirmation/QC/dispatch remain; billing is prohibited for that order |
| Unrelated credits or incomplete returns could close cases | Source-linked financial documents, actual return coverage and completed delivery/receipt checks |
| An incorrect resolution link could strand a case | Validate every selected document before saving; offer case-linked customer replacement orders and allocate accepted supplier receipt quantities from the correct PO lines |
| Supplier shortages could credit goods actually received | Missing goods must be represented on the payable; otherwise retain the PO balance or use Purchasing's reviewed short-close process |
| Unclear ownership and customer follow-up | Customer Service/Purchasing ownership, public timeline, protected evidence, email updates, supplier acknowledgment and rejection-review path |
| Agreements could promise an impossible outcome | Reject return-only settlement of shortages, zero-quantity operational agreements, and customer replacements that exceed sales-order precision before handling starts |
| QC result author could approve another assigned inspector’s record | Track all result authors and completers; enforce independent review |
| Events appeared eight hours before case creation | Consistent application timestamps and a guarded, auditable historical repair |
| Lost receipt responses or short final receipts could strand settlement | Idempotent retries and final-receipt coverage checks before mutation |
| Cancelling an unhandled RMA stranded the case | Audited release of the cancelled/rejected return and agreement revision |
| Staff updates incorrectly cleared requests for customer information | Private replies and internal uploads preserve the pending request |
| Customer Service could not finish the customer return | Disposition/completion grants and limited warehouse-tree lookup, retaining QC and Finance controls |
| Authorized staff could lose case actions in the UI | Separate review, replacement approval and Finance permissions; collect reasons for withdrawal/review and show action failures |

## Using the flow

Customers open a delivered or confirmed delivery and choose **Report a problem**, or open **Problem reports** in their portal. Ogami staff use the same action from a GRN or PO. Select affected lines, enter actual received and defective quantities, describe the issue, and choose redelivery, credit or help deciding. Evidence is optional at submission and can be added later.

A report of 100 expected, 90 received and 2 defective means **10 missing, 2 defective and 88 received without a reported defect**. Only the 2 defective goods can enter a physical-return workflow.

The owning team verifies quantities, records the agreement, and starts the existing return or Accounting workflow. Customer replacements require explicit approval. Supplier shortages stay owed on the original PO unless Purchasing formally changes that obligation. Customers and suppliers can follow progress and reply in the case.

Physical return credits and shortage credits are shown separately. A draft credit is not an issued credit or a cash refund. Cases cannot resolve until the agreed documents are complete, or an authorized reviewer records the permitted no-action decision. Supplier no-action closure with a verified shortage also requires the PO to be closed or cancelled.

## Operational notes

- The new migrations were applied to the local development database; no production deployment was performed.
- Existing documents own stock and money. Case intake itself never receives missing stock, issues credit, or creates new supply.
- No automatic return window, refund entitlement or response deadline was invented. Reviewers record the agreement and expected date.
- Sales orders currently support two decimal places. Agreement and replacement authorization reject quantities that cannot be represented without rounding; the original report keeps its exact quantity.
- Split supplier redelivery receipts were verified in the isolated headless environment: 4 kg plus 6 kg settles a 10 kg shortage, with no premature closure or repeated allocation.
- Evidence and email use the existing private storage and queue configuration. Failed email delivery produces internal follow-up through the existing failure notifier.

See [the experience plan](RETURN-MANAGEMENT-EXPERIENCE-PLAN.md) for the intended journeys and validation scenarios.

## Verification

- Full Return Management and customer-return portal suite: **139 passed, 829 assertions**. This includes receipt retries, malformed quantities, final coverage, cancellation recovery, private follow-up, timeline repair, supplier credit/application and replacement PO handling.
- Quality authorship and adjacent maker-checker/checklist coverage: **23 passed, 81 assertions**.
- Real headless Chromium: **30 successful checkpoints with zero findings**, followed by a separate **16-checkpoint customer restock journey with zero findings**. The second run verifies Customer Service’s actual disposition form and location picker.
- Real customer/internal case-to-return navigation: four further checkpoints passed, including both account logins.
- The no-charge customer replacement test, included in the full suite, uses real sales-order confirmation, delivery creation/dispatch/proof/confirmation and case closure; it verifies that no invoice is created.
- Mocked-API Chromium regressions: **9 passed**, including desktop and mobile flows. The separate Customer Service role boundary test passed 19 assertions.
- TypeScript typecheck, targeted ESLint, PHP syntax, whitespace checks and production Vite build passed. The build artifact is `/tmp/ogami-return-build-final-0925`.

See the [acceptance report](RETURN-MANAGEMENT-HEADLESS-TEST.md) for exact scenarios, evidence paths, rerun instructions and the distinction between browser UI interactions, authenticated API handoffs and mocked UI regressions.
