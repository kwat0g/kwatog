# Maintenance spare-part requests and warehouse handover

Approved design: technicians request spare parts without changing stock. Warehouse confirms physical delivery to the active assigned Maintenance employee. Confirmation posts one issue at source WAC, preserves lot allocation, and records receipt. Outstanding requests and unconfirmed existing issues prevent Passed, consumption confirmation, repair submission, and completion. Inspection notes remain allowed. Pending requests can be withdrawn; work-order cancellation withdraws them. Finished historical records remain unchanged and missing receipts appear as Needs review.

## Implementation

- [ ] Add a request table/model and transactional request, withdrawal, and confirmation service. UUID replay, active assignment, source stock, whole-piece and closed-order checks stay authoritative.
- [ ] Replace public direct issue with request creation; retain the issue service for Warehouse confirmation only.
- [ ] Include maintenance requests and existing maintenance issues in Material Handover, with recipient and evidence details. Warehouse-only confirmation must never post a second issue for historical movements.
- [ ] Enforce pending handover gates under the MWO lock. Show readiness and request status on desktop and mobile, rename Add part to Request spare parts.
- [ ] Add focused feature and frontend regression tests; update affected issue fixtures to use real Warehouse confirmation. Run isolated database tests, type checking, lint, and production build.
- [ ] Apply additive migration and clear route cache; inspect live data read-only. Guide a fresh manual MWO test.

## Compatibility and limits

Existing completed MWOs and stock movements are not rewritten. Historical receipt gaps are displayed, never auto-attested. Pending requests do not reserve stock: usable quantity and cost are checked again at confirmation. Multiple lot movements remain one request, and Warehouse confirmation records evidence for every movement atomically. Concurrent confirmation, return, withdrawal and closure serialize through the parent MWO lock.
