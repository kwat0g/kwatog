const { chromium, expect } = require('@playwright/test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
  const base = 'http://localhost:18080';
  const browser = await chromium.launch({ headless: true });
  const errors = [];
  async function session(email, portal = false) {
    const context = await browser.newContext({
      baseURL: base,
      viewport: { width: 1440, height: 1000 },
    });
    await context.request.get('/sanctum/csrf-cookie', {
      headers: { Origin: base, Referer: base + '/' },
    });
    async function api(method, route, data) {
      const cookie = (await context.cookies()).find((c) => c.name === 'XSRF-TOKEN');
      return context.request.fetch('/api/v1' + route, {
        method,
        data,
        headers: {
          Origin: base,
          Referer: base + '/',
          'X-XSRF-TOKEN': decodeURIComponent(cookie.value),
        },
      });
    }
    const login = await api('POST', portal ? '/b2b/customer/login' : '/auth/sign-in', {
      email,
      password: 'password',
    });
    assert(login.ok(), await login.text());
    const page = await context.newPage();
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('response', (r) => {
      if (r.url().includes('/api/') && r.status() >= 500) errors.push(r.status() + ' ' + r.url());
    });
    return { page, api };
  }
  async function success(actor, method, path, data) {
    const r = await actor.api(method, path, data);
    assert(r.ok(), await r.text());
    return (await r.json()).data;
  }
  try {
    const admin = await session('admin@ogami.test');
    const sales = await session('crm@ogami.test');
    const finance = await session('finance@ogami.test');
    const planner = await session('ppc@ogami.test');
    const warehouse = await session('warehouse@ogami.test');
    const buyer = await session('portal@cust.test', true);
    const tag = String(Date.now()).slice(-8);
    const product = await success(admin, 'POST', '/crm/products', {
      part_number: 'PRICE-' + tag,
      name: 'Price negotiation ' + tag,
      unit_of_measure: 'PCS',
      standard_cost: '10.00',
      base_selling_price: '50.00',
      minimum_selling_price: '15.00',
      portal_visibility: 'standard',
      is_active: true,
    });
    await planner.page.goto('/crm/products/' + product.id);
    await planner.page
      .getByRole('button', { name: 'Propose financial changes', exact: true })
      .click();
    await planner.page.getByLabel('Proposed standard cost').fill('11');
    await planner.page.getByLabel('Proposed minimum price').fill('16');
    await planner.page.getByLabel('Financial change reason').fill('Updated cost and floor');
    await planner.page
      .getByRole('button', { name: 'Submit for Finance approval', exact: true })
      .click();
    await expect(
      planner.page.getByText('Awaiting Finance approval', { exact: true }),
    ).toBeVisible();
    assert.equal(
      (await success(admin, 'GET', '/crm/products/' + product.id)).standard_cost,
      '10.00',
    );
    assert.equal(
      (
        await planner.api('PUT', '/crm/products/' + product.id, { minimum_selling_price: '1.00' })
      ).status(),
      403,
    );
    await finance.page.goto('/crm/products/' + product.id);
    await finance.page
      .getByLabel('Financial decision reason')
      .fill('Finance reviewed current cost');
    await finance.page
      .getByRole('button', { name: 'Approve financial changes', exact: true })
      .click();
    await expect(finance.page.getByText('Awaiting Finance approval', { exact: true })).toHaveCount(
      0,
    );
    assert.equal(
      (await success(admin, 'GET', '/crm/products/' + product.id)).standard_cost,
      '11.00',
    );
    await buyer.page.goto('/portal/customer/pricing');
    await buyer.page.getByRole('button', { name: 'Request pricing', exact: true }).click();
    await buyer.page.locator('select[name="product_id"]').selectOption(product.id);
    await buyer.page.getByLabel('Quantity per order').fill('100');
    await buyer.page.getByLabel('Requested unit price (optional)').fill('30');
    await buyer.page
      .getByLabel('Reason / planned order volume')
      .fill('Repeat monthly volume orders');
    await buyer.page.getByRole('button', { name: 'Submit request', exact: true }).click();
    await expect(
      buyer.page.getByRole('row').filter({ hasText: product.part_number }),
    ).toBeVisible();
    const row = (await success(buyer, 'GET', '/b2b/customer/price-requests')).find(
      (r) => r.product.id === product.id,
    );
    assert(row);
    await sales.page.goto('/crm/price-agreements/requests/' + row.id + '/offer');
    await expect(sales.page.getByRole('heading', { name: 'Propose pricing terms' })).toBeVisible();
    await sales.page.getByLabel('Proposal reason').fill('Discount for all quantities');
    await sales.page.getByLabel('Agreed price').fill('30');
    const date = new Date().toISOString().slice(0, 10);
    const end = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);
    await sales.page.locator('input[name="effective_from"]').fill(date);
    await sales.page.locator('input[name="effective_to"]').fill(end);
    await sales.page
      .getByRole('button', { name: 'Submit for Finance review', exact: true })
      .click();
    await expect(sales.page).toHaveURL(/\/crm\/price-agreements$/);
    assert.equal(
      (
        await warehouse.api('POST', '/crm/price-requests/' + row.id + '/approve', {
          notes: 'Unauthorized',
        })
      ).status(),
      403,
    );
    await finance.page.goto('/crm/price-agreements/requests');
    await finance.page
      .getByRole('row')
      .filter({ hasText: product.part_number })
      .getByRole('button', { name: 'Review', exact: true })
      .click();
    await expect(finance.page.getByText(/Estimated unit cost:.*11\.00/)).toBeVisible();
    await success(sales, 'PUT', '/crm/price-requests/' + row.id + '/offer', {
      price: '31.00',
      pricing_method: 'flat',
      tiers: null,
      effective_from: date,
      effective_to: end,
      notes: 'Revised offer after Finance opened review',
    });
    await finance.page.getByLabel('Review notes').fill('Review old displayed version');
    await finance.page.getByRole('button', { name: 'Approve for customer review' }).click();
    await expect(
      finance.page.getByText('Proposal version 2 · VAT excluded', { exact: true }),
    ).toBeVisible();
    assert.equal(
      (await success(sales, 'GET', '/crm/price-requests/' + row.id)).status,
      'pending_finance',
    );
    await finance.page.getByLabel('Review notes').fill('Cost and revised margin reviewed');
    await finance.page.getByRole('button', { name: 'Approve for customer review' }).click();
    await expect(
      finance.page
        .getByRole('row')
        .filter({ hasText: product.part_number })
        .getByText('offered', { exact: true }),
    ).toBeVisible();
    await buyer.page.reload();
    await buyer.page
      .getByRole('row')
      .filter({ hasText: product.part_number })
      .getByRole('button', { name: 'Accept terms', exact: true })
      .click();
    await expect(
      buyer.page
        .getByRole('row')
        .filter({ hasText: product.part_number })
        .getByText('accepted', { exact: true }),
    ).toBeVisible();
    const agreement = (
      await success(sales, 'GET', '/crm/price-agreements?search=' + product.part_number)
    )[0];
    assert.equal(agreement.price, '31.00');
    assert.equal(
      (
        await admin.api('PUT', '/crm/price-agreements/' + agreement.id, { price: '35.00' })
      ).status(),
      422,
    );
    await finance.page.goto('/crm/price-agreements/cost-reviews');
    await expect(
      finance.page.getByRole('heading', { name: 'Supplier cost reviews', exact: true }),
    ).toBeVisible();
    const seed = `$source = App\\Modules\\Inventory\\Models\\GrnItem::firstOrFail(); $item = App\\Modules\\Inventory\\Models\\Item::factory()->create(['code'=>'COST-${tag}','standard_cost'=>'10.0000']); $grn = App\\Modules\\Inventory\\Models\\GoodsReceiptNote::factory()->create(['status'=>'accepted','accepted_at'=>now()]); $line = $grn->items()->create(['purchase_order_item_id'=>$source->purchase_order_item_id,'location_id'=>$source->location_id,'item_id'=>$item->id,'quantity_received'=>'5','quantity_accepted'=>'5','unit_cost'=>'12.0000']); app(App\\Modules\\CRM\\Services\\PricingReviewService::class)->receiptCostChanged(new App\\Modules\\Inventory\\Events\\GoodsReceiptNoteAccepted($grn));`;
    require('node:child_process').execFileSync(
      'docker',
      ['exec', 'ogami-audit-20261002-api', 'php', 'artisan', 'tinker', '--execute=' + seed],
      { encoding: 'utf8' },
    );
    const cost = (await success(finance, 'GET', '/inventory/cost-reviews')).find(
      (r) => r.item.code === 'COST-' + tag,
    );
    assert(cost);
    assert.equal(
      (
        await warehouse.api('POST', '/inventory/cost-reviews/' + cost.id + '/decide', {
          decision: 'approve',
          notes: 'Unauthorized',
        })
      ).status(),
      403,
    );
    await finance.page.reload();
    await finance.page
      .getByRole('row')
      .filter({ hasText: cost.item.code })
      .getByRole('button', { name: 'Review', exact: true })
      .click();
    await finance.page.getByLabel('Approval or rejection reason').fill('Reviewed received cost');
    await finance.page.getByRole('button', { name: 'Approve standard cost', exact: true }).click();
    await expect(
      finance.page
        .getByRole('row')
        .filter({ hasText: cost.item.code })
        .getByText('approved', { exact: true }),
    ).toBeVisible();
    assert.equal(
      (await success(finance, 'GET', '/inventory/cost-reviews')).find((r) => r.id === cost.id)
        .current_cost,
      '12.0000',
    );
    const renewal = `$source=App\\Modules\\Inventory\\Models\\GrnItem::firstOrFail();$item=App\\Modules\\Inventory\\Models\\Item::factory()->create(['code'=>'RENEW-${tag}','standard_cost'=>'10.0000']);$grn=App\\Modules\\Inventory\\Models\\GoodsReceiptNote::factory()->create(['status'=>'accepted','accepted_at'=>now()]);$line=$grn->items()->create(['purchase_order_item_id'=>$source->purchase_order_item_id,'location_id'=>$source->location_id,'item_id'=>$item->id,'quantity_received'=>'5','quantity_accepted'=>'5','unit_cost'=>'12.0000']);app(App\\Modules\\Inventory\\Services\\ItemCostReviewService::class)->capture($line,'12.0000');`;
    require('node:child_process').execFileSync(
      'docker',
      ['exec', 'ogami-audit-20261002-api', 'php', 'artisan', 'tinker', '--execute=' + renewal],
      { encoding: 'utf8', stdio: 'pipe' },
    );
    await finance.page.reload();
    await finance.page
      .getByRole('row')
      .filter({ hasText: 'RENEW-' + tag })
      .getByRole('button', { name: 'Review', exact: true })
      .click();
    await finance.page.getByLabel('Approval or rejection reason').fill('Incorrect receipt cost');
    await finance.page.getByRole('button', { name: 'Reject', exact: true }).click();
    await expect(
      finance.page
        .getByRole('row')
        .filter({ hasText: 'RENEW-' + tag })
        .getByText('rejected', { exact: true }),
    ).toBeVisible();
    const correction = `$review=App\\Modules\\Inventory\\Models\\ItemCostReview::whereHas('item',fn($q)=>$q->where('code','RENEW-${tag}'))->firstOrFail();$review->receiptLine->update(['unit_cost'=>'14.0000']);`;
    require('node:child_process').execFileSync(
      'docker',
      ['exec', 'ogami-audit-20261002-api', 'php', 'artisan', 'tinker', '--execute=' + correction],
      { encoding: 'utf8', stdio: 'pipe' },
    );
    await planner.page.goto('/crm/price-agreements/cost-reviews');
    await planner.page
      .getByRole('row')
      .filter({ hasText: 'RENEW-' + tag })
      .getByRole('button', { name: 'Renew review', exact: true })
      .click();
    await planner.page
      .getByLabel('Corrected cost review reason')
      .fill('Supplier corrected unit cost');
    await planner.page
      .getByRole('button', { name: 'Submit corrected review', exact: true })
      .click();
    await expect(
      planner.page
        .getByRole('row')
        .filter({ hasText: 'RENEW-' + tag })
        .getByText('pending', { exact: true }),
    ).toBeVisible();
    const renewed = (await success(finance, 'GET', '/inventory/cost-reviews')).find(
      (r) => r.item.code === 'RENEW-' + tag,
    );
    assert.equal(renewed.version, 2);
    assert.equal(renewed.history.length, 1);
    assert.equal(renewed.proposed_cost, '14.0000');
    // Isolate frontend feature evidence without changing shared environment settings.
    await finance.page.route('**/api/v1/auth/me', async (route) => {
      const response = await route.fetch();
      const body = await response.json();
      body.data.features = body.data.features.filter((feature) => feature !== 'crm');
      await route.fulfill({ response, json: body });
    });
    await finance.page.goto('/crm/price-agreements/cost-reviews');
    await finance.page.reload();
    await expect(
      finance.page.getByRole('heading', { name: 'Supplier cost reviews', exact: true }),
    ).toBeVisible();
    fs.mkdirSync('../.impeccable/review/pricing-negotiation', { recursive: true });
    await buyer.page.screenshot({
      path: '../.impeccable/review/pricing-negotiation/customer-desktop.png',
      fullPage: true,
    });
    await buyer.page.setViewportSize({ width: 390, height: 844 });
    assert(await buyer.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await buyer.page.screenshot({
      path: '../.impeccable/review/pricing-negotiation/customer-mobile.png',
      fullPage: true,
    });
    assert.deepEqual(errors, []);
    console.log(
      JSON.stringify({
        roles: ['customer', 'sales', 'finance', 'warehouse', 'ppc', 'admin'],
        requested: true,
        finance_review: true,
        accepted: true,
        terms_locked: true,
        supplier_cost_approved: true,
        financial_proposal_approved: true,
        stale_approval_blocked: true,
        rejected_review_renewed: true,
        inventory_feature_guard: true,
        errors,
      }),
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
