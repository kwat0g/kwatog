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
const persist = () => fs.writeFileSync(path.join(h.OUT, 'sequential-report.json'), JSON.stringify({
  ...h.report, checkpoints, state,
}, null, 2));

async function stage(name, action) {
  if (checkpoints.some(checkpoint => checkpoint.name === name && checkpoint.status === 'passed')) {
    console.log(`CHECKPOINT ALREADY PASSED: ${name}`);
    return;
  }
  checkpoints.push({ name, status: 'running', started_at: new Date().toISOString() });
  persist();
  await action();
  h.assertCleanStep(name);
  h.check(`${name}: no browser exceptions or server failures`, h.report.page_errors.length === 0 && h.report.http_errors.length === 0, h.report.page_errors);
  Object.assign(checkpoints.at(-1), { status: 'passed', finished_at: new Date().toISOString() });
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
  const customer = await actor('customer', fixture.customer_email, true);
  await h.open(customer, '/portal/customer/orders/new');
  h.check('Requested delivery date starts empty', await customer.getByLabel('Requested delivery date', { exact: true }).inputValue() === '');
  h.check('Order date is fixed', await customer.getByLabel('Order date', { exact: true }).getAttribute('readonly') !== null);
  state.delivery_date = new Date(Date.now() + 8 * 3600e3 + 14 * 864e5).toISOString().slice(0, 10);
  await customer.getByLabel('Requested delivery date', { exact: true }).fill(state.delivery_date);
  await h.pageButton(customer, 'Add products');
  await customer.getByRole('button', { name: 'Add O2C wiper bushing to order', exact: true }).click();
  await customer.getByLabel(`Quantity for ${fixture.product_code}`, { exact: true }).fill('100');
  await customer.getByTestId('order-total').filter({ hasText: '1,400.00' }).waitFor();
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

  const sales = await actor('sales', 'crm@ogami.test');
  await h.open(sales, `/crm/sales-orders/${state.so}`);
  const before = await read(sales, `/crm/sales-orders/${state.so}`);
  h.check('Sales cannot edit customer order', !before.is_editable && !before.capabilities.can_edit);
  h.check('Requested date survives portal handoff', before.requested_delivery_dates[0] === state.delivery_date, before.requested_delivery_dates);
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
  h.check('Customer changes lock after sales confirmation', !portalAfter.capabilities.can_edit && !portalAfter.capabilities.can_cancel);
  await h.shot(sales, `/crm/sales-orders/${state.so}`, '01-sales-confirmed');
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
    await h.modalButton(ppc, 'Apply assignment and confirm');
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
  h.check('Confirmed slot still blocks starting early', Date.parse(crew.schedule.scheduled_start) > Date.now());
  state.original_schedule = crew.schedule;
  await h.shot(ppc, `/production/work-orders/${state.wo}`, '02-wo-confirmed');
}

