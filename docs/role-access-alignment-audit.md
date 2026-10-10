# Role access alignment audit

Date: 2026-10-02

## Scope and evidence

The review covers the sixteen seeded internal roles, examined individually against `RolePermissionSeeder`, the enforced workflow steps in `WorkflowSeeder`, module route middleware, record policies, dashboard dispatch and shared navigation. Finance is included as a regression check. Custom roles and explicit user grants remain administrative decisions; the migration does not synchronize the whole role catalog.

A cross-module duty grants its read or action capability, not the entire module. A readable list does not authorize a write. Approval permissions still require the correct workflow step, record scope and maker-checker checks. Self-service attendance, leave and payslip permissions do not expose operational HR or payroll pages.

## Sequential role review

| Order | Role | Primary duties and necessary supporting access | Boundary or correction |
| --- | --- | --- | --- |
| 1 | Vice President | Financial statements and budgets; purchase, payment, loan, salary-change and asset-disposal approvals; complaint and quotation review | Expose loan, salary-change and complaint queues. No supplier negotiation, sourcing, payroll preparation or employee editing. Shared Dashboard dispatches to the existing executive/plant view. |
| 2 | HR Officer | Employees, recruitment, training, attendance, leave, loans, profile review, separation and payroll preparation/statutory submissions | Expose owner queues and government contribution maintenance. Grant only the government-table feature in Administration. Remove financial write-off request/approval; no Finance bank leg or payroll approval/finalization. |
| 3 | Production Manager | Production execution and scheduling; quality/complaint review; company-loan, salary-change and return approvals; planning, inventory, procurement, maintenance and asset reads | Expose relevant review queues. Supporting reads do not grant sourcing, inventory adjustment, payroll operations or planning-master authoring. |
| 4 | PPC Head | Planning, BOMs, routings, forecasting, finished goods, work-order creation and confirmation | Preserve supporting maintenance/assets and return reads. No operational HR, procurement approval or return lifecycle management. |
| 5 | Purchasing Officer | PR/PO preparation, supplier sourcing, RFQs, supplier response negotiation, receipts, inbound logistics and supplier returns | Separate company PO reads and supplier negotiation from spending approval. Remove obsolete PR/PO approval grants. Replace broad logistics read with inbound shipment/document read; no fleet browse or dispatch. |
| 6 | Warehouse Staff | Receiving, inventory, issues, counts, picking, quarantine, return receiving and delivery staging | Expose warehouse clearance tasks. Company task reach covers only items owned by the actor's department. No financial clearance details, sourcing or delivery/fleet management. |
| 7 | QC Inspector | Inspections, specifications, nonconformance, calibration, traceability, return inspection and quarantine | Expose complaint reads. Retain quotation/inventory review without sourcing or purchasing approvals. |
| 8 | Maintenance Technician | Create/complete maintenance work; downtime and condition readings; schedule, machine and asset reads | Expose maintenance clearance tasks without final-pay details. No work assignment, schedule management, production recording or broad HR. |
| 9 | Impex Officer | Inbound shipments and documents; dispatch, delivery confirmation and fleet | Put Supply Chain first. Procurement remains scoped supporting PR/PO reads; no sourcing or purchase creation/approval. |
| 10 | Department Head | Department employees, PR creation, leave/overtime/loan/return approval and clearance | Expose loans and clearance. Remove obsolete PR approval. Employee and clearance reads remain department-scoped; revoking employee browsing cannot broaden clearance scope. |
| 11 | Sales Officer | Prepare, negotiate and confirm sales orders; read customers, products and price agreements | Preserve sales-only writes. No customer-service lifecycle, invoice creation, inventory or payroll operations. |
| 12 | Customer Service Officer | Customers, inquiries, complaints, price agreements and customer-return cases | Expose inquiries. Supporting sales-order read does not allow sales-order creation/confirmation; receiving and inspection stay with their responsible roles. |
| 13 | Driver | Assigned delivery runs, delivery outcome/proof and employee self-service | Retain own delivery route. No general supply-chain, inventory, HR or payroll-operation access. |
| 14 | Employee | Own attendance, leave, loans and payslips; common notifications and work surfaces | Preserve self-service record ownership. No back-office module navigation from self-service permissions. |
| 15 | System Administrator | Users, roles, settings, audit, sessions, backups and integration support | Put Administration first. Existing wildcard access remains; business workflow role and maker-checker restrictions still apply. |
| 16 | Finance regression | Accounting, budgets, payroll review/disbursement, financial approvals, supplier quotation review and Finance clearance | Preserve required PR/PO/RFQ reads. Add loans, clearance and financial-return discoverability without broad HR/CRM access. Supplier negotiation is Purchasing's capability. |

## Shared navigation and authorization changes

- Every role uses the single Dashboard button. Existing permission-derived dispatch remains authoritative; specialized pages do not get duplicate sidebar links.
- Inactive modules collapse after a temporary peek; active pages select the most specific matching navigation item. Desktop and mobile use the same metadata.
- Complaint, payroll-period and payroll-adjustment navigation use their read permission. Writes retain their separate action gates.
- Salary-only readers see “Salary Adjustments”; employee readers see “Employees”. Profile reviewers see “Profile Change Requests”; Finance bank reviewers see “Bank Account Changes”. Command Palette uses the same labels and gates.
- Restored notification-preference access for Purchasing, Warehouse, QC, Impex and Driver through a separate migration; existing user revokes remain authoritative.
- Loans and separation clearance have their own feature gates. Recruitment, training and inquiries are discoverable owner workflows, without exposing those pages to other roles.
- Separation list/detail reads share an actor scope. Department readers see their department's records; Warehouse/Maintenance see records containing their own clearance tasks. Unlinked restricted signers receive no records.
- Restricted clearance responses expose only department-owned checklist items, with server-computed signing eligibility. Final-pay amounts, breakdown, journal references and general remarks require company-clearance review. Stored checklist data is unchanged; completion and finalization use the aggregate's actual lifecycle status.
- Supplier-response decisions require their dedicated permission and PO visibility under the transaction lock. Notifications use effective permissions and PO scope, including user overrides. Price increases still re-enter Finance/VP approval.

