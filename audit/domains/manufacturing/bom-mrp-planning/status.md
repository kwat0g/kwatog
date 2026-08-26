id: M049
domain: manufacturing
module: bom-mrp-planning
tier: 3
roles: system_admin, production_manager, ppc_head
depends_on: sales-orders, inventory-master, production-routings, purchase-requests
surface: L
status: 🔁 Needs Re-audit — all 14 findings execute correctly & MRP suite green (67/67), but: zero regression tests pin any of them (9 gaps open), M02 recost path untested, cancellation race confirmed-unfixed, cost-snapshot mutability policy still open, SPA typecheck not run
last_session: 2026-08-26
