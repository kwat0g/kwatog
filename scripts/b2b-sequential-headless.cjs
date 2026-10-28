// Run the current B2B journey against disposable master data. Every checkpoint
// throws on failure; the next business stage never runs after a failed gate.
// O2CUI_RUN_ID=<unique> node scripts/b2b-sequential-headless.cjs
const fs = require('node:fs');
const path = require('node:path');
const h = require('./o2c-ui-chain.cjs');
// A failed click can close the page before its paired response waiter settles.
// The checkpoint catch records the original failure; keep teardown reliable.
process.on('unhandledRejection', error => {
  if (!/Target page, context or browser has been closed/.test(String(error))) throw error;
});
h.env.QC_CHECKLIST_CAPTURE_ENABLED = 'true';
let fixture;
const prior = process.env.B2B_RESUME === '1' ? JSON.parse(fs.readFileSync(path.join(h.OUT, 'sequential-report.json'), 'utf8')) : {};
const state = prior.state ?? {};
const checkpoints = prior.checkpoints?.filter(checkpoint => checkpoint.status === 'passed') ?? [];
if (process.env.B2B_RESUME === '1') {
  for (const field of ['checks', 'api_errors', 'http_errors', 'console_errors', 'page_errors', 'expected_api_errors', 'expected_console_errors']) {
    if (Array.isArray(prior[field])) h.report[field] = prior[field];
  }
}
const persist = () => fs.writeFileSync(path.join(h.OUT, 'sequential-report.json'), JSON.stringify({
  ...h.report, checkpoints, state,
}, null, 2));

async function stage(name, action) {
  const existing = checkpoints.find(checkpoint => checkpoint.name === name);
  if (existing?.status === 'passed') {
    console.log(`CHECKPOINT ALREADY PASSED: ${name}`);
    return;
  }
  const checkpoint = existing ?? { name };
  Object.assign(checkpoint, { status: 'running', started_at: new Date().toISOString() });
  if (!existing) checkpoints.push(checkpoint);
  persist();
  await action();
  h.assertCleanStep(name);
  h.check(`${name}: no browser exceptions or server failures`, h.report.page_errors.length === 0 && h.report.http_errors.length === 0, h.report.page_errors);
  Object.assign(checkpoint, { status: 'passed', finished_at: new Date().toISOString() });
  persist();
  console.log(`CHECKPOINT PASSED: ${name}`);
}

async function actor(key, email, portal = false) {
  h.who[key] ??= await h.login(email, portal === 'supplier' ? '/b2b/supplier/me' : portal ? '/b2b/customer/me' : '/auth/user');
  return h.who[key];
}
const read = async (page, route) => {
  const response = await h.api(page, 'GET', route);
  h.check(`Read ${route}`, response.status === 200, response.status);
  return response.body.data;
};

async function salesOrder() {
  const sales = await actor('sales', 'crm@ogami.test');
  const customer = await actor('customer', fixture.customer_email, true);
  await h.open(customer, '/portal/customer/orders/new');
  h.check('Requested delivery date starts empty', await customer.getByLabel('Requested delivery date', { exact: true }).inputValue() === '');
  h.check('Order date is fixed', await customer.getByLabel('Order date', { exact: true }).getAttribute('readonly') !== null);
  const paymentMethod = customer.getByRole('combobox', { name: /Payment method/ });
  const portalMe = await h.api(customer, 'GET', '/b2b/customer/me');
  h.check('Customer portal returns saved payment methods', portalMe.status === 200 && portalMe.body?.data?.customer_terms?.allowed_payment_methods?.join(',') === 'cash,check,bank_transfer', portalMe.body?.data?.customer_terms);
  await paymentMethod.locator('option[value="cash"]').waitFor({ state: 'attached', timeout: 10_000 });
  const availableMethods = await paymentMethod.locator('option').evaluateAll(options => options.map(option => option.value).filter(Boolean));
  h.check('Portal offers only customer-approved payment methods', availableMethods.join(',') === 'cash,check,bank_transfer', availableMethods);
  await paymentMethod.selectOption('bank_transfer');
  state.delivery_date = new Date(Date.now() + 8 * 3600e3 + 14 * 864e5).toISOString().slice(0, 10);
  await customer.getByLabel('Requested delivery date', { exact: true }).fill(state.delivery_date);
  await h.pageButton(customer, 'Add products');
  await customer.getByRole('button', { name: 'Add O2C wiper bushing to order', exact: true }).click();
  await customer.getByLabel(`Quantity for ${fixture.product_code}`, { exact: true }).fill('100');
  await customer.getByTestId('order-total').filter({ hasText: '1,400.00' }).waitFor();
  h.check('Customer-paid freight is quoted separately from product total', await customer.getByText('Freight is excluded. Sales must confirm any customer-paid charge separately.', { exact: true }).isVisible());
  h.check('Customer sees VAT included', await customer.getByText('VAT included', { exact: true }).isVisible());
  await customer.getByLabel('Order note (optional)').fill(`Sequential B2B acceptance ${h.RUN}: 100 pieces, one defect recovery.`);
  const submitted = customer.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/api/v1/b2b/customer/orders');
  await h.pageButton(customer, 'Submit order for review');
  const response = await submitted;
  const payload = await response.json();
  h.check('Customer submits order', response.status() === 201, payload);
  state.so = payload.data.id;
  state.so_number = payload.data.so_number;
  const order = await read(customer, `/b2b/customer/orders/${state.so}`);
  h.check('Order awaits sales confirmation', order.status === 'awaiting_confirmation', order.status);
  h.check('Customer can edit or cancel before confirmation', order.capabilities.can_edit && order.capabilities.can_cancel);
  h.check('No downstream records before sales confirms', h.sql('select count(*) from work_orders') === '0');

  await h.open(sales, `/crm/sales-orders/${state.so}`);
  const before = await read(sales, `/crm/sales-orders/${state.so}`);
  h.check('Sales cannot edit customer order', !before.is_editable && !before.capabilities.can_edit);
  h.check('Requested date survives portal handoff', before.requested_delivery_dates[0] === state.delivery_date, before.requested_delivery_dates);
  h.check('Order snapshots bank transfer choice and active customer terms', before.payment_method === 'bank_transfer' && before.customer_terms_snapshot?.agreement_status === 'in_effect' && before.customer_terms_snapshot?.shipping_payer === 'customer', before.customer_terms_snapshot);
  const orderTerms = await read(customer, `/b2b/customer/orders/${state.so}/agreements`);
  const warranty = orderTerms.warranty_terms?.find(item => item.terms)?.terms;
  h.check('Order freezes warranty duration, report window, basis, issue and remedy', warranty?.warranty_duration_days === 30 && warranty?.warranty_report_window_days === 7 && warranty?.warranty_start_basis === 'delivery' && warranty?.warranty_covered_issues?.includes('manufacturing_defect') && warranty?.warranty_remedies?.includes('replace'), warranty);
  await h.open(customer, `/portal/customer/orders/${state.so}`);
  h.check('Customer portal displays payment, freight and warranty terms', await customer.getByText('Customer terms captured for this order', { exact: true }).isVisible() && await customer.getByText('Bank transfer', { exact: true }).isVisible() && await customer.getByText(/no freight amount is included here/i).isVisible() && await customer.getByText('Warranty terms by product', { exact: true }).isVisible());
  h.check('Order detail has no ad hoc agreement text area', await customer.locator('textarea').count() === 0);
  await h.pageButton(sales, 'Confirm order');
  const confirmed = sales.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/sales-orders/${state.so}/confirm`));
  await h.modalButton(sales, 'Confirm & Start Chain');
  const confirmation = await confirmed;
  const result = await confirmation.json();
  h.check('Sales confirmation succeeds', confirmation.status() === 200, result);
  h.check('Planning completes without hidden errors', result.chain_result?.planning_status === 'completed' && !result.chain_result.planning_error, result.chain_result);
  h.check('Automatic proposal is accurately reported', result.chain_result.auto_scheduled === 1 && result.chain_result.needs_manual === 0, result.chain_result);
  h.check('Exactly one WO for one product', result.chain_result.work_orders_created === 1);
  state.wo = result.chain_result.work_orders[0].id;
  state.wo_number = result.chain_result.work_orders[0].wo_number;
  const after = await read(sales, `/crm/sales-orders/${state.so}`);
  h.check('SO totals reconcile', after.subtotal === '1250.00' && after.vat_amount === '150.00' && after.total_amount === '1400.00', after.total_amount);
  h.check('MRP exists and has no shortages', !!after.mrp_plan && after.mrp_plan.shortages_found === 0, after.mrp_plan);
  const ppc = await actor('ppc', 'ppc@ogami.test');
  const wo = await read(ppc, `/production/work-orders/${state.wo}`);
  h.check('WO remains Planned until crew review', wo.status === 'planned', wo.status);
  h.check('Production slot proposed', h.sql('select count(*) from production_schedules where reserves_capacity') === '1');
  h.check('No material issue before WO confirmation', h.sql("select count(*) from stock_movements where movement_type='material_issue'") === '0');
  const portalAfter = await read(customer, `/b2b/customer/orders/${state.so}`);
  h.check('Customer edits lock after confirmation; cancellation remains available for review', !portalAfter.capabilities.can_edit && portalAfter.capabilities.can_cancel, portalAfter.capabilities);
  await h.shot(sales, `/crm/sales-orders/${state.so}`, '01-sales-confirmed');
}

async function customerTerms() {
  const sales = await actor('sales', 'crm@ogami.test');
  await h.open(sales, `/crm/customers/${fixture.customer}/edit`);
  const start = new Date(Date.now() + 8 * 3600e3 - 7 * 864e5).toISOString().slice(0, 10);
  const end = new Date(Date.now() + 8 * 3600e3 + 365 * 864e5).toISOString().slice(0, 10);
  await sales.getByLabel('Agreement starts', { exact: true }).fill(start);
  await sales.getByLabel('Agreement ends', { exact: true }).fill(end);
  await sales.getByLabel('Shipping paid by', { exact: true }).selectOption('customer');
  await sales.getByLabel('Warranty duration (days)', { exact: true }).fill('30');
  await sales.getByLabel('Report within (days)', { exact: true }).fill('7');
  await sales.getByLabel('Warranty starts from', { exact: true }).selectOption('delivery');
  await sales.getByLabel('Manufacturing defect', { exact: true }).check();
  await sales.getByLabel('Shipping damage', { exact: true }).check();
  await sales.getByLabel('Replace', { exact: true }).check();
  await sales.getByLabel('Repair', { exact: true }).check();
  await sales.getByLabel('Cash', { exact: true }).check();
  await sales.getByLabel('Check', { exact: true }).check();
  await sales.getByLabel('Bank transfer', { exact: true }).check();
  const saved = sales.waitForResponse(r => r.request().method() === 'PUT' && new URL(r.url()).pathname === `/api/v1/crm/customers/${fixture.customer}`);
  await h.pageButton(sales, 'Save changes');
  const response = await saved;
  h.check('CRM saves customer agreement and warranty defaults', response.status() === 200, await response.json());
  const customer = await read(sales, `/crm/customers/${fixture.customer}`);
  h.check('Saved agreement dates are effective and all supported methods are allowed', customer.agreement_starts_on === start && customer.agreement_ends_on === end && [...(customer.allowed_payment_methods ?? [])].sort().join(',') === 'bank_transfer,cash,check', customer);
  h.check('Saved customer freight and warranty terms match CRM choices', customer.shipping_payer === 'customer' && customer.warranty_duration_days === 30 && customer.warranty_report_window_days === 7 && customer.warranty_start_basis === 'delivery' && [...(customer.warranty_covered_issues ?? [])].sort().join(',') === 'manufacturing_defect,shipping_damage' && [...(customer.warranty_remedies ?? [])].sort().join(',') === 'repair,replace', customer);
}

async function confirmation() {
  const ppc = await actor('ppc', 'ppc@ogami.test');
  await h.open(ppc, '/production/schedule');
  h.check('Schedule page cannot bypass crew confirmation', await ppc.getByRole('button', { name: /Confirm selected|Confirm schedule/ }).count() === 0);
  await h.open(ppc, `/production/work-orders/${state.wo}`);
  const before = await read(ppc, `/production/work-orders/${state.wo}`);
  if (before.status === 'planned') {
    await h.pageButton(ppc, 'Confirm');
    await ppc.getByRole('dialog').getByLabel('Number of workers').selectOption('2');
    const applied = ppc.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/work-orders/${state.wo}/assignment-apply`));
    await h.modalButton(ppc, 'Confirm work order');
    const response = await applied;
    h.check('PPC confirms suggested crew and equipment', response.status() === 200, await response.json());
  }
  const wo = await read(ppc, `/production/work-orders/${state.wo}`);
  const crew = await read(ppc, `/production/work-orders/${state.wo}/crew`);
  h.check('Confirmed WO owns machine and mold', wo.status === 'confirmed' && !!wo.machine && !!wo.mold, wo.status);
  h.check('Exactly two regular Production employees assigned', crew.current.members.length === 2 && crew.current.members.every(member => fixture.workers.some(worker => worker.id === member.id)), crew.current.members);
  h.check('One output recorder belongs to crew', crew.current.members.some(member => member.id === crew.current.recorder_employee_id));
  state.recorder = fixture.workers.find(worker => worker.id === crew.current.recorder_employee_id);
  h.check('WO details show both assigned worker names', (await ppc.locator('body').innerText()).includes(fixture.workers[0].name) && (await ppc.locator('body').innerText()).includes(fixture.workers[1].name));
  h.check('Confirmation reserves exact 2 kg BOM material', h.sql("select sum(quantity) from material_reservations where status='reserved'") === '2.000');
  h.check('Reservation has not consumed stock', h.sql("select sum(quantity) from stock_levels where item_id=(select id from items where code like 'RM-%')") === '500.000');
  h.check('Confirmed slot starts within the current planning window', Date.parse(crew.schedule.scheduled_start) >= Date.now() - 5 * 60e3, crew.schedule);
  state.original_schedule = crew.schedule;
  await h.shot(ppc, `/production/work-orders/${state.wo}`, '02-wo-confirmed');
}

