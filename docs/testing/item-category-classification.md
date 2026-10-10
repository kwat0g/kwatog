# Item category classification

New and Edit Item forms use one Category selector. The item type is derived from that category and remains stored internally for BOM, inventory, and accounting. Category setup requires an inventory type only for top-level categories; children inherit their parent's type.

The additive migration maps standard categories explicitly and other existing category trees only when their items have a single type. Mixed or empty custom categories remain unconfigured. It never changes existing items. Item lists, details, and edit forms identify legacy conflicts. Unrelated edits preserve their type; selecting a matching category resolves the conflict.

The API rejects unconfigured or inactive categories for new items, explicit category/type mismatches, hierarchy cycles, and category type changes that would conflict with existing items, including archived items. Items already used by stock, movements, counts, BOMs, or products cannot be reclassified. Unused items can move to another type through a category change.

## Verification

- Results: 40 PHP tests with 208 assertions, 7 SPA tests, production build, and both headless browser scripts passed.
- PHP integration: `docker exec ogami-api php vendor/bin/phpunit -c phpunit.audit.pass1.w1.xml tests/Feature/Inventory --filter='ItemCategoryClassificationTest|ItemUomStockIntegrityTest|ItemResourceEndpointTest|BomInventoryReadinessTest'` uses the isolated test database.
- SPA: from `spa`, `npx vitest run src/pages/inventory/items/form.test.tsx src/components/ui/Select.test.tsx` and `npm run build`.
- Headless role-based browser: from `spa`, `node e2e-real/item-classification.cjs` and `node e2e-real/item-bom-forms.cjs`. Both use the audit environment at `http://localhost:18080`, with warehouse, planner, and administrator accounts. Fixture records are tagged; production records are not modified by these scripts.
- Screenshots: `.impeccable/review/item-bom-forms/item-{desktop,mobile}.png` and `category-{desktop,mobile}.png`.

Migration rollback removes only the category mapping column. Deploy the previous application before rollback. Item classifications and transaction history remain intact. A before/after checksum verified all production item IDs, category IDs, and item types were preserved; `SO-202610-0027` remained `6272.00`.
