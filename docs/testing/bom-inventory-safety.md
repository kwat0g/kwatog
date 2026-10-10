# BOM and Inventory safety implementation

Implemented the user-approved nine findings from `bom-inventory-audit.md` on 2026-10-04. Existing code-based product/item mapping is retained and validated. No new dependencies or actual-profit accounting workflow was introduced.

## Resulting behavior

| Finding | Implemented behavior |
| --- | --- |
| Missing finished-good identities | Idempotent setup creates missing inventory counterparts for active BOM products using their existing name, base unit and standard cost. Conflicting existing mappings cause a visible review failure. No stock is manufactured by setup. Products and BOM show readiness. |
| Zero-value new production receipts | New WOs freeze positive product standard cost as their inventory valuation basis. Each output persists its selected receipt cost. Missing/zero manufacturing cost creates the existing durable recovery handoff instead of posting zero value. Repair receipts preserve the source lot value. |
| Stale conversion costs | Conversion edits/deletes and item standard-cost edits invalidate active dependent BOMs and ancestors in the write transaction. Existing scoped MRP replans run afterwards. Same-second nested changes are covered. |
| Incomplete cost estimate | Costing warns about missing/empty routing, missing times and applicable zero rates. BOM shows Not costed, Materials only, Incomplete estimate or Manufacturing estimate. No status claims financial review. |
| Broken master-data links | Linked product/item codes and base units are protected. Active BOM component and output-item retirement is blocked with dependency context. Manufactured components and production receipts require correct active finished-good type and equal units. |
| Stock availability mismatch | Inventory display/filter and MRP reuse per-bin usable quantity: physical quantity minus reservations minus outgoing-QC holds, clamped per bin. Quarantine, scrap and inactive locations/warehouses are excluded. Physical stock, QC holds and usability remain separate figures. |
| Publication race | BOM publication locks the stable Product before latest-version lookup and sorted component rows. Restoring active versions revalidates locked components. Existing database uniqueness remains. |
| Routing dedupe collision | Each routing change has a unique persisted outbox key, preserving same-second legitimate replans and worker replay deduplication. |
| Historical traceability | New WOs retain BOM ID/version and valuation timestamp. Historical BOM costs cannot be recosted; active costs remain refreshable. Existing posted receipts and commercial records are not rewritten. |

## Margin interpretation

Base selling price excludes VAT. Estimated contribution is price minus the current BOM estimate; margin divides contribution by price, while markup divides by cost. A material-only BOM explicitly shows **Material contribution estimate**, excluding labor/machine/overhead. Missing/zero prices or costs and incomplete routing suppress a full estimate. Product standard cost is displayed separately because it is the production valuation basis; the system does not silently substitute the cheapest cost or change selling prices.

New WO valuation is an estimate, not proof of total actual profitability. Legacy WOs retain no invented historical BOM reference. Their first new manufacturing receipt freezes the current positive product cost if no snapshot exists. Existing zero-valued historical stock requires a separate accounting reconciliation; this change does not rewrite it.

## Validation

Isolated PostgreSQL test databases were used; no RefreshDatabase test ran against live data. Relevant module checks passed:

- Costing, nested BOMs, subassembly production, routing and UOM lifecycle: 93 tests / 325 assertions.
- Inventory readiness, MRP netting and reservation invariants: 79 tests / 326 assertions; expanded readiness tests separately cover same-second scoped outbox changes.
- Production receipt, BOM snapshot, pricing and inline QC regression: 61 tests / 266 assertions.
- Frontend customer ordering, output and rework regression: 19 tests.

Counts overlap and must not be added as unique test totals. Fresh final command logs are in `/tmp/bom-*.log` for this session. The final snapshot/receipt suite passed 15 tests / 77 assertions; the final margin suite passed 3 / 16. The frontend production build passed and `git diff --check` was clean.

The headless browser script `spa/e2e-real/bom-inventory-safety.cjs` uses Admin, PPC, Production, Warehouse, QC and Customer accounts on the audit host only. It creates isolated tagged master records through role APIs, verifies guarded identities and permission denials, inspects desktop/mobile BOM detail, checks a WO's frozen 20.00 cost despite a later product-cost edit, and checks 100 pieces remain unusable until independent outgoing-QC review. Upstream material issue and in-process QC are explicit fixture starting states, covered separately by integration tests; this is not a full SO-to-cash browser run. The final six-role headless run passed: frozen receipt cost 20.0000, receipt value 2000.00, pending-QC usable quantity 0, approved usable quantity 100, zero browser/API 500 errors, and no mobile page overflow. Screenshots are in `.impeccable/review/bom-inventory/`. Browser fixtures intentionally remain on the audit database for review.

The additive snapshot migration was applied to live and audit. Live setup filled all eight core missing finished-good identities without stock creation. Active BOM estimates were recalculated to populate previously missing snapshots and completeness warnings. A before/after comparison of every prior product, agreement, SO/line, stock level/movement, WO and output field found no historical changes. Active BOM cost snapshots are deliberately current estimates and were updated.

## Practical limits

QC-held and excluded-zone totals may overlap; they are not an additive subtraction equation. Inventory stock-status filtering scans matching candidates to evaluate ledger holds; move to a materialized projection only if measured catalog scale warrants it. Existing stock-card endpoints remain physical ledger views. No automatic price floor derivation or actual-profit claim was added.