async function startProduction() {
  const manager = await actor('manager', 'production@ogami.test');
  await h.open(manager, `/production/work-orders/${state.wo}`);
  let wo = await read(manager, `/production/work-orders/${state.wo}`);
  if (wo.status === 'confirmed') {
    if (await manager.getByRole('button', { name: 'Review production slot', exact: true }).count()) {
      await h.pageButton(manager, 'Review production slot');
      const start = new Date(Date.now() + 8 * 3600e3 - 60000).toISOString().slice(0, 16);
      const end = new Date(Date.now() + 8 * 3600e3 + 2 * 3600e3).toISOString().slice(0, 16);
      await manager.getByRole('dialog').getByLabel('Reviewed production start').fill(start);
      await manager.getByRole('dialog').getByLabel('Reviewed production end').fill(end);
      await manager.getByRole('dialog').getByLabel('Scheduling reason').fill('Authorized acceptance run within assigned workers current HR shift.');
      const reviewWait = manager.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/work-orders/${state.wo}/review-slot`));
      await h.modalButton(manager, 'Approve production slot');
      const reviewed = await reviewWait;
      h.check('Production management approves current slot', reviewed.status() === 200, await reviewed.json());
      await h.open(manager, `/production/work-orders/${state.wo}`);
    }
    const startWait = manager.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/work-orders/${state.wo}/start`));
    await h.pageButton(manager, 'Start');
    await h.modalButton(manager, 'Start production');
    const started = await startWait;
    h.check('WO starts inside authorized slot with present crew', started.status() === 200, await started.json());
  }
  wo = await read(manager, `/production/work-orders/${state.wo}`);
  h.check('Started WO has exact automatic material issue', wo.status === 'in_progress' && h.sql("select sum(quantity) from stock_movements where movement_type='material_issue'") === '2.000');
  h.check('Warehouse material quantity reconciles', h.sql("select sum(quantity) from stock_levels where item_id=(select id from items where code like 'RM-%')") === '498.000');
  if (wo.qc_workflow === 'finish_first_v1') {
    h.check('Finish-first workflow has no pre-output in-process inspection', !wo.inspections.some(inspection => inspection.stage === 'in_process') && !wo.process_quality_blocker, wo.inspections);
    state.process_inspection = null;
  } else {
    h.check('In-process QC created and blocks output', wo.inspections.some(inspection => inspection.stage === 'in_process' && inspection.status === 'draft') && !!wo.process_quality_blocker, wo.process_quality_blocker);
    state.process_inspection = wo.inspections.find(inspection => inspection.stage === 'in_process').id;
  }
  const warehouse = await actor('warehouse', 'warehouse@ogami.test');
  await h.open(warehouse, `/inventory/picking?work_order_id=${state.wo}`);
  h.check('Warehouse reviews the issued WO material', (await warehouse.locator('body').innerText()).includes(`${state.wo_number} · Issued materials`));
  const confirmHandover = warehouse.getByRole('button', { name: 'Confirm handover (1 lines)', exact: true });
  if (await confirmHandover.count()) {
    await warehouse.getByRole('checkbox', { name: new RegExp(`Select RM-${h.RUN}`) }).check();
    const handoverWait = warehouse.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/inventory/material-handovers/group'));
    await h.pageButton(warehouse, 'Confirm handover (1 lines)');
    const handover = await handoverWait;
    h.check('Warehouse records actual material handover', handover.status() === 200, await handover.json());
  } else {
    h.check('Warehouse handover already recorded on resumed run', (await warehouse.locator('body').innerText()).includes('0 pending · 1 handed over'));
  }
  h.check('Warehouse handover is saved once', h.sql('select count(*) from material_handovers') === '1');
  h.check('Handover does not issue stock twice', h.sql("select sum(quantity) from stock_movements where movement_type='material_issue'") === '2.000');
  await h.shot(manager, `/production/work-orders/${state.wo}`, '03-started-awaiting-process-qc');
}

async function selectFinalBatch(page, inspection) {
  const group = await read(page, `/quality/work-orders/${inspection.final_work_order_id}/final-inspection`);
  if (group.batches.length <= 1) return;
  const selected = page.waitForResponse(r => r.request().method() === 'GET'
    && new URL(r.url()).pathname.endsWith('/final-inspection')
    && new URL(r.url()).searchParams.get('inspection_id') === inspection.id);
  await page.getByLabel('Output batch', { exact: true }).selectOption(inspection.id);
  const response = await selected;
  h.check('QC selects the exact output batch under review', response.status() === 200, inspection.work_order_output?.batch_code);
}