async function startProduction() {
  const manager = await actor('manager', 'production@ogami.test');
  await h.open(manager, `/production/work-orders/${state.wo}`);
  let wo = await read(manager, `/production/work-orders/${state.wo}`);
  if (wo.status === 'confirmed') {
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
    const startWait = manager.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/work-orders/${state.wo}/start`));
    await h.pageButton(manager, 'Start');
    await h.modalButton(manager, 'Start');
    const started = await startWait;
    h.check('WO starts inside authorized slot with present crew', started.status() === 200, await started.json());
  }
  wo = await read(manager, `/production/work-orders/${state.wo}`);
  h.check('Started WO has exact automatic material issue', wo.status === 'in_progress' && h.sql("select sum(quantity) from stock_movements where movement_type='material_issue'") === '2.000');
  h.check('Warehouse material quantity reconciles', h.sql("select sum(quantity) from stock_levels where item_id=(select id from items where code like 'RM-%')") === '498.000');
  h.check('In-process QC created and blocks output', wo.inspections.some(inspection => inspection.stage === 'in_process' && inspection.status === 'draft') && !!wo.process_quality_blocker, wo.process_quality_blocker);
  state.process_inspection = wo.inspections.find(inspection => inspection.stage === 'in_process').id;
  const warehouse = await actor('warehouse', 'warehouse@ogami.test');
  await h.open(warehouse, '/inventory/stock-levels?view=movements');
  h.check('Movement reference shows actual WO number', (await warehouse.locator('body').innerText()).includes(state.wo_number));
  await warehouse.getByRole('row').filter({ hasText: state.wo_number }).getByRole('button').first().click();
  await warehouse.getByRole('button', { name: /Record handover/ }).first().click();
  const handoverWait = warehouse.waitForResponse(r => r.request().method() === 'POST' && /\/handover$/.test(r.url()));
  await h.modalButton(warehouse, 'Confirm handover');
  const handover = await handoverWait;
  h.check('Warehouse records actual material handover', handover.status() === 200, await handover.json());
  h.check('Handover does not issue stock twice', h.sql("select sum(quantity) from stock_movements where movement_type='material_issue'") === '2.000');
  await h.shot(manager, `/production/work-orders/${state.wo}`, '03-started-awaiting-process-qc');
}

async function inspect(id, defective = false) {
  const qc = await actor('qc', 'qc@ogami.test');
  let inspection = await read(qc, `/quality/inspections/${id}`);
  if (['draft', 'in_progress'].includes(inspection.status)) {
    await h.open(qc, `/quality/inspections/${id}`);
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
    await qc.getByRole('checkbox', { name: /I confirm (every piece|all required checks)/ }).check();
    const submitWait = qc.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/inspections/${id}/batch-result`));
    await h.pageButton(qc, 'Submit inspection');
    const submitted = await submitWait;
    h.check('Checklist submits without entering measurements', submitted.status() === 200, await submitted.json());
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
  await inspect(state.process_inspection);
  const manager = await actor('manager', 'production@ogami.test');
  const wo = await read(manager, `/production/work-orders/${state.wo}`);
  h.check('In-process QC gate released', !wo.process_quality_blocker, wo.process_quality_blocker);
}

