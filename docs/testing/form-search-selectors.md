# Combined form search selectors

Record selectors in 40 create/edit forms now use the shared searchable Select: 81 fields across inventory, purchasing, CRM, production, quality, finance, budgeting, HR, administration, maintenance and supply chain. The BOM form also uses the same underlying searchable control. Fixed status/type choices and unit lists retain their existing native dropdowns. BOM component units remain read-only.

Search lives inside the selector. Local choices filter immediately; remote search callbacks wait 300 ms after the last keystroke. Closing the selector cancels pending callbacks. Existing standalone server searches in product customer assignments and return-case document lookup also wait 300 ms. Existing catalog APIs, permissions and pagination limits are retained; local-only selectors search their loaded choices.

The shared adapter retains the native select for React Hook Form registration, record IDs, reset behavior and existing change/blur handlers. Server-filtered selectors retain selected records outside the current results. Dependent local selectors clear unavailable selections instead of silently choosing the next record. Existing field sizes and layout are preserved.

From `spa/`, run:

```sh
npx vitest run src/components/ui/Select.test.tsx src/pages/inventory/items/form.test.tsx src/pages/mrp/boms/form.test.tsx src/pages/quality/inspection-specs/editor.test.tsx
npm run build
node e2e-real/form-search-selectors.cjs
node e2e-real/item-bom-forms.cjs
node e2e-real/customer-standard-pricing.cjs
```

Validation on 2026-10-04: 14 focused tests passed. The headless form check passed for 12 representative forms using 11 role accounts: Warehouse, Purchasing, Production, PPC, Customer Service, Sales, QC, Finance, HR, Admin and Impex. It verifies selected record IDs, dependent controls, one request after rapid typing, and mobile dropdown positioning. BOM item creation/versioning and the customer pricing browser flows also passed. These scripts target the isolated audit host at `http://localhost:18080`; their fixtures do not modify live customer orders.

Review captures are in `.impeccable/review/form-search-selectors/`, `.impeccable/review/item-bom-forms/` and `.impeccable/review/customer-pricing/`. The representative form check exercises selection, not submission of every business transaction.