async function inspect(id, defective = false) {
  const qc = await actor('qc', 'qc@ogami.test');
  let inspection = await read(qc, `/quality/inspections/${id}`);
  if (inspection.final_work_order_id) {
    await h.open(qc, `/quality/inspections/${id}`);
    await selectFinalBatch(qc, inspection);
    h.check('Final inspection has pinned requirements for every produced piece', inspection.measurements.length === 2, inspection.measurements);
    if (['draft', 'in_progress'].includes(inspection.status)) {
      if (defective) {
        await qc.getByLabel('Defective pieces', { exact: true }).fill('1');
        const failedCheck = inspection.measurements.find(row => !row.is_critical) ?? inspection.measurements[0];
        if (await qc.getByLabel('Failed check for piece 1').count()) {
          await qc.getByLabel('Failed check for piece 1').selectOption({ label: failedCheck.parameter_name });
        }
        await qc.getByLabel('Problem for piece 1').fill('One piece has visible flash; isolate it for repair.');
      } else {
        await h.pageButton(qc, 'PASS ALL');
      }
      await qc.getByRole('checkbox', { name: /I checked (every piece|all \d+ pieces)/ }).check();
      const submitWait = qc.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith(`/quality/work-orders/${inspection.final_work_order_id}/final-inspection/submit`));
      await h.pageButton(qc, 'Submit inspection');
      const submitted = await submitWait;
      h.check('Full-piece inspection submits its documented batch result', submitted.status() === 200, await submitted.json());
      inspection = await read(qc, `/quality/inspections/${id}`);
    }
    if (inspection.status === 'awaiting_review') {
      const checker = await actor('checker', fixture.checker_email);
      await h.open(checker, `/quality/inspections/${id}`);
      await selectFinalBatch(checker, inspection);
      await checker.getByLabel('Review remarks', { exact: true }).fill(defective
        ? 'Independent review confirms one defective piece; batch remains held for sorting and repair.'
        : 'Independent review confirms the full batch passed all required checks.');
      const reviewWait = checker.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith(`/quality/work-orders/${inspection.final_work_order_id}/final-inspection/review`));
      await h.pageButton(checker, 'Approve inspection results');
      const reviewed = await reviewWait;
      h.check('Independent checker reviews full-piece QC result', reviewed.status() === 200, await reviewed.json());
      inspection = await read(checker, `/quality/inspections/${id}`);
    }
    h.check('Final full-piece QC verdict matches evidence', inspection.status === (defective ? 'failed' : 'passed'), inspection.status);
    return inspection;
  }
  if (['draft', 'in_progress'].includes(inspection.status)) {
    await h.open(qc, `/quality/inspections/${id}`);
    if (inspection.counted_capture) {
      h.check('Incoming inspection requires full-piece counts and evidence', inspection.measurements.length > 0 && inspection.batch_quantity > 0, inspection.measurements);
      await qc.locator('input[type="number"]').fill(defective ? '1' : '0');
      if (defective) await qc.locator('textarea').fill('One component arrived cracked; isolate it for supplier return.');
      const confirmation = qc.getByRole('checkbox', { name: /I checked all \d+ (?:pieces|lot) against the requirements/ });
      await confirmation.check();
      if (defective && inspection.count_only_incoming) {
        await qc.getByRole('checkbox', { name: /I separated the 1 defective pieces from good stock/ }).check();
      }
      h.check('Incoming inspection has required coverage and segregation evidence', await confirmation.isChecked()
        && (!defective || !inspection.count_only_incoming
          || await qc.getByRole('checkbox', { name: /I separated the 1 defective pieces from good stock/ }).isChecked()));
    } else {
      h.check('Inspection captures one decision per requirement', inspection.capture_method === 'checklist' && inspection.measurements.length === 2, inspection.capture_method);
      for (const row of inspection.measurements) {
        const failed = defective && !row.is_critical;
        await qc.getByRole('button', { name: `${failed ? 'FAIL' : 'PASS'} ${row.parameter_name}`, exact: true }).click();
        if (failed) await qc.getByLabel(`Problem for ${row.parameter_name}`).fill('One piece has visible flash; isolate it for repair.');
      }
      if (defective) {
        await qc.getByRole('button', { name: 'Yes', exact: true }).click();
        await qc.getByLabel('Unique defective pieces').fill('1');
      }
    }
    if (!inspection.counted_capture) await qc.getByRole('checkbox', { name: /I confirm (every piece|all required checks)/ }).check();
    const submitWait = qc.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/inspections/${id}/batch-result`));
    await h.pageButton(qc, 'Submit inspection');
    const submitted = await submitWait;
    h.check(inspection.counted_capture ? 'Counted incoming inspection records defective pieces' : 'Checklist submits without entering measurements', submitted.status() === 200, await submitted.json());
    inspection = await read(qc, `/quality/inspections/${id}`);
  }
  if (inspection.status === 'awaiting_review') {
    const checker = await actor('checker', fixture.checker_email);
    await h.open(checker, `/quality/inspections/${id}`);
    const reviewWait = checker.waitForResponse(r => r.request().method() === 'PATCH' && r.url().endsWith(`/inspections/${id}/review`));
    await h.pageButton(checker, defective ? 'Confirm failure' : 'Approve pass');
    if (defective) await checker.getByRole('dialog').getByRole('textbox').fill('Independent review confirms one defective piece; batch remains held for sorting and repair.');
    await h.modalButton(checker, defective ? 'Confirm failure' : 'Approve pass');
    const reviewed = await reviewWait;
    h.check('Independent QC checker confirms result', reviewed.status() === 200, await reviewed.json());
  }
  inspection = await read(qc, `/quality/inspections/${id}`);
  h.check('Final QC verdict matches evidence', inspection.status === (defective ? 'failed' : 'passed'), inspection.status);
  return inspection;
}

async function processQuality() {
  const manager = await actor('manager', 'production@ogami.test');
  const wo = await read(manager, `/production/work-orders/${state.wo}`);
  if (state.process_inspection) {
    await inspect(state.process_inspection);
    h.check('In-process QC gate released', !wo.process_quality_blocker, wo.process_quality_blocker);
  } else {
    const finishFirstReady = wo.qc_workflow === 'finish_first_v1'
      && !wo.inspections.some(inspection => inspection.stage === 'in_process')
      && ((wo.status === 'in_progress' && wo.quantity_produced === 0)
        || (wo.status === 'completed' && wo.quantity_produced > 0 && wo.inspections.some(inspection => inspection.stage === 'outgoing')));
    h.check('Finish-first workflow uses full-piece outgoing QC', finishFirstReady, wo);
  }
}

async function productionOutput() {
  const worker = await actor('worker', state.recorder.email);
  const manager = await actor('manager', 'production@ogami.test');
  let wo = await read(manager, `/production/work-orders/${state.wo}`);
  if (wo.quantity_produced === 0) {
    await h.open(worker, `/self-service/work-orders/${state.wo}`);
    const quantityLabel = wo.qc_workflow === 'finish_first_v1' ? 'Quantity made' : 'Good count';
    await worker.getByLabel(quantityLabel, { exact: true }).fill('100');
    const outputWait = worker.waitForResponse(r => r.request().method() === 'POST' && /\/outputs$/.test(new URL(r.url()).pathname));
    await h.pageButton(worker, wo.qc_workflow === 'finish_first_v1' ? 'Record production' : 'Record output');
    const output = await outputWait;
    h.check('Assigned employee records actual production', output.status() === 200, await output.json());
    wo = await read(manager, `/production/work-orders/${state.wo}`);
  }
  h.check('100 pieces recorded; outgoing QC remains open', wo.quantity_good === 100 && wo.quantity_produced === 100 && wo.status !== 'closed', wo.status);
  h.check('One output only, attributed to assigned recorder', h.sql("select count(*) from work_order_outputs") === '1' && h.sql("select u.email from work_order_outputs o join users u on u.id=o.recorded_by") === state.recorder.email);
  const workerReceipts = await read(worker, `/production/my-work-orders/${state.wo}/outputs`);
  h.check('Assigned worker can read saved receipt after production completes', workerReceipts.length === 1 && workerReceipts[0].produced_count === 100, workerReceipts);
  h.check('No delivery releases unapproved output', h.sql('select count(*) from deliveries') === '0');
  await h.open(worker, '/self-service/work-orders');
  if (wo.status === 'in_progress') {
    await h.open(manager, `/production/work-orders/${state.wo}`);
    const completeWait = manager.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/work-orders/${state.wo}/complete`));
    await h.pageButton(manager, 'Complete');
    await h.modalButton(manager, 'Complete');
    const complete = await completeWait;
    h.check('Finish production creates outgoing QC', complete.status() === 200, await complete.json());
    wo = await read(manager, `/production/work-orders/${state.wo}`);
  }
  state.outgoing = wo.inspections.find(inspection => inspection.stage === 'outgoing').id;
  h.check('Completed production waits for outgoing QC', wo.status === 'completed' && !!state.outgoing, wo.status);
  await h.shot(manager, `/production/work-orders/${state.wo}`, '05-production-awaits-qc');
}

async function failOutgoing() {
  const manager = await actor('manager', 'production@ogami.test');
  await h.open(manager, `/production/work-orders/${state.wo}`);
  const before = await read(manager, `/production/work-orders/${state.wo}`);
  if (before.status === 'in_progress') {
    const completeWait = manager.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/work-orders/${state.wo}/complete`));
    await h.pageButton(manager, 'Complete');
    await h.modalButton(manager, 'Complete');
    const completed = await completeWait;
    h.check('Production finishes; WO stays open for QC', completed.status() === 200, await completed.json());
  }
  const inspection = await inspect(state.outgoing, true);
  h.check('One defective piece fails entire QC verdict', (inspection.sample_defect_count ?? inspection.defective_quantity) === 1 && inspection.status === 'failed', inspection.sample_defect_count);
  const qc = await actor('qc', 'qc@ogami.test');
  const ncrs = await read(qc, '/quality/ncrs');
  h.check('QC failure automatically creates NCR', ncrs.length === 1, ncrs);
  state.ncr = ncrs[0].id;
  const wo = await read(manager, `/production/work-orders/${state.wo}`);
  h.check('WO cannot auto-close with failed QC', wo.status === 'completed', wo.status);
  h.check('No defective batch delivery created', h.sql('select count(*) from deliveries') === '0');
  await h.shot(qc, `/quality/inspections/${state.outgoing}`, '06-outgoing-failed');
}

async function sorting() {
  const checker = await actor('checker', fixture.checker_email);
  const qc = await actor('qc', 'qc@ogami.test');
  await h.open(checker, `/quality/ncrs/${state.ncr}`);
  let ncr = await read(checker, `/quality/ncrs/${state.ncr}`);
  if (!ncr.mrb_decided_at) {
    await checker.getByLabel('Disposition', { exact: true }).selectOption('rework');
    if (ncr.full_piece_inspection) {
      await checker.getByLabel(/Good pieces to release/).fill('99');
      await checker.getByLabel(/Separation reference/).fill(`SORT-${h.RUN}: 100 inspected, 99 conforming, one flash defect isolated.`);
      await checker.getByRole('checkbox', { name: 'Good pieces match inspection results.' }).check();
      await checker.getByRole('checkbox', { name: 'Defective pieces physically separated.' }).check();
    } else {
      const maker = await read(qc, '/auth/user');
      await checker.getByLabel('Verified good quantity after sorting', { exact: true }).fill('99');
      await checker.getByLabel('Quantity inspected after full-batch sorting').fill('100');
      await checker.getByLabel('Sorting / reinspection evidence reference').fill(`SORT-${h.RUN}: 100 checked, 99 compliant, one isolated flash defect.`);
      await checker.getByLabel('Sorting and reinspection performed by').selectOption(maker.id);
      await checker.getByRole('checkbox', { name: 'Remaining good pieces were segregated, reinspected and passed all requirements.' }).check();
    }
    await checker.getByLabel(/Root cause/).fill('One molded piece has flash.');
    if (await checker.getByLabel('Corrective action', { exact: true }).count()) {
      await checker.getByLabel('Corrective action', { exact: true }).fill('Segregate 99 good pieces; trim and reinspect the single defective piece.');
    }
    const saveWait = checker.waitForResponse(r => r.request().method() === 'PATCH' && r.url().endsWith(`/ncrs/${state.ncr}/disposition`));
    await h.pageButton(checker, ncr.full_piece_inspection ? 'Approve release and repair' : 'Save disposition');
    const saved = await saveWait;
    h.check('Independent checker approves full-batch sorting', saved.status() === 200, await saved.json());
    ncr = await read(checker, `/quality/ncrs/${state.ncr}`);
  }
  h.check('Single defective piece assigned for inline repair', ncr.confirmed_rework_quantity === 1 && ncr.rework_work_order.id === state.wo, ncr);
  h.check('Recovery keeps original WO and quantity', h.sql('select count(*) from work_orders') === '1' && h.sql('select quantity_target from work_orders') === '100');
  await h.shot(checker, `/quality/ncrs/${state.ncr}`, '07-sorting-approved');
}

async function repair() {
  const manager = await actor('manager', 'production@ogami.test');
  let crew = await read(manager, `/production/work-orders/${state.wo}/crew?rework_ncr_id=${state.ncr}`);
  if (!crew.schedule) {
    await h.open(manager, `/production/work-orders/${state.wo}`);
    await h.pageButton(manager, 'Start Rework');
    const dialog = manager.getByRole('dialog');
    await dialog.getByRole('button', { name: 'Auto schedule and suggest resources', exact: true }).click();
    await dialog.getByLabel('Repair method', { exact: true }).selectOption('manual');
    const reviewed = manager.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/rework/${state.ncr}/review-slot`));
    await dialog.getByRole('button', { name: 'Approve repair schedule', exact: true }).click();
    const response = await reviewed;
    h.check('Manual repair slot and crew approved without occupying equipment', response.status() === 200, await response.json());
  }
  crew = await read(manager, `/production/work-orders/${state.wo}/crew?rework_ncr_id=${state.ncr}`);
  if (!crew.current) {
    await h.open(manager, `/production/work-orders/${state.wo}`);
    await h.pageButton(manager, 'Assign workers');
    for (const worker of fixture.workers) {
      await manager.getByRole('dialog').getByRole('combobox', { name: 'Add worker' }).click();
      await manager.getByRole('option').filter({ hasText: worker.name }).click();
    }
    const saved = manager.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/work-orders/${state.wo}/crew`));
    await h.modalButton(manager, 'Save workers');
    const response = await saved;
    h.check('Present Production employees assigned to repair', response.status() === 200, await response.json());
  }
  crew = await read(manager, `/production/work-orders/${state.wo}/crew?rework_ncr_id=${state.ncr}`);
  state.repair_recorder = fixture.workers.find(worker => worker.id === crew.current.recorder_employee_id);
  let wo = await read(manager, `/production/work-orders/${state.wo}`);
  if (!wo.rework.find(rework => rework.id === state.ncr).started_at) {
    await h.open(manager, `/production/work-orders/${state.wo}`);
    await h.pageButton(manager, 'Start Rework');
    const dialog = manager.getByRole('dialog');
    const presence = dialog.getByLabel('Confirm repair workers are physically present', { exact: true });
    if (await presence.count()) await presence.check();
    const started = manager.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/rework/${state.ncr}/start`));
    await dialog.getByRole('button', { name: 'Start rework', exact: true }).click();
    const response = await started;
    h.check('Repair starts inside approved slot', response.status() === 200, await response.json());
  }
  wo = await read(manager, `/production/work-orders/${state.wo}`);
  if (wo.rework.find(rework => rework.id === state.ncr).remaining_quantity > 0) {
    const worker = await actor('repair_worker', state.repair_recorder.email);
    await h.open(worker, `/self-service/work-orders/${state.wo}?rework_ncr_id=${state.ncr}`);
    await worker.getByLabel('Repaired pieces', { exact: true }).fill('1');
    const recorded = worker.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/rework/${state.ncr}/outputs`));
    await h.pageButton(worker, 'Record output');
    const response = await recorded;
    h.check('Assigned recorder repairs one existing piece', response.status() === 200, await response.json());
    await h.open(worker, '/self-service/work-orders');
  }
  wo = await read(manager, `/production/work-orders/${state.wo}`);
  if (!wo.rework.find(rework => rework.id === state.ncr).finished_at) {
    await h.open(manager, `/production/work-orders/${state.wo}`);
    await h.pageButton(manager, 'Continue Rework');
    const dialog = manager.getByRole('dialog', { name: new RegExp(`Continue Rework · ${state.wo_number}`) });
    const finished = manager.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/rework/${state.ncr}/finish`));
    await dialog.getByRole('button', { name: 'Finish rework', exact: true }).click();
    await manager.getByRole('dialog', { name: 'Finish rework?' }).getByRole('button', { name: 'Finish rework', exact: true }).click();
    const response = await finished;
    h.check('Repair finishes and awaits fresh quality approval', response.status() === 200, await response.json());
  }
  wo = await read(manager, `/production/work-orders/${state.wo}`);
  state.repair_inspection = wo.rework.find(rework => rework.id === state.ncr).inspections[0]?.id;
  h.check('Repair has separate fresh inspection', !!state.repair_inspection && state.repair_inspection !== state.outgoing);
  h.check('Repair never inflates original production quantity', wo.quantity_produced === 100 && h.sql('select count(*) from work_orders') === '1');
  h.check('Repair does not reissue original BOM', h.sql("select sum(quantity) from stock_movements where movement_type='material_issue'") === '2.000');
  await h.shot(manager, `/production/work-orders/${state.wo}`, '08-one-piece-repair');
}