async function productionOutput() {
  const worker = await actor('worker', state.recorder.email);
  const manager = await actor('manager', 'production@ogami.test');
  let wo = await read(manager, `/production/work-orders/${state.wo}`);
  if (wo.quantity_produced === 0) {
    await h.open(worker, `/self-service/work-orders/${state.wo}`);
    await worker.getByLabel('Good count', { exact: true }).fill('100');
    const outputWait = worker.waitForResponse(r => r.request().method() === 'POST' && /\/outputs$/.test(new URL(r.url()).pathname));
    await h.pageButton(worker, 'Record output');
    const output = await outputWait;
    h.check('Assigned employee records actual production', output.status() === 200, await output.json());
    wo = await read(manager, `/production/work-orders/${state.wo}`);
  }
  h.check('100 pieces produced; outgoing QC remains open', wo.quantity_good === 100 && wo.quantity_produced === 100 && wo.status !== 'closed', wo.status);
  h.check('One output only, attributed to assigned recorder', h.sql("select count(*) from work_order_outputs") === '1' && h.sql("select u.email from work_order_outputs o join users u on u.id=o.recorded_by") === state.recorder.email);
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
  h.check('One defective piece fails entire QC verdict', inspection.sample_defect_count === 1 && inspection.status === 'failed', inspection.sample_defect_count);
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
  const maker = await read(qc, '/auth/user');
  await h.open(checker, `/quality/ncrs/${state.ncr}`);
  let ncr = await read(checker, `/quality/ncrs/${state.ncr}`);
  if (!ncr.mrb_decided_at) {
    await checker.getByLabel('Disposition', { exact: true }).selectOption('rework');
    await checker.getByLabel('Verified good quantity after sorting', { exact: true }).fill('99');
    await checker.getByLabel('Quantity inspected after full-batch sorting').fill('100');
    await checker.getByLabel('Sorting / reinspection evidence reference').fill(`SORT-${h.RUN}: 100 checked, 99 compliant, one isolated flash defect.`);
    await checker.getByLabel('Sorting and reinspection performed by').selectOption(maker.id);
    await checker.getByRole('checkbox', { name: 'Remaining good pieces were segregated, reinspected and passed all requirements.' }).check();
    await checker.getByLabel('Root cause', { exact: true }).fill('One molded piece has flash.');
    await checker.getByLabel('Corrective action', { exact: true }).fill('Segregate 99 good pieces; trim and reinspect the single defective piece.');
    const saveWait = checker.waitForResponse(r => r.request().method() === 'PATCH' && r.url().endsWith(`/ncrs/${state.ncr}/disposition`));
    await h.pageButton(checker, 'Save disposition');
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
    await h.pageButton(manager, 'Review production slot');
    const dialog = manager.getByRole('dialog');
    await dialog.getByLabel('Reviewed production start').fill(new Date(Date.now() + 8 * 3600e3 - 60000).toISOString().slice(0, 16));
    await dialog.getByLabel('Reviewed production end').fill(new Date(Date.now() + 8 * 3600e3 + 3600e3).toISOString().slice(0, 16));
    await dialog.getByLabel('Scheduling reason').fill('Trim the single isolated defective piece during the present crew shift.');
    const reviewed = manager.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/rework/${state.ncr}/review-slot`));
    await h.modalButton(manager, 'Approve production slot');
    const response = await reviewed;
    h.check('Manual repair slot reviewed without occupying equipment', response.status() === 200, await response.json());
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
    const started = manager.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/rework/${state.ncr}/start`));
    await h.pageButton(manager, 'Start rework');
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
    const finished = manager.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/rework/${state.ncr}/finish`));
    await h.pageButton(manager, 'Finish rework');
    await h.modalButton(manager, 'Finish rework');
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
    if (delivery.status === 'scheduled' && !delivery.driver) {
      await h.open(dispatcher, `/supply-chain/deliveries/${entry.id}`);
      await h.pageButton(dispatcher, 'Assign driver & vehicle');
      const dialog = dispatcher.getByRole('dialog');
      await dialog.getByLabel('Vehicle').selectOption(fixture.vehicle);
      await dialog.getByLabel('Driver').selectOption({ index: 1 });
      await dialog.getByLabel('Assignment reason').fill('Deliver independently approved production lots to the order customer.');
      const assigned = dispatcher.waitForResponse(r => r.request().method() === 'PATCH' && r.url().endsWith(`/deliveries/${entry.id}/assignment`));
      await h.modalButton(dispatcher, 'Save assignment');
      const response = await assigned;
      h.check('Dispatch assigns available van and active driver', response.status() === 200, await response.json());
      delivery = await read(dispatcher, `/supply-chain/deliveries/${entry.id}`);
    }
    if (delivery.status === 'scheduled') {
      await h.open(dispatcher, `/supply-chain/deliveries/${entry.id}`);
      if (delivery.can_reserve_stock) {
        const reserved = dispatcher.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/deliveries/${entry.id}/reserve-stock`));
        await h.pageButton(dispatcher, 'Reserve shipment stock');
        const response = await reserved;
        h.check('Shipment reserves exact approved physical lot', response.status() === 200, await response.json());
      }
      const loaded = dispatcher.waitForResponse(r => r.request().method() === 'PATCH' && r.url().endsWith(`/deliveries/${entry.id}/status`));
      await h.pageButton(dispatcher, /^Mark Loading$/i);
      const response = await loaded;
      h.check('Warehouse-approved shipment advances to loading', response.status() === 200, await response.json());
    }
    delivery = await read(driver, `/driver/deliveries/${entry.id}`);
    for (const [from, next] of [['loading', 'in_transit'], ['in_transit', 'delivered']]) {
      if (delivery.status !== from) continue;
      await h.open(driver, `/driver/${entry.id}`);
      const label = `Mark ${delivery.next_status_label}`;
      const advanced = driver.waitForResponse(r => r.request().method() === 'PATCH' && r.url().endsWith(`/driver/deliveries/${entry.id}/status`));
      await h.pageButton(driver, label);
      await h.modalButton(driver, label);
      const response = await advanced;
      h.check(`Assigned driver advances shipment to ${next}`, response.status() === 200, await response.json());
      delivery = await read(driver, `/driver/deliveries/${entry.id}`);
      h.check('Driver status persists before next transition', delivery.status === next, delivery.status);
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
      await h.open(finance, `/accounting/invoices/${entry.id}`);
      await h.pageButton(finance, 'Record collection');
      const dialog = finance.getByRole('dialog');
      await dialog.getByLabel('Cash account').selectOption(fixture.cash_account);
      await dialog.getByLabel(/^Amount \(max/).fill(invoice.balance);
      await dialog.getByLabel('Method').selectOption('bank_transfer');
      await dialog.getByLabel('Reference no.').fill(`B2B-${h.RUN}-${entry.id}`);
      const collected = finance.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/invoices/${entry.id}/collections`));
      await h.modalButton(finance, 'Record');
      const response = await collected;
      h.check('Finance records exact remaining customer payment', response.status() === 201, await response.json());
    }
    invoice = await read(finance, `/invoices/${entry.id}`);
    h.check('Invoice balance settles to zero', invoice.status === 'paid' && Number(invoice.balance) === 0, invoice.status);
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

async function incomingFailure() {
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
    await checker.getByLabel('Description', { exact: true }).fill(description);
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
    await h.pageButton(purchasing, 'Submit for Approval');
    await h.modalButton(purchasing, 'Submit');
    const response = await submitted;
    h.check('Supplier return enters approval workflow', response.status() === 200, await response.json());
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
  state.replacement_po = rma.replacement_purchase_order?.id;
  h.check('One replacement PO linked to original supplier return', !!state.replacement_po && h.sql('select count(*) from purchase_orders') === '2', rma);
  const replacement = await read(purchasing, `/purchasing/purchase-orders/${state.replacement_po}`);
  h.check('Replacement order covers one piece at original price', replacement.items.length === 1 && Number(replacement.items[0].quantity) === 1 && Number(replacement.items[0].unit_price) === 10, replacement.items);
  h.check('Rejected component creates no bill credit or double stock issue', !rma.credit_note && h.sql(`select sum(quantity) from stock_levels where item_id=(select id from items where code='${fixture.supplier.item_code}')`) === '99.000');
  const original = await read(purchasing, `/purchasing/purchase-orders/${state.po}`);
  h.check('Original PO short-closes without duplicating owed replacement', original.status === 'closed' && !!original.short_closed_at, original);
  if (rma.status !== 'completed') {
    await h.open(purchasing, `/return-management/${id}`);
    const completed = purchasing.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`${route}/complete`));
    await h.pageButton(purchasing, 'Complete RMA');
    await h.modalButton(purchasing, 'Confirm Complete');
    const response = await completed;
    h.check('Supplier return completes without double issue or credit', response.status() === 200, await response.json());
  }
  await h.shot(purchasing, `/return-management/${id}`, '16-supplier-return-completed');
}

async function supplierReplacement() {
  await sendPurchaseOrder(state.replacement_po);
  const warehouse = await actor('warehouse', 'warehouse@ogami.test');
  const receipts = await read(warehouse, '/inventory/grn');
  state.replacement_grn = receipts.find(receipt => receipt.purchase_order?.id === state.replacement_po)?.id;
  h.check('Replacement PO automatically creates separate GRN', !!state.replacement_grn && state.replacement_grn !== state.grn);
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
    await stage('1. Customer submission and sales confirmation', salesOrder);
    await stage('2. PPC crew, equipment and material reservation', confirmation);
    await stage('3. Production start, material movement and handover', startProduction);
    await stage('4. In-process QC release', processQuality);
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
    await stage('16. Supplier return approval, completion and replacement PO', supplierReturn);
    await stage('17. Replacement receiving, fresh QC and stock reconciliation', supplierReplacement);
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
