# Finance role access alignment

Reviewed 2026-10-02 against the permission catalog, workflow definitions, route middleware, record policies and RFQ workflow documentation.

## Finance responsibilities

Finance manages accounting, budgets, loans and fixed assets. HR creates and computes payroll; Finance reviews, returns corrections, approves, finalizes and disburses it. Finance also participates in purchase approvals, financial return approvals, high-value stock adjustments, employee bank changes and Finance clearance.

The shared Dashboard navigation opens the Finance dashboard. Finance navigation contains Finance, Procurement, Payroll and Assets. Approval Queue, Action Center and notifications remain available. Inactive modules collapse using the shared sidebar behavior.

## Procurement boundaries

| Capability | Finance | Vice President | Purchasing | QC |
| --- | --- | --- | --- | --- |
| Read PRs and POs | Yes | Yes | Yes | No |
| Approve PRs and POs | Current workflow step only | Current workflow step only | Current workflow step only | No |
| Read RFQs and compare closed quotations | Yes | Yes | Yes | Yes |
| Create, publish or award RFQs | No | No | Yes | No |
| Create orders, select suppliers or send orders | No | No | Yes | No |
| Review supplier listings | No | No | Yes | No |
| Company Procurement Chain overview | No | No | Yes | No |
| Supplier performance | Read | No | Read and recompute | No |

RFQ review is an independent grant. `docs/SUPPLIER-RFQ-BIDDING-PLAN.md` section 5 explicitly grants Finance, VP and QC read access; prices remain sealed until closure. This supports review of costs and quality without granting sourcing operations.

`purchasing.view` reads PRs and POs through their existing record policies. It does not grant Chain, RFQ operations, supplier management or supplier listing review. `purchasing.chain.view` separately controls the company pipeline in the API, browser route, sidebar and command palette. Purchasing and System Administrator receive that permission by default. Additional roles require an explicit grant.

Department heads retain department-scoped PR/PO reads and PR creation. Production Manager and Impex retain existing scoped supporting reads. Warehouse receives receivable PO data through its inventory endpoint. Their supporting reads do not unlock the company Procurement Chain. PR/PO dashboard totals now reuse the same record policies; supplier-performance widgets require the dedicated performance permission.

## Bank change approval

Finance uses `hr.profile_updates.finance_review` to open the bank-change queue and approve its leg after HR approval. No general employee access is granted. The queue includes financial requests only, hides unrelated contact changes, and does not link Finance to employee records. HR review permissions, department boundaries and self-review restrictions remain enforced.

## Migration and verification

The migration introduces Chain access without synchronizing or replacing existing roles. Existing Purchasing read revokes also revoke the extracted Chain capability. Existing PR/PO grants, approval permissions and custom role descriptions remain intact. Rollback removes the new capability and restores catalog labels and widget metadata.

Regression coverage checks every seeded role against Chain access, Finance/VP procurement boundaries, RFQ action permissions, bank queue reads and approvals, migration repetition/rollback and Purchasing user restrictions. Existing role responsibility, workflow permission, PR/PO record scope, HR review and dashboard checks provide additional coverage. This review establishes these boundaries; it is not an assertion that every feature in the ERP has been exhaustively audited.