async function repairQuality() {
  const inspection = await inspect(state.repair_inspection);
  h.check('Fresh QC accepts repaired piece only', Number(inspection.source_quantity) === 1 && inspection.accepted_quantity === 1, inspection);
  const checker = await actor('checker', fixture.checker_email);
  let ncr = await read(checker, `/quality/ncrs/${state.ncr}`);
  if (ncr.status !== 'closed') {
    await h.open(checker, `/quality/ncrs/${state.ncr}`);
    if (!ncr.full_piece_inspection) {
      for (const [type, description] of [
        ['corrective', 'Removed flash from the isolated piece; fresh independent QC verified compliance.'],
        ['preventive', 'Check mold alignment and flash at production startup to prevent recurrence.'],
      ]) {
        if (ncr.actions.some(action => action.action_type === type)) continue;
        await checker.getByLabel('Type', { exact: true }).selectOption(type);
        await checker.getByLabel('Description', { exact: true }).fill(description);
        const added = checker.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/ncrs/${state.ncr}/actions`));
        await h.pageButton(checker, 'Add action');
        const response = await added;
        h.check(`${type} action recorded before NCR closure`, response.status() === 201, await response.json());
      }
    }
    const closed = checker.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/ncrs/${state.ncr}/close`));
    await h.pageButton(checker, 'Close NCR');
    await h.modalButton(checker, 'Close NCR');
    const response = await closed;
    h.check('NCR closes only after successful repaired-piece QC', response.status() === 200, await response.json());
  }
  const manager = await actor('manager', 'production@ogami.test');
  const wo = await read(manager, `/production/work-orders/${state.wo}`);
  h.check('WO auto-closes after all quality and recovery finishes', wo.status === 'closed', wo.status);
  h.check('Finished stock reconciles to original 100 pieces', h.sql("select sum(quantity) from stock_levels where item_id=(select id from items where code='O2C-" + h.RUN + "')") === '100.000');
  for (const worker of fixture.workers) {
    const employee = await actor(`closed_worker_${worker.id}`, worker.email);
    const assignments = await read(employee, '/production/my-work-orders');
    h.check('Closed WO disappears from employee temporary access', !assignments.some(assignment => assignment.work_order?.id === state.wo || assignment.id === state.wo), assignments);
  }
  await h.shot(manager, `/production/work-orders/${state.wo}`, '09-quality-resolved-wo-closed');
}

async function deliveryAndReceipt() {
  const dispatcher = await actor('dispatcher', 'impex@ogami.test');
  const driver = await actor('driver', 'driver@ogami.test');
  const customer = await actor('customer', fixture.customer_email, true);
  state.deliveries ??= (await read(dispatcher, '/supply-chain/deliveries')).filter(delivery => delivery.sales_order?.id === state.so);
  h.check('Approved 99 plus repaired 1 creates exact 100-piece deliveries', state.deliveries.length > 0 && h.sql('select sum(quantity) from delivery_items') === '100.000');
  for (const entry of state.deliveries) {
    let delivery = await read(dispatcher, `/supply-chain/deliveries/${entry.id}`);
    if (['scheduled', 'loading'].includes(delivery.status)) {
      await h.open(dispatcher, `/supply-chain/deliveries/${entry.id}`);
      await h.pageButton(dispatcher, 'Arrange shipment');
      const dialog = dispatcher.getByRole('dialog');
      const shipmentDate = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
      const shipmentDates = dialog.locator('input[type="date"]');
      h.check('Shipment dialog exposes schedule and arrival date inputs', await shipmentDates.count() === 2);
      await shipmentDates.nth(0).fill(shipmentDate);
      await shipmentDates.nth(1).fill(shipmentDate);
      await dialog.locator('input[maxlength="100"]').fill('Ogami Audit Logistics');
      const assignmentSelects = dialog.locator('select');
      h.check('Shipment dialog exposes vehicle and driver selectors', await assignmentSelects.count() === 2);
      await assignmentSelects.nth(0).selectOption(fixture.vehicle);
      const firstDriver = await assignmentSelects.nth(1).locator('option').nth(1).getAttribute('value');
      h.check('Shipment dialog lists an active driver', Boolean(firstDriver));
      await assignmentSelects.nth(1).selectOption(firstDriver);
      await dialog.locator('textarea').fill('Customer accepted the full approved order shipment.');
      await dialog.locator('input[type="file"]').setInputFiles(path.join(h.OUT, '01-sales-confirmed.png'));
      const dispatched = dispatcher.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/deliveries/${entry.id}/shipment-plan`));
      await dialog.getByRole('button', { name: 'Dispatch shipment', exact: true }).click();
      const response = await dispatched;
      const dispatchBody = await response.json();
      h.check('Dispatcher arranges and dispatches shipment with loading photo', response.status() === 200 && dispatchBody.data.status === 'in_transit', dispatchBody);
      delivery = await read(dispatcher, `/supply-chain/deliveries/${entry.id}`);
    }
    delivery = await read(driver, `/driver/deliveries/${entry.id}`);
    if (delivery.status === 'in_transit') {
      await h.open(driver, `/driver/${entry.id}`);
      await h.pageButton(driver, 'Record delivery');
      await driver.waitForURL(new RegExp(`/driver/${entry.id}/photo$`));
      await driver.locator('input[type="file"]').nth(1).setInputFiles({
        name: `receipt-${entry.id}.png`, mimeType: 'image/png',
        buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64'),
      });
      const recorded = driver.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/driver/deliveries/${entry.id}/receipt`));
      await h.pageButton(driver, 'Record delivery');
      const response = await recorded;
      h.check('Driver records handover with receipt photo', response.status() === 200, await response.json());
      delivery = await read(driver, `/driver/deliveries/${entry.id}`);
      h.check('Receipt photo advances delivery to delivered', delivery.status === 'delivered', delivery.status);
    }
    delivery = await read(dispatcher, `/supply-chain/deliveries/${entry.id}`);
    if (!delivery.proofs?.some(proof => proof.proof_type === 'photo' && !proof.deleted_at)) {
      await h.open(driver, `/driver/${entry.id}/photo`);
      await driver.locator('input[type="file"]').nth(1).setInputFiles({
        name: `receipt-${entry.id}.png`, mimeType: 'image/png',
        buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64'),
      });
      const uploaded = driver.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/driver/deliveries/${entry.id}/receipt`));
      await h.pageButton(driver, 'Upload photo');
      const response = await uploaded;
      h.check('Driver uploads actual receipt evidence', response.status() === 200, await response.json());
    }
    delivery = await read(dispatcher, `/supply-chain/deliveries/${entry.id}`);
    if (delivery.status !== 'confirmed') {
      await h.open(customer, `/portal/customer/deliveries/${entry.id}`);
      await h.pageButton(customer, /^Confirm Receipt$/i);
      await customer.getByLabel('Received by').fill('Juan Dela Cruz');
      await customer.getByLabel('Position').fill('Warehouse Staff');
      const confirmed = customer.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/b2b/customer/deliveries/${entry.id}/confirm`));
      await h.pageButton(customer, 'Confirm receipt');
      const response = await confirmed;
      h.check('Customer confirms actual receipt with proof', response.status() === 200, await response.json());
    }
    delivery = await read(dispatcher, `/supply-chain/deliveries/${entry.id}`);
    h.check('Delivery confirmation generates draft invoice visibly', delivery.status === 'confirmed' && delivery.invoice_handoff.status === 'generated' && !!delivery.invoice, delivery);
    await h.shot(dispatcher, `/supply-chain/deliveries/${entry.id}`, `10-delivery-${entry.id}-confirmed`);
  }
  h.check('Dispatch consumes exactly 100 good pieces', h.sql("select sum(quantity) from stock_movements where movement_type='delivery'") === '100.000');
  h.check('No finished stock remains after full delivery', h.sql("select coalesce(sum(quantity),0) from stock_levels where item_id=(select id from items where code='O2C-" + h.RUN + "')") === '0.000');
}

