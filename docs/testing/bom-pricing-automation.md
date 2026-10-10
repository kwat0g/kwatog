# BOM and pricing automation audit

Audited and implemented October 4, 2026. Scope: inventory standard costs, active and nested BOM estimates, routing changes, product selling prices, customer agreements, supplier receipt costs, and staff notifications. This is a focused connection audit, not an exhaustive ERP audit.

**Follow-up implemented:** [Pricing risk resolution](pricing-risk-resolution.md) adds customer acceptance, Finance cost review, approved receipt-cost updates, and generic worker/scheduler supervision. That document supersedes the open negotiation/runtime findings and pricing-only timer instructions below. Verification results below describe the initial audit snapshot.

## Implemented automation

| Trigger | Automatic result | Responsible staff |
| --- | --- | --- |
| Inventory component standard cost or UoM conversion changes | Invalidate and refresh every affected active BOM, including parent assemblies. Preserve historical BOM versions and production snapshots. | PPC and system administrator receive BOM refresh or failure notifications. |
| BOM publication, manual recalculation, or routing revision | Refresh current estimates and affected parents; review selling prices against the updated estimate. | PPC; Finance, Sales, Customer Service and administrator when pricing needs review. |
| Product base price, minimum price, or product standard cost changes | Review active and future unexpired agreements and every volume tier. Show warnings without rewriting terms or order prices. | PPC, Finance, Sales, Customer Service and administrator receive the price-change notice; commercial roles receive risk notices. |
| Agreement creation, update, archive, or restoration | Review current product pricing and remaining unexpired agreements. | Finance, Sales, Customer Service and administrator when risk exists. |
| Supplier goods receipt accepted or partially accepted | Compare accepted base-unit cost, including allocated landed cost, with the inventory item's standard cost. Notify on a difference; preserve approved standard cost. | Purchasing, Finance, Warehouse, PPC and administrator. |
| Daily at 07:00 Asia/Manila | Recover stale BOM estimates, check pricing risks, recover supplier alerts for receipts accepted since yesterday, and remind commercial staff about agreements ending within seven days and again with one day remaining. | Relevant staff above. |
| Every minute | Recover up to 100 pending pricing refreshes, including expired processing leases. Failed infrastructure calls remain eligible for retry and do not prevent other requests in that batch from running. | Service failure is visible in system logs. |

Notifications are in-app, respect notification preferences, exclude inactive users, and route staff without module permission to their notification inbox. Existing notification deduplication prevents repeat alerts for the same risk state or reminder window. No customer manufacturing costs are exposed by these additions.

The Price Agreements table now shows every quantity threshold, unit price, saved price below the first tier, current product base/minimum prices, and pricing warnings. Thresholds apply per product per order; the highest eligible tier applies. Table cells keep readable widths and scroll within the table on smaller screens.

## Findings and remaining risks

| Priority | Finding | Current treatment or smallest next improvement |
| --- | --- | --- |
| High | An agreement can become loss-making after material or routing costs rise. A configured price floor can itself be below cost. | Implemented tier-by-tier cost/floor warnings and staff alerts. Existing positive-price and configured-floor guards continue to block new quotes below the floor. Finance must approve a suitable floor; this change does not silently raise it. Being below estimated cost alone is a warning, not an additional pricing block. |
| High | Material-only or incomplete routing can understate manufacturing cost and inflate apparent margin. | Reuse existing BOM completeness warnings. Flag profitability for review when cost is stale or warnings exist. Compare against the higher of product standard cost and a fresh BOM estimate. Neither proves actual profitability. |
| High | Current Price Agreement editing changes commercial terms without a customer proposal/acceptance lifecycle. | Remains open. Introduce a customer discount request and a dated, accepted replacement agreement; preserve accepted terms until replacement becomes effective. Do not silently edit price or quantity commitments. This audit did not create that negotiation subsystem. |
| High | A tiered agreement's saved fallback price is separate from the current product base price. Increasing base price does not update the saved fallback. | Display both values and warn when they differ. Preserve existing contract behavior. Next policy decision: whether new agreements use a separately negotiated fallback or current base price below the minimum quantity. |
| High | The inspected live host had no generic queue worker or scheduler process, although those services exist in production Compose configuration. Unrelated queued workflows and scheduled jobs may remain idle. | Activated pricing-specific host timers and direct pricing-outbox recovery. Supplier-cost recovery calls only the pricing listener; it does not replay accounting. Generic worker/scheduler rollout remains separate and must account for its existing backlog. |
| Medium | Supplier PO price, received landed cost, inventory weighted average, and standard manufacturing estimate represent different costs. | Added accepted-receipt variance alerts with base-unit comparison. An approved standard-cost edit triggers BOM refresh. PO quotes and later supplier bill adjustments do not automatically change standard costs; add their review handoff if needed. |
| Medium | Zero costs, missing conversion factors, inactive components, or invalid nested BOMs can make automatic costing unsafe. | Existing costing guards reject invalid data; new failure notification identifies the problem. Savepoints roll back partial costing writes. Daily recovery retries after data correction. |
| Medium | Agreement expiry can switch future orders to base price or make them unavailable if no base exists. | Added two reminder windows; exclude archived agreements and inactive customers/products. Orders retain their saved prices. Pricing is evaluated using the order's requested delivery date. |
| Medium | Repeating a previously warned identical risk state does not generate a fresh warning after that state was once resolved. | Existing deduplication deliberately limits noise. Risks remain visible in the agreement table. Add an owned review status and resolution/reopen lifecycle if acknowledgement and escalation become necessary. |
| Medium | Estimated BOM cost depends on standard rates, waste allowance and setup batch size; actual scrap, overtime, freight changes and actual consumption may differ. | Preserve the estimated label and historical production cost snapshots. Next improvement: a production standard-versus-actual variance review using existing material/output records. |
| Low | Fixed canonical staff roles determine notification recipients; there is no product-specific costing owner. | Reuse existing roles and permission-aware links. Add an assigned owner only when multiple PPC/commercial teams need narrower routing. |