## Upgrade behavior

`2026_10_02_000003_align_role_clearance_and_payroll_access.php` introduces `purchasing.po.view_all`, `purchasing.po.responses.resolve`, `supply_chain.shipments.view`, `hr.separation.view_all` and `hr.clearance.work_company` and removes only the identified seeded-role overgrants. It updates descriptions only when they still match the old seeded text. Existing explicit user restrictions are copied to extracted capabilities; unrelated roles, grants and custom descriptions remain intact.

The migration can be replayed after rollback. Rollback removes the introduced capabilities and restores unchanged seeded descriptions; it deliberately retains the corrected removal of obsolete/financial grants and the existing government-table capability. A full rollback of business authorization requires restoring the reviewed role grants explicitly. Rollback must accompany the matching code version. New custom grants on introduced capabilities are removed with those capabilities.

## Verification

Verification results are recorded below after the final runs. Initial live baseline: 601 browser authorization checks across all sixteen roles, no sidebar permission/route failures. All sixteen Action Center visits hit the same existing `422 GET /api/v1/business-policies`: the installation lacks a valid `company.vat_status`. No tax-registration value was invented during this access audit.

### Automated checks

- Backend role/workflow/dashboard/record-scope run: **191 tests, 1,107 assertions passed**.
- Latest shipment, negotiation, migration and document-access run: **72 tests, 612 assertions passed**; includes overlapping regressions from the earlier run.
- Final clearance/privacy and migration replay check: **5 tests, 81 assertions passed**.
- Notification-default migration check: **2 tests, 24 assertions passed**.
- Shared Sidebar, Command Palette and profile-queue checks: **54 tests passed**. All sixteen dashboard destinations and inactive module peeks are covered.
- `npx tsc --noEmit` and whitespace checks passed.

The two scoped migrations were applied to the running installation. Final live results:

- **864 browser authorization checks across all sixteen roles passed**, including shared Dashboard selection, inactive module collapse, desktop/mobile navigation parity, required sidebar links and denied routes.
- **176 direct API read checks across all sixteen roles passed**, including notification preferences, procurement Chain/RFQs, loans, clearance, payroll, complaints/inquiries, contribution tables, inbound shipments and fleet boundaries.
- Every live non-admin role's complete permission set matches the current seeded responsibilities. Administrator retains its documented wildcard access.
- Two browser observations of the same `422` business-policy configuration error were recorded separately. The Employee row initially reported that configuration error; it has no remaining authorization failure. A direct live policy request still returns `422` because `company.vat_status` is missing or invalid.

The deployment's VAT registration setting requires a real company value before Action Center can load normally. This audit does not change tax policy or assert that every business lifecycle in the ERP has been executed end to end.

### Independent second verification — 2026-10-02, 19:46 UTC

The second review reran the current code against the isolated role test database and the running installation:

- **228 backend tests, 1,262 assertions passed**, including procurement negotiation and read boundaries, migration replay and overrides, clearance privacy/signing, loan approval and settlement, employee/self-service scope, payroll scope, dashboard dispatch/widgets and inbound document access.
- **54 frontend tests passed** using one worker and a 20-second test timeout. The first concurrent run had one 5-second sidebar timeout; the complete rerun passed without changing product code or test assertions.
- **864 live browser authorization checks across all sixteen roles passed**. Two observations of the existing VAT configuration error were classified separately; Action Center is still affected.
- **176 live API access checks passed**. The direct business-policy request still returns `422`.
- TypeScript and whitespace checks passed.
- All fifteen non-admin roles' complete live permission sets and descriptions match the catalog. Effective permissions match fresh database grants and current user overrides; no stale permission cache was found.
- All **22 active steps in the ten enforced approval workflows** have their required route capability and at least one active authorized role holder. Record scope, the current step and maker-checker checks remain separate requirements for acting on an actual document.

Administrator has two stored catalog differences: its pivot lacks `supply_chain.shipments.view` and still includes the retired `accounting.journal.self_post_override` row. Backend and frontend administrator wildcard checks preserve effective access, and journal services no longer use the retired permission to bypass maker-checker checks. These are catalog bookkeeping differences, not permission synchronization changes made by this verification.

HR retains the existing `loans.approve` capability because the loan-limit lookup used by its loan creation form requires it. Neither seeded loan approval workflow assigns an HR step; the shared approval service rejects HR acting as an approver unless a valid delegation names the current step. Its retained settlement capability does not grant financial write-off request or approval.

No additional product changes or live permission mutations were made during the second verification. The result establishes the reviewed role boundaries; it does not certify every ERP business lifecycle or resolve the company's missing VAT registration policy.

### VAT configuration repair — 2026-10-02

The user confirmed that the company is **VAT Registered**. The blank `company.vat_status` was updated through the existing authenticated administrator settings endpoint, which recorded the old value, new value, actor and reason in the settings audit log. `COMPANY_VAT_STATUS` was also populated in the deployment's existing `api/.env` so bootstrap configuration agrees with the saved setting. The existing VAT rate remains `0.12`.

Business policies and Action Center now return **HTTP 200 for all sixteen roles**. Finance and Employee Action Center pages also loaded in the browser without page errors or failed API requests after authentication. The missing VAT configuration blocker described in the earlier verification sections is resolved. No tax calculation code, role permission or business document was changed by this repair.