async function billingAndPayment() {
  const finance = await actor('finance', 'finance@ogami.test');
  const dispatcher = await actor('dispatcher', 'impex@ogami.test');
  const customer = await actor('customer', fixture.customer_email, true);
  const invoices = [];
  for (const delivery of state.deliveries) {
    const current = await read(dispatcher, `/supply-chain/deliveries/${delivery.id}`);
    invoices.push(await read(finance, `/invoices/${current.invoice.id}`));
  }
  h.check('One invoice per confirmed shipment totals VAT-inclusive 1400', invoices.length === state.deliveries.length && h.sql('select sum(total_amount) from invoices') === '1400.00');
  for (const entry of invoices) {
    let invoice = await read(finance, `/invoices/${entry.id}`);
    if (invoice.status === 'draft') {
      await h.open(finance, `/accounting/invoices/${entry.id}`);
      const finalized = finance.waitForResponse(r => r.request().method() === 'PATCH' && r.url().endsWith(`/invoices/${entry.id}/finalize`));
      await h.pageButton(finance, 'Finalize');
      await h.modalButton(finance, 'Finalize');
      const response = await finalized;
      h.check('Finance finalizes customer invoice', response.status() === 200, await response.json());
      invoice = await read(finance, `/invoices/${entry.id}`);
    }
    if (Number(invoice.balance) > 0) {
      const paymentDate = new Date(Date.now() + 8 * 3600e3).toISOString().slice(0, 10);
      const submitProof = async (reference) => {
        await h.open(customer, `/portal/customer/invoices/${entry.id}`);
        h.check('Customer invoice states bank-transfer method', await customer.getByText(/payment method: Bank transfer/i).isVisible());
        await h.pageButton(customer, 'Submit payment');
        const dialog = customer.getByRole('dialog');
        h.check('Portal directs customer to Finance for verified receiving details', await dialog.getByText(/Contact Ogami Finance for verified receiving account details/i).isVisible());
        await dialog.locator('input[maxlength="100"]').fill('Audit Test Bank');
        await dialog.locator('input[maxlength="50"]').fill(reference);
        await dialog.locator('input[type="date"]').fill(paymentDate);
        await dialog.locator('input[type="file"]').setInputFiles({
          name: `${reference}.pdf`, mimeType: 'application/pdf',
          buffer: Buffer.from('%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF'),
        });
        const submitted = customer.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === `/api/v1/b2b/customer/invoices/${entry.id}/payment-submissions`);
        await h.modalButton(customer, 'Submit payment evidence');
        const response = await submitted;
        h.check('Customer submits bank-transfer proof without triggering collection', response.status() === 201, await response.json());
      };

      const firstReference = `B2B-${h.RUN}-${entry.id}-REJECTED`;
      invoice = await read(finance, `/invoices/${entry.id}`);
      if (!invoice.payment_submissions?.some(payment => payment.reference_number === firstReference)) await submitProof(firstReference);
      invoice = await read(finance, `/invoices/${entry.id}`);
      let firstProof = invoice.payment_submissions?.find(payment => payment.reference_number === firstReference);
      h.check('Submitted proof is recorded without changing invoice balance', !!firstProof && ['pending', 'rejected'].includes(firstProof.status) && invoice.status === 'finalized' && Number(invoice.balance) > 0, invoice.payment_submissions);
      if (firstProof.status === 'pending') {
        await h.open(finance, `/accounting/invoices/${entry.id}`);
        await h.pageButton(finance, 'Review payment');
        const review = finance.getByRole('dialog');
        await h.modalButton(finance, 'Reject payment');
        await review.locator('textarea').fill('Reference does not match the receiving bank record.');
        const rejected = finance.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/invoices/${entry.id}/payment-submissions/${firstProof.id}/reject`));
        await h.modalButton(finance, 'Reject payment');
        const response = await rejected;
        h.check('Finance can reject unverified proof with a reason', response.status() === 200, await response.json());
      }

      const verifiedReference = `B2B-${h.RUN}-${entry.id}-VERIFIED`;
      invoice = await read(finance, `/invoices/${entry.id}`);
      if (!invoice.payment_submissions?.some(payment => payment.reference_number === verifiedReference)) await submitProof(verifiedReference);
      invoice = await read(finance, `/invoices/${entry.id}`);
      let pending = invoice.payment_submissions?.find(payment => payment.reference_number === verifiedReference && payment.status === 'pending');
      h.check('Customer can submit corrected evidence after rejection', !!pending && invoice.payment_submissions?.some(payment => payment.reference_number === firstReference && payment.status === 'rejected'), invoice.payment_submissions);
      if (pending) {
        await h.open(finance, `/accounting/invoices/${entry.id}`);
        await h.pageButton(finance, 'Review payment');
        const review = finance.getByRole('dialog');
        await review.locator('select').selectOption(fixture.cash_account);
        await review.locator('input[type="checkbox"]').check();
        const verified = finance.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/invoices/${entry.id}/payment-submissions/${pending.id}/verify`));
        await h.modalButton(finance, 'Confirm funds received');
        const response = await verified;
        h.check('Finance verifies cleared funds and records exact collection', response.status() === 200, await response.json());
      }
    }
    invoice = await read(finance, `/invoices/${entry.id}`);
    h.check('Invoice balance settles only after Finance verifies payment', invoice.status === 'paid' && Number(invoice.balance) === 0 && invoice.payment_submissions?.some(payment => payment.status === 'rejected') && invoice.payment_submissions?.some(payment => payment.status === 'verified'), invoice);
    h.check('Verified collection uses bank transfer and proof reference', invoice.collections?.some(payment => payment.payment_method === 'bank_transfer' && payment.reference_number?.includes('-VERIFIED')));
    await h.shot(finance, `/accounting/invoices/${entry.id}`, `11-invoice-${entry.id}-paid`);
  }
  h.check('Every posted journal is balanced', h.sql("select count(*) from journal_entries where status='posted' and total_debit<>total_credit") === '0');
  const sales = await actor('sales', 'crm@ogami.test');
  const order = await read(sales, `/crm/sales-orders/${state.so}`);
  h.check('SO automatically closes only after full delivery and payment', order.status === 'closed', order.status);
  h.check('All automatic events finished without hidden failure', h.sql("select count(*) from event_outbox where status<>'published'") === '0' && h.sql("select count(*) from chain_listener_runs where status='failed'") === '0');
  await h.shot(sales, `/crm/sales-orders/${state.so}`, '11-so-closed');
}

async function supplierRequest() {
  if (!fixture.supplier) {
    const seeded = h.artisan('tinker', '--execute', "require 'tests/Browser/b2b_supplier_prerequisites.php';");
    if (!seeded.includes('B2B supplier ready.')) throw new Error(seeded);
    h.compose(['cp', `api:${h.env.O2C_FIXTURE_PATH}`, path.join(h.OUT, 'fixture.json')]);
    fixture = JSON.parse(fs.readFileSync(path.join(h.OUT, 'fixture.json'), 'utf8'));
  }
  const purchasing = await actor('purchasing', 'purchasing@ogami.test');
  if (!state.pr) {
    await h.open(purchasing, '/purchasing/purchase-requests/create');
    await purchasing.getByLabel('Priority').selectOption('normal');
    await purchasing.getByRole('combobox', { name: 'Department' }).click();
    await purchasing.getByRole('option', { name: 'Production', exact: true }).click();
    await purchasing.getByLabel('Reason').fill('Purchase 100 raw components; verify supplier defect return and replacement.');
    await purchasing.getByRole('combobox', { name: 'Item', exact: true }).fill(fixture.supplier.item_code);
    await purchasing.getByRole('option').filter({ hasText: fixture.supplier.item_code }).click();
    await purchasing.getByLabel('Quantity', { exact: true }).fill('100');
    const created = purchasing.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/api/v1/purchasing/purchase-requests');
    await h.pageButton(purchasing, 'Submit for approval');
    await h.modalButton(purchasing, 'Submit');
    const response = await created;
    const payload = await response.json();
    h.check('Purchasing creates supplier replenishment PR', response.status() === 201, payload);
    state.pr = payload.data.id;
    persist();
    await purchasing.waitForURL(url => url.pathname === `/purchasing/purchase-requests/${state.pr}`);
  }
  const finance = await actor('finance', 'finance@ogami.test');
  let pr = await read(purchasing, `/purchasing/purchase-requests/${state.pr}`);
  if (pr.status === 'pending') {
    await h.open(finance, `/purchasing/purchase-requests/${state.pr}`);
    if (await finance.getByRole('button', { name: 'Finance acknowledge', exact: true }).count()) {
      await h.pageButton(finance, 'Finance acknowledge');
      await finance.getByText('Finance acknowledged', { exact: true }).waitFor();
    }
    const approved = finance.waitForResponse(r => r.request().method() === 'PATCH' && r.url().endsWith(`/purchase-requests/${state.pr}/approve`));
    await h.pageButton(finance, 'Approve');
    await h.modalButton(finance, 'Approve');
    const response = await approved;
    h.check('Independent Finance approves procurement request', response.status() === 200, await response.json());
  }
  pr = await read(purchasing, `/purchasing/purchase-requests/${state.pr}`);
  h.check('Supplier request finishes approval', ['approved', 'converted'].includes(pr.status), pr.status);
  state.po = (await read(purchasing, '/purchasing/purchase-orders')).find(order => order.vendor?.id === fixture.supplier.id)?.id;
  if (!state.po) {
    await h.open(purchasing, `/purchasing/purchase-requests/${state.pr}`);
    await h.pageButton(purchasing, 'Convert to PO');
    await purchasing.getByRole('dialog').getByLabel('Supplier').selectOption(fixture.supplier.id);
    const converted = purchasing.waitForResponse(r => r.request().method() === 'POST' && /\/convert/.test(new URL(r.url()).pathname));
    await h.modalButton(purchasing, 'Create purchase order');
    const response = await converted;
    h.check('Approved request converts to supplier PO', response.status() === 200, await response.json());
    state.po = (await read(purchasing, '/purchasing/purchase-orders')).find(order => order.vendor?.id === fixture.supplier.id).id;
  }
  h.check('Exactly one supplier PO created', h.sql('select count(*) from purchase_orders') === '1');
  await h.shot(purchasing, `/purchasing/purchase-requests/${state.pr}`, '12-pr-approved');
}

async function sendPurchaseOrder(id) {
  const purchasing = await actor('purchasing', 'purchasing@ogami.test');
  const finance = await actor('finance', 'finance@ogami.test');
  let order = await read(purchasing, `/purchasing/purchase-orders/${id}`);
  for (const [from, action, button, user] of [
    ['draft', 'submit', 'Submit', purchasing],
    ['pending_approval', 'approve', 'Approve', finance],
    ['approved', 'send', 'Mark as sent', purchasing],
  ]) {
    if (order.status !== from) continue;
    await h.open(user, `/purchasing/purchase-orders/${id}`);
    const responseWait = user.waitForResponse(r => r.request().method() === 'PATCH' && r.url().endsWith(`/purchase-orders/${id}/${action}`));
    await h.pageButton(user, button);
    await h.modalButton(user, button);
    const response = await responseWait;
    h.check(`Purchase order ${action} succeeds`, response.status() === 200, await response.json());
    order = await read(purchasing, `/purchasing/purchase-orders/${id}`);
  }
  const supplier = await actor('supplier', fixture.supplier.email, 'supplier');
  order = await read(supplier, `/b2b/supplier/purchase-orders/${id}`);
  if (order.capabilities.can_accept) {
    await h.open(supplier, `/portal/supplier/purchase-orders/${id}`);
    const accepted = supplier.waitForResponse(r => r.request().method() === 'POST' && /\/respond$/.test(r.url()));
    await h.pageButton(supplier, 'Accept');
    await h.modalButton(supplier, 'Accept PO');
    const response = await accepted;
    h.check('Supplier accepts PO through own portal', response.status() === 201, await response.json());
  }
  order = await read(supplier, `/b2b/supplier/purchase-orders/${id}`);
  h.check('Supplier acceptance persists', order.latest_response?.type === 'accept' && order.latest_response.status === 'accepted'
    && ['acknowledged', 'partially_received', 'received', 'closed'].includes(order.status), order);
}