## Additional automation recommendations

1. **Priority: agreement revision and acceptance.** Customer requests discount; Sales proposes quantity tiers, price and dates; Finance reviews unsafe margins; customer accepts; accepted replacement becomes effective on its agreed date. Automate reminders and status changes, not customer consent.
2. **Priority: approved cost review.** Supplier cost variance opens a review for Finance/PPC. Approval updates inventory standard cost through the existing service, which already refreshes affected BOMs. Avoid treating each supplier receipt as a new approved standard.
3. **Production variance review.** Notify PPC/Finance when actual material usage or scrap exceeds the existing allowance. Reuse production actuals before creating a new costing subsystem.
4. **Runtime health.** Surface failed pricing timer runs and pending pricing refresh age in the existing operations health screen. Review the generic scheduler/queue backlog before enabling all ERP automations.

## Operations and reversibility

No new database migration is required. Durable requests use the existing outbox and notifications use the existing notification service. Costing updates active estimates only; agreement prices, saved order prices and historical production costs remain preserved.

The Laravel schedule includes both pricing tasks for normal deployments. On the current host, systemd units are installed because the generic scheduler is absent. Copies live in `docker/systemd/ogami-pricing-*.service` and `docker/systemd/ogami-pricing-*.timer`; they intentionally name the current host user, workspace and `ogami-api` container. Adjust those three values for another host. Use either the normal Laravel scheduler or these host timers; outbox leases and alert deduplication protect duplicate dispatch, but duplicate catalog scans waste work.

Install/reinstall on this host:

```bash
sudo install -m 0644 docker/systemd/ogami-pricing-* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ogami-pricing-refresh.timer ogami-pricing-review.timer
```

Inspect:

```bash
systemctl list-timers --all ogami-pricing-refresh.timer ogami-pricing-review.timer
systemctl status ogami-pricing-refresh.service ogami-pricing-review.service
journalctl -u ogami-pricing-refresh.service -u ogami-pricing-review.service --since today
docker exec ogami-api php artisan pricing:review-agreements --dispatch-only
```

Disable these timers to roll back host automation without removing data:

```bash
sudo systemctl disable --now ogami-pricing-refresh.timer ogami-pricing-review.timer
```

Disabling timers does not stop a currently running oneshot service and does not disable Laravel schedule entries. Pending outbox requests remain available for later recovery. Keep the event codec/listener available until pending pricing events are drained before reverting application code.

## Verification

Automated checks use isolated PostgreSQL databases, not live business data. `spa/e2e-real/bom-pricing-automation.cjs` uses the isolated audit site at `http://localhost:18080` with admin, Warehouse, PPC, Sales and Customer Service accounts. It checks a real standard-cost update, automatic BOM refresh, unchanged discount tiers, visible quantity rules, PPC/Sales notifications, denied Sales recalculation, and desktop/mobile overflow. Feature tests cover nested BOMs, preserved historical costs, rollback, receipt variances, reminder deduplication, inactive/archived exclusion, costing refusal/recovery, pricing read paths, and failed-request isolation/retry.

Run backend proof using the repository's isolated PHPUnit configuration:

```bash
docker exec ogami-api php vendor/bin/phpunit -c phpunit.pricing.20261004.xml tests/Feature --filter='AutomaticBomPricingReviewTest|BomCostingTest|BomInventoryCostSafetyTest|BomProductionSnapshotTest|BomInventoryReadinessTest|CustomerStandardPricingTest|CustomerProductPricingTest|OutboxTest'
docker exec ogami-api php vendor/bin/phpunit -c phpunit.pricing.20261004.xml tests/Unit/NotificationCatalogTest.php
```

From `spa`:

```bash
node e2e-real/bom-pricing-automation.cjs
npx vitest run src/lib/notificationMeta.test.ts
npm run build
```

The dedicated `ogami_test_pricing_20261004` database avoids concurrent suites resetting shared audit databases. An initial combined run against a shared database collided with another suite; those missing-table errors were not accepted as validation.

Final results: 75 focused backend regression tests / 311 assertions passed, plus 7 notification-catalog tests / 50 assertions. After adding the last stale-status safeguard, all 9 automation tests / 41 assertions passed again. All 12 notification metadata tests passed; TypeScript/Vite build and targeted ESLint checks passed. Headless browser checks passed with five staff roles, no captured browser errors, and bounded desktop/mobile table scrolling. Both pricing timers are enabled and active; the minute refresh service exited successfully. Live read-only checks confirmed SO-202610-0027 remains 6272.00 and WB-001 base price remains the user's 200.00.
