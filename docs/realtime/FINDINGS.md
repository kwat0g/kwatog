# Realtime and cache findings

## Supplier RFQ reads can close records before vendor scope is enforced

- **Evidence:** The supplier list called `closeAllDue()` before the vendor-scoped list query, and detail called `closeDue($rfq)` before the invitation lookup. Two focused regressions reproduced both effects against a disposable PostgreSQL test database: reading the list as an uninvolved supplier cancelled another vendor's overdue RFQ, and requesting an uninvited RFQ detail returned 404 only after cancelling it. The closure can hand conversion state back to the linked Purchase Request. The regressions pass after the isolated fix (`fix/supplier-rfq-authorized-close`; 2 tests / 9 assertions).
- **Impact:** A supplier read could trigger deadline-driven transitions for an RFQ outside that supplier's invitation scope. The tests prove this state change; they do not show data disclosure or a user-visible incident. The scheduled deadline closer remains an expected system transition path.
- **Classification:** Confirmed authorization-order side effect, dynamically reproduced and fixed in a separate B2B/Purchasing change.
- **Disposition:** Supplier list closure is now invitation-scoped to issued RFQs; detail checks the vendor invitation before lazy closure. The focused regressions pass. No broad API suite or E2E test ran.
