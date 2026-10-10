# Item and BOM form verification

The inventory Item form groups identity, base-unit costing, replenishment and availability. Create and Edit BOM share one form with searchable product/component choices and a scrollable component table. Selected components remain selected when search results change. Component changes select the new item's base unit; UoM is read-only and still included in submissions. Existing lines retain their saved units so editing does not reinterpret quantities stored in conversion units. Duplicate components are unavailable in other lines and rejected by form validation.

Product and component selectors combine search and selection in one field. Results load from the existing catalog APIs. Dropdowns appear outside the scrollable table, stay within the viewport and support Arrow keys, Enter and Escape. Typing without choosing a result leaves the previous selection intact.

Saving a BOM publishes a new active version. When the selected product already has an active BOM, the Create form explains that saving replaces it, preserves history and leaves existing Work Orders on their assigned versions. Edit preloads the source definition and saves a new version.

Run the focused checks from `spa/`:

```sh
npx vitest run src/pages/inventory/items/form.test.tsx src/pages/mrp/boms/form.test.tsx
npm run build
node e2e-real/item-bom-forms.cjs
```

The browser script targets the isolated audit host at `http://localhost:18080`, never the live application. It uses Warehouse, PPC and Admin accounts and leaves tagged fixtures in the audit database. It verifies item creation and edit prefill, positive minimum order quantities, automatic component units, duplicate prevention, preserved BOM history, active-version warnings, and a bounded 20-line table on desktop/mobile. It does not exercise production or inventory receipts.

Validation on 2026-10-04: six focused frontend tests passed, covering locked UoM submission, preservation of existing conversion units, keyboard selection and retained selections during search. The three-role headless browser run passed, including locked units and combined selectors on mobile, with no browser errors, API 500 responses or document-level horizontal overflow. Review captures are in `.impeccable/review/item-bom-forms/`.