async function supplierOrder() {
  await sendPurchaseOrder(state.po);
  const warehouse = await actor('warehouse', 'warehouse@ogami.test');
  const receipts = await read(warehouse, '/inventory/grn');
  state.grn = receipts.find(receipt => receipt.purchase_order?.id === state.po)?.id;
  h.check('Sent PO automatically creates draft warehouse receipt', !!state.grn, receipts);
  await h.shot(warehouse, `/inventory/grn/${state.grn}`, '13-supplier-po-draft-receipt');
}

async function receiveSupplier(id, quantity) {
  const warehouse = await actor('warehouse', 'warehouse@ogami.test');
  let receipt = await read(warehouse, `/inventory/grn/${id}`);
  if (receipt.status === 'draft') {
    await h.open(warehouse, `/inventory/grn/${id}`);
    await warehouse.locator('select[aria-label^="Bin "]').selectOption(fixture.raw_location);
    await warehouse.locator('input[aria-label^="Entered quantity "]').fill(String(quantity));
    const finalized = warehouse.waitForResponse(r => r.request().method() === 'PATCH' && r.url().endsWith(`/grn/${id}/finalize`));
    await h.pageButton(warehouse, 'Finalize receiving');
    await h.modalButton(warehouse, 'Finalize GRN');
    const response = await finalized;
    h.check('Warehouse receives actual supplier quantity', response.status() === 200, await response.json());
    receipt = await read(warehouse, `/inventory/grn/${id}`);
  }
  return receipt;
}

async function supplierDispatch() {
  const supplier = await actor('supplier', fixture.supplier.email, 'supplier');
  let order = await read(supplier, `/b2b/supplier/purchase-orders/${state.po}`);
  let shipment = order.shipments?.find(row => order.items.every(item =>
    row.items?.some(line => line.purchase_order_item_id === item.id && Number(line.quantity_shipped) === Number(item.quantity_dispatch_limit))));
  if (!shipment) {
    await h.open(supplier, `/portal/supplier/purchase-orders/${state.po}`);
    await h.pageButton(supplier, 'Add shipment');
    const dialog = supplier.getByRole('dialog');
    const dates = dialog.locator('input[type="date"]');
    h.check('Supplier shipment form exposes shipped and arrival dates', await dates.count() === 2);
    await dates.nth(1).fill(new Date(Date.now() + 8 * 3600e3).toISOString().slice(0, 10));
    await dialog.locator('input[maxlength="100"]').fill('Audit Test Supplier Logistics');
    for (const item of order.items) {
      await dialog.locator(`input[aria-label="Shipped quantity for ${item.part_number}"]`).fill(item.quantity_dispatch_limit);
    }
    await dialog.locator('input[type="file"]').setInputFiles(path.join(h.OUT, '01-sales-confirmed.png'));
    const created = supplier.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/purchase-orders/${state.po}/shipments`));
    await h.modalButton(supplier, 'Add shipment');
    const response = await created;
    const body = await response.json();
    h.check('Supplier records dispatched PO quantities and photo', response.status() === 201 && body.data.quantity_recorded, body);
    state.supplier_shipment = body.data.id;
  } else {
    state.supplier_shipment = shipment.id;
  }
  order = await read(supplier, `/b2b/supplier/purchase-orders/${state.po}`);
  shipment = order.shipments?.find(row => row.id === state.supplier_shipment);
  h.check('Supplier dispatch records exact PO quantities', !!shipment && order.items.every(item =>
    shipment.items?.some(line => line.purchase_order_item_id === item.id && Number(line.quantity_shipped) === Number(item.quantity_dispatch_limit))));
}

async function incomingFailure() {
  await supplierDispatch();
  const receipt = await receiveSupplier(state.grn, 100);
  state.incoming = receipt.items[0].inspection.id;
  h.check('Unapproved supplier material never enters usable stock', h.sql(`select coalesce(sum(quantity),0) from stock_levels where item_id=(select id from items where code='${fixture.supplier.item_code}')`) === '0');
  await inspect(state.incoming, true);
  const qc = await actor('qc', 'qc@ogami.test');
  state.supplier_ncr = (await read(qc, `/quality/ncrs?inspection_id=${state.incoming}`))[0]?.id;
  h.check('Supplier failure creates NCR and holds receiving', !!state.supplier_ncr);
  const warehouse = await actor('warehouse', 'warehouse@ogami.test');
  const current = await read(warehouse, `/inventory/grn/${state.grn}`);
  h.check('Supplier QC failure waits for independent sorting decision', current.status === 'pending_qc', current.status);
  await h.shot(qc, `/quality/inspections/${state.incoming}`, '14-supplier-one-piece-failure');
}

async function supplierSorting() {
  const checker = await actor('checker', fixture.checker_email);
  const qc = await actor('qc', 'qc@ogami.test');
  const maker = await read(qc, '/auth/user');
  let ncr = await read(checker, `/quality/ncrs/${state.supplier_ncr}`);
  await h.open(checker, `/quality/ncrs/${state.supplier_ncr}`);
  if (!ncr.mrb_decided_at) {
    await checker.getByLabel('Disposition', { exact: true }).selectOption('return_to_supplier');
    await checker.getByLabel('Verified good quantity after sorting', { exact: true }).fill('99');
    await checker.getByLabel('Quantity inspected after full-batch sorting').fill('100');
    await checker.getByLabel('Sorting / reinspection evidence reference').fill(`SUP-SORT-${h.RUN}: all 100 checked, 99 compliant, one cracked component isolated.`);
    await checker.getByLabel('Sorting and reinspection performed by').selectOption(maker.id);
    await checker.getByRole('checkbox', { name: 'Remaining good pieces were segregated, reinspected and passed all requirements.' }).check();
    await checker.getByLabel('Root cause', { exact: true }).fill('One component arrived cracked.');
    await checker.getByLabel('Corrective action', { exact: true }).fill('Accept the verified 99 pieces and return the single defective component for replacement.');
    const saved = checker.waitForResponse(r => r.request().method() === 'PATCH' && r.url().endsWith(`/ncrs/${state.supplier_ncr}/disposition`));
    await h.pageButton(checker, 'Save disposition');
    const response = await saved;
    h.check('Independent sorting accepts only 99 supplier pieces', response.status() === 200, await response.json());
    ncr = await read(checker, `/quality/ncrs/${state.supplier_ncr}`);
  }
  for (const [type, description] of [
    ['corrective', 'Segregated the cracked component; 99 good components passed full-batch sorting.'],
    ['preventive', 'Supplier must improve packaging and check components for cracks before dispatch.'],
  ]) {
    if (ncr.actions.some(action => action.action_type === type)) continue;
    await checker.getByLabel('Type', { exact: true }).selectOption(type);
    await checker.getByPlaceholder('Describe what was done or what needs to happen.', { exact: true }).fill(description);
    const added = checker.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/ncrs/${state.supplier_ncr}/actions`));
    await h.pageButton(checker, 'Add action');
    const response = await added;
    h.check(`Supplier ${type} action recorded`, response.status() === 201, await response.json());
  }
  if (ncr.status !== 'closed') {
    const closed = checker.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/ncrs/${state.supplier_ncr}/close`));
    await h.pageButton(checker, 'Close NCR');
    await h.modalButton(checker, 'Close NCR');
    const response = await closed;
    h.check('Supplier NCR closes after verified routing into return workflow', response.status() === 200, await response.json());
  }
  const warehouse = await actor('warehouse', 'warehouse@ogami.test');
  const receipt = await read(warehouse, `/inventory/grn/${state.grn}`);
  h.check('Original GRN accepts 99 and rejects one', Number(receipt.items[0].quantity_accepted) === 99, receipt);
  const returns = await read(warehouse, '/return-management/return-requests');
  state.supplier_rma = returns.find(rma => rma.purchase_order?.id === state.po)?.id;
  h.check('One supplier return automatically created', !!state.supplier_rma && h.sql('select count(*) from return_requests') === '1', returns);
  const rma = await read(warehouse, `/return-management/return-requests/${state.supplier_rma}`);
  h.check('Supplier return covers only defective component with exact GRN and PO lineage', rma.items.length === 1 && Number(rma.items[0].quantity) === 1 && !!rma.items[0].source_grn_item_id && !!rma.items[0].source_po_item_id, rma.items);
  h.check('Only sorted good supplier pieces enter stock', h.sql(`select sum(quantity) from stock_levels where item_id=(select id from items where code='${fixture.supplier.item_code}')`) === '99.000');
  h.check('Supplier defect never creates a production WO', h.sql('select count(*) from work_orders') === '1');
  await h.shot(warehouse, `/return-management/${state.supplier_rma}`, '15-supplier-return-one-piece');
}

async function supplierReturn() {
  const purchasing = await actor('purchasing', 'purchasing@ogami.test');
  const id = state.supplier_rma;
  const route = `/return-management/return-requests/${id}`;
  let rma = await read(purchasing, route);
  if (rma.status === 'draft') {
    await h.open(purchasing, `/return-management/${id}`);
    const submitted = purchasing.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`${route}/submit`));
    await h.pageButton(purchasing, 'Send to supplier');
    const dialog = purchasing.getByRole('dialog');
    await dialog.locator('input[type="file"]').setInputFiles(path.join(h.OUT, '14-supplier-one-piece-failure.png'));
    await dialog.locator('input[type="date"]').fill(new Date(Date.now() + 8 * 3600e3 + 14 * 864e5).toISOString().slice(0, 10));
    await h.modalButton(purchasing, 'Send to supplier');
    const response = await submitted;
    h.check('Purchasing sends defect evidence and replacement deadline to supplier', response.status() === 200, await response.json());
    rma = await read(purchasing, route);
  }
  for (const [step, key, email] of [[1, 'depthead', 'depthead@ogami.test'], [2, 'manager', 'production@ogami.test']]) {
    if (rma.status !== 'pending_approval' || rma.pending_approval_step !== step) continue;
    const approver = await actor(key, email);
    await h.open(approver, `/return-management/${id}`);
    const approved = approver.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`${route}/approve`));
    await h.pageButton(approver, 'Approve');
    await h.modalButton(approver, 'Approve');
    const response = await approved;
    h.check(`Supplier return approval step ${step}`, response.status() === 200, await response.json());
    rma = await read(purchasing, route);
  }
  if (rma.status === 'approved') {
    const warehouse = await actor('warehouse', 'warehouse@ogami.test');
    await h.open(warehouse, `/return-management/${id}`);
    await h.pageButton(warehouse, 'Record Receipt');
    await warehouse.getByRole('dialog').getByRole('spinbutton').fill(rma.items[0].remaining_quantity);
    const received = warehouse.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`${route}/receive`));
    await h.modalButton(warehouse, 'Record Final Receipt');
    const response = await received;
    h.check('Warehouse confirms custody of one defective component', response.status() === 200, await response.json());
    rma = await read(purchasing, route);
  }
  if (rma.status === 'received') {
    await h.open(purchasing, `/return-management/${id}`);
    const inspected = purchasing.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`${route}/inspect`));
    await purchasing.getByRole('button', { name: /Stage Quality Handoff|Continue to Disposition/, exact: true }).click();
    await purchasing.getByRole('dialog').getByRole('button', { name: /Stage Handoff|Continue/, exact: true }).click();
    const response = await inspected;
    h.check('Supplier return reuses its incoming quality evidence', response.status() === 200, await response.json());
    rma = await read(purchasing, route);
  }
  if (rma.disposition_status !== 'disposed') {
    await h.open(purchasing, `/return-management/${id}`);
    await h.pageButton(purchasing, 'Dispose Items');
    const dialog = purchasing.getByRole('dialog');
    await dialog.getByLabel('Disposition', { exact: true }).selectOption('return_to_supplier');
    await dialog.getByLabel('Restock location', { exact: true }).selectOption(fixture.raw_location);
    await dialog.getByLabel(/Raise a replacement purchase order/).check();
    const disposed = purchasing.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`${route}/dispose`));
    await h.modalButton(purchasing, 'Record Disposition');
    const response = await disposed;
    h.check('Supplier return creates one-piece replacement PO', response.status() === 200, await response.json());
    rma = await read(purchasing, route);
  }
  h.check('Supplier quality return completes with defect evidence and due date', rma.status === 'completed' && rma.requires_defect_evidence && !!rma.defect_photo_name && !!rma.replacement_due_date, rma);
  h.check('Rejected component creates no bill credit or double stock issue', !rma.credit_note && h.sql(`select sum(quantity) from stock_levels where item_id=(select id from items where code='${fixture.supplier.item_code}')`) === '99.000');
  const supplier = await actor('supplier', fixture.supplier.email, 'supplier');
  const order = await read(supplier, `/b2b/supplier/purchase-orders/${state.po}`);
  const pendingReturn = order.supplier_returns?.find(item => item.id === id);
  h.check('Supplier sees one replacement obligation on the original PO', !!pendingReturn?.replacement_pending && pendingReturn.items.length === 1 && Number(pendingReturn.items[0].quantity) === 1 && !pendingReturn.replacement_purchase_order, pendingReturn);
  h.check('Original PO allows only the one owed replacement dispatch', order.capabilities.can_update_shipment && order.items.length === 1 && Number(order.items[0].quantity_dispatch_limit) === 101, order.items);
  h.check('Replacement remains against the original PO with no duplicate PO', h.sql('select count(*) from purchase_orders') === '1' && !rma.replacement_purchase_order && order.id === state.po);
  await h.shot(purchasing, `/return-management/${id}`, '16-supplier-return-completed');
}

async function supplierReplacement() {
  const supplier = await actor('supplier', fixture.supplier.email, 'supplier');
  let order = await read(supplier, `/b2b/supplier/purchase-orders/${state.po}`);
  let shipment = order.shipments?.find(row => row.return_rma_number && order.supplier_returns?.some(item =>
    item.rma_number === row.return_rma_number && item.id === state.supplier_rma));
  if (!shipment) {
    await h.open(supplier, `/portal/supplier/purchase-orders/${state.po}`);
    await h.pageButton(supplier, 'Add shipment');
    const dialog = supplier.getByRole('dialog');
    await dialog.getByLabel('Shipment purpose', { exact: true }).selectOption(state.supplier_rma);
    const dates = dialog.locator('input[type="date"]');
    await dates.nth(1).fill(new Date(Date.now() + 8 * 3600e3 + 14 * 864e5).toISOString().slice(0, 10));
    await dialog.locator('input[maxlength="100"]').fill('Audit Test Supplier Replacement');
    for (const item of order.items) {
      if (Number(item.quantity_dispatch_limit) > Number(item.quantity_received)) {
        await dialog.locator(`input[aria-label="Shipped quantity for ${item.part_number}"]`).fill('1');
      }
    }
    await dialog.locator('input[type="file"]').setInputFiles(path.join(h.OUT, '14-supplier-one-piece-failure.png'));
    const created = supplier.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/purchase-orders/${state.po}/shipments`));
    await h.modalButton(supplier, 'Add shipment');
    const response = await created;
    const body = await response.json();
    h.check('Supplier dispatches exactly one replacement against the source RMA', response.status() === 201 && body.data.return_rma_number, body);
    shipment = body.data;
    state.replacement_shipment = shipment.id;
  } else {
    state.replacement_shipment = shipment.id;
  }
  order = await read(supplier, `/b2b/supplier/purchase-orders/${state.po}`);
  shipment = order.shipments?.find(row => row.id === state.replacement_shipment);
  h.check('Replacement shipment remains linked to the source RMA and carries one piece', shipment?.return_rma_number === order.supplier_returns?.find(item => item.id === state.supplier_rma)?.rma_number && shipment.items.length === 1 && Number(shipment.items[0].quantity_shipped) === 1, shipment);
  const warehouse = await actor('warehouse', 'warehouse@ogami.test');
  const receipts = await read(warehouse, '/inventory/grn');
  const replacementReceipts = receipts.filter(receipt => receipt.purchase_order?.id === state.po && receipt.id !== state.grn);
  for (const candidate of replacementReceipts) {
    const detail = await read(warehouse, `/inventory/grn/${candidate.id}`);
    if (detail.supplier_shipment?.id === state.replacement_shipment) {
      state.replacement_grn = detail.id;
      break;
    }
  }
  h.check('Replacement shipment automatically creates a separate linked GRN', !!state.replacement_grn && state.replacement_grn !== state.grn, replacementReceipts);
  let receipt = await receiveSupplier(state.replacement_grn, 1);
  state.replacement_inspection = receipt.items[0].inspection.id;
  const inspection = await inspect(state.replacement_inspection);
  h.check('Replacement receives fresh independent one-piece QC', inspection.id !== state.incoming && Number(inspection.source_quantity) === 1 && !!inspection.reviewer, inspection);
  receipt = await read(warehouse, `/inventory/grn/${state.replacement_grn}`);
  h.check('Replacement GRN accepts exactly one compliant piece', Number(receipt.items[0].quantity_accepted) === 1, receipt);
  h.check('Final supplier stock is exactly 100 compliant components', h.sql(`select sum(quantity) from stock_levels where item_id=(select id from items where code='${fixture.supplier.item_code}')`) === '100.000');
  h.check('Original supplier failure remains in history', (await read(await actor('qc', 'qc@ogami.test'), `/quality/inspections/${state.incoming}`)).status === 'failed');
  h.check('Supplier recovery creates no extra production WO or credit', h.sql('select count(*) from work_orders') === '1' && h.sql('select count(*) from credit_notes') === '0');
  h.check('All supplier chain events complete with balanced journals', h.sql("select count(*) from event_outbox where status<>'published'") === '0' && h.sql("select count(*) from chain_listener_runs where status='failed'") === '0' && h.sql("select count(*) from journal_entries where status='posted' and total_debit<>total_credit") === '0');
  await h.shot(warehouse, `/inventory/grn/${state.replacement_grn}`, '17-supplier-replacement-accepted');
}

