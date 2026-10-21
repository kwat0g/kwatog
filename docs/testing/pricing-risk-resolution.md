# Pricing risk resolution

Implemented October 4, 2026. This extends the [BOM and pricing automation audit](bom-pricing-automation.md).

Cross-module hardening is documented in [pricing-cross-module-hardening.md](pricing-cross-module-hardening.md).

## Customer pricing

Customers request a discount from **Pricing agreements** in their portal. Quantity is a whole number per product per order, not a lifetime volume commitment. Standard products remain orderable at their base price without an agreement.

Sales or Customer Service propose flat pricing or quantity tiers, dates, and a reason. Submitting a proposal does not change prices. Finance sees the estimated cost, base price and minimum price before approving. The proposal creator cannot approve their own proposal. Approval requires positive complete cost information, a configured minimum price at least equal to the reviewed cost, and every offered price strictly above that cost.

Finance approval makes the offer available to its customer. Customer acceptance is required. A changed proposal version, cost, price limit or agreement state prevents stale acceptance. Customer rejection keeps existing terms. Repeated acceptance creates only one agreement. Another customer cannot accept or retrieve the request through their portal.

Accepted and unexpired agreements cannot be silently edited or archived. A replacement requires review and acceptance and begins on its agreed date. The earlier agreement keeps its prices and ends the day before replacement; the negotiation history records its original terms. Existing sales orders retain their saved line prices.

## Supplier costs

An accepted receipt cost variance opens one review per receipt line. Received cost includes allocated landed cost in the inventory base unit. Finance approves or rejects with a reason. Approval updates standard cost through the existing inventory service, refreshing affected active and parent BOMs and notifying costing/pricing staff.

Warehouse cannot approve reviews or directly change an existing item's standard cost. Approval refuses inactive items, ineligible receipts, zero accepted quantity, nonpositive costs, changed standard costs, and changed receipt costs. Repeated approval is safe. Receipt costs are review suggestions; they do not automatically rewrite standard cost or inventory valuation. A rejected review can be renewed explicitly after its receipt cost or standard-cost basis changes. Renewal preserves the earlier rejection and reason, increments its version, and requires Finance approval again. Archived-source reviews can still be rejected.

## Runtime

The generic queue worker and Laravel scheduler now run under systemd. The worker finishes its current job on graceful restart. The scheduler uses the existing fail-fast command and execution ledger. Historical failed jobs were not replayed. The previous pricing-only timers are disabled to avoid duplicate scans. Operations Health shows scheduler health, pending/failed pricing requests, open negotiations and pending cost reviews.

Current-host units deliberately name `ec2-user`, `/home/ec2-user/kwatog`, and `ogami-api`. Adjust these for another deployment. Run one scheduler and one worker supervision method; do not also start duplicate Compose services.

```bash
sudo install -m 0644 docker/systemd/ogami-scheduler.service docker/systemd/ogami-scheduler.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ogami-scheduler.timer
sudo systemctl disable --now ogami-pricing-refresh.timer ogami-pricing-review.timer
docker exec ogami-api php artisan scheduler:health
systemctl status ogami-scheduler.timer
```

Disable the new timer and worker to stop host automation without deleting business records. A running scheduler tick can finish. Application rollback requires preserving the new negotiation and cost-review records; do not roll back their database migration on populated production data. Migration forward/backward permission preservation is tested on an isolated database.

## Verification

Feature checks use the dedicated `ogami_test_pricing_20261004` database, separate from live data and other audit suites:

```bash
docker exec ogami-api php vendor/bin/phpunit -c phpunit.pricing.20261004.xml tests/Feature --filter='PriceNegotiationLifecycleTest|PricingNegotiationMigrationTest|ItemCostReviewTest|AutomaticBomPricingReviewTest|CustomerStandardPricingTest|CustomerProductPricingTest|RolloutHealthTest'
```

From `spa`:

```bash
node e2e-real/pricing-negotiation.cjs
node e2e-real/bom-pricing-automation.cjs
npm run build
```

The headless negotiation script uses customer, Sales, Finance, Warehouse and Admin accounts at the isolated audit site. It exercises request submission, a Sales proposal, Finance cost visibility and approval, customer acceptance, locked terms, denied unauthorized approvals, supplier cost approval, and mobile overflow. Supplier fixtures are created only in that audit database.

Verification passed: 45 focused backend tests with 198 assertions, both role-based headless scripts with no captured browser errors, TypeScript/Vite build, targeted ESLint, and systemd unit validation. Scheduler health reports a successful tick; the live queue has no pending, delayed or reserved jobs. Live read-only checks preserve `SO-202610-0027` at `6272.00` and `WB-001` base selling price at `200.00`.

## Deliberate limits

BOM margin remains an estimate, not actual net profit. Finance still chooses a suitable floor and commercial margin policy. Existing agreements that become risky receive warnings; their prices are not automatically raised. No automatic customer consent, cost-derived selling prices, new approval engine, product-specific owner assignment, or actual-production variance subsystem was introduced.