async function customerWarrantyComplaint() {
  const customer = await actor('customer', fixture.customer_email, true);
  const service = await actor('customer_service', 'customerservice@ogami.test');
  let complaint = state.complaint ? await read(service, `/crm/complaints/${state.complaint}`) : null;
  let deliveredItemId = complaint?.delivered_item?.id;
  if (!complaint) {
    const existing = await read(service, `/crm/complaints?per_page=100&search=${encodeURIComponent(`B2B ${h.RUN}:` )}`);
    const found = existing.find(row => row.description?.includes(`B2B ${h.RUN}:`));
    if (found) {
      state.complaint = found.id;
      complaint = await read(service, `/crm/complaints/${state.complaint}`);
      persist();
    }
  }
  let response;
  if (!complaint) {
    await h.open(customer, '/portal/customer/complaints');
    await h.pageButton(customer, 'New complaint');
    await customer.getByLabel('Related order', { exact: true }).selectOption(state.so);
    const deliveredItem = customer.getByLabel('Delivered item', { exact: true });
    deliveredItemId = await deliveredItem.locator('option').evaluateAll((options, code) =>
      options.find(option => option.textContent?.includes(code))?.value ?? '', fixture.product_code);
    h.check('Warranty complaint offers the delivered item from this customer order', !!deliveredItemId);
    await deliveredItem.selectOption(deliveredItemId);
    h.check('Portal shows advisory warranty and report-window dates', await customer.getByText(/Report-by estimate:.*Warranty period through/s).isVisible());
    await customer.getByLabel('Severity', { exact: true }).selectOption('high');
    await customer.getByPlaceholder('Describe the issue…', { exact: true }).fill(`B2B ${h.RUN}: one delivered part has a manufacturing defect; request inspection under the saved warranty.`);
    await customer.locator('form input[type="number"]').fill('1');
    const submitted = customer.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/api/v1/b2b/customer/complaints');
    await h.pageButton(customer, 'Submit complaint');
    response = await submitted;
    const payload = await response.json();
    h.check('Portal accepts a delivered-item warranty complaint for staff review', response.status() === 201, payload);
    state.complaint = payload.data.id;
    persist();
    complaint = await read(service, `/crm/complaints/${state.complaint}`);
  }
  h.check('Complaint retains delivery provenance and opens its NCR handoff', ['open', 'resolved', 'closed'].includes(complaint.status)
    && complaint.delivered_item?.id === deliveredItemId && complaint.ncr_handoff?.status === 'generated' && !!complaint.ncr?.id, complaint);
  const fields = [
    'Sales, Quality, and Customer Service investigated this customer report.',
    'One delivered part was reported with a possible manufacturing defect.',
    'Customer Service acknowledged the report and Quality isolated the affected complaint record.',
    'Review found one isolated molding defect in the reported piece.',
    'The reported piece was reworked and documented against the complaint NCR.',
    'Quality verified the documented corrective action and closed the linked NCR.',
    'Production will inspect mold alignment and first-off parts at startup.',
    'Customer Service and Quality completed the investigation and recorded the outcome.',
  ];
  if (!complaint.eight_d_report?.finalized_at) {
    await h.open(service, `/crm/complaints/${state.complaint}`);
    await service.getByRole('tab', { name: '8D report', exact: true }).click();
    h.check('8D report presents all eight investigation fields', await service.locator('textarea').count() === fields.length);
    for (const [index, value] of fields.entries()) {
      const field = service.locator('textarea').nth(index);
      await field.fill(value);
      const saved = service.waitForResponse(r => r.request().method() === 'PATCH' && new URL(r.url()).pathname === `/api/v1/crm/complaints/${state.complaint}/8d`);
      await field.press('Tab');
      response = await saved;
      h.check(`8D D${index + 1} is saved before finalization`, response.status() === 200, await response.json());
    }
    await h.pageButton(service, 'Finalise 8D');
    const finalized = service.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/complaints/${state.complaint}/8d/finalize`));
    await h.modalButton(service, 'Finalise');
    response = await finalized;
    h.check('Customer complaint 8D report finalizes after all fields are recorded', response.status() === 200, await response.json());
    complaint = await read(service, `/crm/complaints/${state.complaint}`);
  }

  const qc = await actor('complaint_qc', 'qc@ogami.test');
  complaint = await read(service, `/crm/complaints/${state.complaint}`);
  const ncrId = complaint.ncr.id;
  let ncr = await read(qc, `/quality/ncrs/${ncrId}`);
  await h.open(qc, `/quality/ncrs/${ncrId}`);
  if (!ncr.disposition) {
    await qc.getByLabel('Disposition', { exact: true }).selectOption('rework');
    await qc.getByLabel('Root cause', { exact: true }).fill('One reported part requires a documented corrective action.');
    await qc.getByLabel('Corrective action', { exact: true }).fill('Rework and verify the reported part under this NCR.');
    const disposition = qc.waitForResponse(r => r.request().method() === 'PATCH' && r.url().endsWith(`/ncrs/${ncrId}/disposition`));
    await h.pageButton(qc, 'Save disposition');
    response = await disposition;
    h.check('Quality records a disposition and corrective action', response.status() === 200, await response.json());
    ncr = await read(qc, `/quality/ncrs/${ncrId}`);
  }
  for (const [type, description] of [
    ['corrective', 'Reworked the complaint-reported piece and verified the corrective outcome.'],
    ['preventive', 'Inspect mold alignment and first-off parts at each production startup.'],
  ]) {
    if (ncr.actions.some(action => action.action_type === type)) continue;
    await qc.getByLabel('Type', { exact: true }).selectOption(type);
    await qc.getByPlaceholder('Describe what was done or what needs to happen.', { exact: true }).fill(description);
    const added = qc.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/ncrs/${ncrId}/actions`));
    await h.pageButton(qc, 'Add action');
    response = await added;
    h.check(`Quality records ${type} action on complaint NCR`, response.status() === 201, await response.json());
    ncr = await read(qc, `/quality/ncrs/${ncrId}`);
  }
  if (ncr.status !== 'closed') {
    const closedNcr = qc.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/ncrs/${ncrId}/close`));
    await h.pageButton(qc, 'Close NCR');
    await h.modalButton(qc, 'Close NCR');
    response = await closedNcr;
    h.check('Complaint NCR closes only after disposition and corrective/preventive actions', response.status() === 200, await response.json());
  }

  complaint = await read(service, `/crm/complaints/${state.complaint}`);
  h.check('Complaint has finalized 8D report and a closed NCR before resolution', !!complaint.eight_d_report?.finalized_at && complaint.ncr.status === 'closed');
  complaint = await read(service, `/crm/complaints/${state.complaint}`);
  if (complaint.status === 'open') {
    await h.open(service, `/crm/complaints/${state.complaint}`);
    await h.pageButton(service, 'Resolve');
    const resolved = service.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/complaints/${state.complaint}/resolve`));
    await h.modalButton(service, 'Resolve');
    response = await resolved;
    h.check('Customer complaint resolves after quality completion', response.status() === 200, await response.json());
    complaint = await read(service, `/crm/complaints/${state.complaint}`);
  }
  if (complaint.status !== 'closed') {
    await h.open(service, `/crm/complaints/${state.complaint}`);
    await h.pageButton(service, 'Close');
    const closed = service.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/complaints/${state.complaint}/close`));
    await h.modalButton(service, 'Close');
    response = await closed;
    h.check('Customer complaint reaches closed terminal state', response.status() === 200, await response.json());
  }

  const portalComplaints = await read(customer, '/b2b/customer/complaints?per_page=100');
  const portalComplaint = portalComplaints.find(item => item.id === state.complaint);
  h.check('Customer can see closed complaint and linked delivery after resolution', portalComplaint?.status === 'closed' && portalComplaint.delivered_item?.delivery_number && portalComplaint.warranty_advisory?.duration_days === 30 && portalComplaint.warranty_advisory?.report_window_days === 7, portalComplaint);
  await h.shot(customer, '/portal/customer/complaints', '18-customer-warranty-complaint-closed');
}

async function alternateOrderTerms() {
  const sales = await actor('sales', 'crm@ogami.test');
  const customer = await actor('customer', fixture.customer_email, true);
  const updateCustomerTerms = async ({ payer, startsOn, endsOn, methods }) => {
    await h.open(sales, `/crm/customers/${fixture.customer}/edit`);
    await sales.getByLabel('Shipping paid by', { exact: true }).selectOption(payer);
    if (startsOn) await sales.getByLabel('Agreement starts', { exact: true }).fill(startsOn);
    if (endsOn) await sales.getByLabel('Agreement ends', { exact: true }).fill(endsOn);
    for (const [method, label] of [['cash', 'Cash'], ['check', 'Check'], ['bank_transfer', 'Bank transfer']]) {
      const checkbox = sales.getByLabel(label, { exact: true });
      if (methods.includes(method)) await checkbox.check();
      else await checkbox.uncheck();
    }
    const saved = sales.waitForResponse(r => r.request().method() === 'PUT' && new URL(r.url()).pathname === `/api/v1/crm/customers/${fixture.customer}`);
    await h.pageButton(sales, 'Save changes');
    const response = await saved;
    h.check(`CRM updates customer terms for ${payer} freight scenario`, response.status() === 200, await response.json());
  };
  const createAndCancel = async (method, expectedStatus, expectedPayer) => {
    await h.open(customer, '/portal/customer/orders/new');
    const paymentMethod = customer.getByRole('combobox', { name: /Payment method/ });
    await paymentMethod.locator(`option[value="${method}"]`).waitFor({ state: 'attached', timeout: 10_000 });
    const methods = await paymentMethod.locator('option').evaluateAll(options => options.map(option => option.value).filter(Boolean));
    h.check(`Portal restricts choices to the configured ${method} method`, methods.join(',') === method, methods);
    await paymentMethod.selectOption(method);
    await customer.getByLabel('Requested delivery date', { exact: true }).fill(new Date(Date.now() + 8 * 3600e3 + 21 * 864e5).toISOString().slice(0, 10));
    await h.pageButton(customer, 'Add products');
    await customer.getByRole('button', { name: `Add O2C wiper bushing to order`, exact: true }).click();
    await customer.getByLabel(`Quantity for ${fixture.product_code}`, { exact: true }).fill('1');
    await customer.getByTestId('order-total').filter({ hasText: '14.00' }).waitFor();
    const submitted = customer.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/api/v1/b2b/customer/orders');
    await h.pageButton(customer, 'Submit order for review');
    let response = await submitted;
    const payload = await response.json();
    h.check(`${method} sales order submits for review`, response.status() === 201, payload);
    const orderId = payload.data.id;
    let order = await read(customer, `/b2b/customer/orders/${orderId}`);
    h.check(`${method} order snapshots its method and current freight terms`, order.payment_method === method && order.customer_terms_snapshot?.agreement_status === expectedStatus && order.customer_terms_snapshot?.shipping_payer === expectedPayer, order.customer_terms_snapshot);
    await h.open(customer, `/portal/customer/orders/${orderId}`);
    if (expectedPayer === 'company' && expectedStatus === 'in_effect') {
      h.check('Company-paid freight is excluded from the customer product total', await customer.getByText(/Ogami is responsible for freight.*not part of this product total/i).isVisible());
    }
    if (expectedStatus === 'expired') {
      h.check('Expired agreement warns both parties to reconfirm freight before final charges', await customer.getByText(/saved customer agreement was not in effect.*Freight is excluded from this total/i).isVisible());
    }
    await h.pageButton(customer, 'Cancel order');
    await customer.getByLabel('Reason (optional)', { exact: true }).fill(`Completed cancellation branch for ${method} terms coverage.`);
    const cancelled = customer.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/orders/${orderId}/cancel`));
    await h.modalButton(customer, 'Cancel order');
    response = await cancelled;
    h.check(`${method} order reaches customer-cancelled terminal state`, response.status() === 200, await response.json());
    order = await read(customer, `/b2b/customer/orders/${orderId}`);
    h.check(`${method} cancelled order creates no production order`, order.status === 'cancelled' && h.sql('select count(*) from work_orders') === '1', order.status);
  };

  await updateCustomerTerms({ payer: 'company', methods: ['check'] });
  await createAndCancel('check', 'in_effect', 'company');
  const expiredOn = new Date(Date.now() + 8 * 3600e3 - 2 * 864e5).toISOString().slice(0, 10);
  await updateCustomerTerms({ payer: 'customer', endsOn: expiredOn, methods: ['cash'] });
  await createAndCancel('cash', 'expired', 'customer');
  const futureStart = new Date(Date.now() + 8 * 3600e3 + 14 * 864e5).toISOString().slice(0, 10);
  const futureEnd = new Date(Date.now() + 8 * 3600e3 + 365 * 864e5).toISOString().slice(0, 10);
  await updateCustomerTerms({ payer: 'company', startsOn: futureStart, endsOn: futureEnd, methods: ['bank_transfer'] });
  await createAndCancel('bank_transfer', 'not_yet_effective', 'company');
  const original = await read(sales, `/crm/sales-orders/${state.so}`);
  h.check('Later customer edits do not rewrite the completed order’s original terms snapshot', original.customer_terms_snapshot?.agreement_status === 'in_effect' && original.customer_terms_snapshot?.shipping_payer === 'customer' && original.payment_method === 'bank_transfer', original.customer_terms_snapshot);
}

(async () => {
  let server;
  try {
    server = await h.setUp();
    if (!h.fixture.workers) {
      const seeded = h.artisan('tinker', '--execute', "require 'tests/Browser/b2b_crew_prerequisites.php';");
      if (!seeded.includes('B2B workers ready.')) throw new Error(seeded);
    }
    h.compose(['cp', `api:${h.env.O2C_FIXTURE_PATH}`, path.join(h.OUT, 'fixture.json')]);
    fixture = JSON.parse(fs.readFileSync(path.join(h.OUT, 'fixture.json'), 'utf8'));
    await stage('0. Configure customer agreement and warranty defaults', customerTerms);
    await stage('1. Customer submission and sales confirmation', salesOrder);
    await stage('2. PPC crew, equipment and material reservation', confirmation);
    await stage('3. Production start, material movement and handover', startProduction);
    await stage('4. QC workflow gate', processQuality);
    await stage('5. Assigned employee output recording', productionOutput);
    await stage('6. Outgoing QC failure and automatic NCR', failOutgoing);
    await stage('7. Full-batch sorting and inline repair decision', sorting);
    await stage('8. Same-WO one-piece repair', repair);
    await stage('9. Fresh repair QC, NCR closure and automatic WO closure', repairQuality);
    await stage('10. Approved lot dispatch, driver proof and customer receipt', deliveryAndReceipt);
    await stage('11. Invoice finalization, collection and SO closure', billingAndPayment);
    await stage('12. Supplier purchase request and approval', supplierRequest);
    await stage('13. Purchase order approval, supplier acceptance and automatic GRN', supplierOrder);
    await stage('14. Supplier receiving and one-piece QC failure', incomingFailure);
    await stage('15. Supplier full-batch sorting and automatic one-piece return', supplierSorting);
    await stage('16. Supplier quality return closeout and replacement obligation', supplierReturn);
    await stage('17. Replacement receiving, fresh QC and stock reconciliation', supplierReplacement);
    await stage('18. Customer warranty complaint, 8D and NCR closure', customerWarrantyComplaint);
    await stage('19. Restricted methods, freight responsibility, expired/future agreements and cancellation branches', alternateOrderTerms);
  } catch (error) {
    if (!error.auditCheck) h.report.failures.push({ name: 'Run stopped', evidence: error.stack });
    if (checkpoints.at(-1)?.status === 'running') Object.assign(checkpoints.at(-1), { status: 'failed', error: error.message });
    console.error(error.stack);
    for (const [key, page] of Object.entries(h.who)) await page.screenshot({ path: path.join(h.OUT, `failure-${key}.png`), fullPage: true }).catch(() => {});
    process.exitCode = 1;
  } finally {
    persist();
    await h.closeBrowser();
    await h.tearDown(server);
    console.log(`Evidence: ${h.OUT}/sequential-report.json`);
  }
})();
