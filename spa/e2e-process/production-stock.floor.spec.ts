import { test, expect } from './fixtures';
import { actorPage, loadActors } from './actor-session';
import type { E2EActor } from './actor-session';
import { createJourneyContext, runProcessStep } from './process-runner';
import type { JourneyContext } from './process-runner';
import { readOnlyDatabaseJson } from './helpers/database-reader';
import { freezeClock, readClock, clearClock } from './helpers/clock';

type ActorPage = Awaited<ReturnType<typeof actorPage>>;
type Material = {
  item_id: number;
  code: string;
  name: string;
  unit_of_measure: string;
  standard_cost: string;
  safety_stock: string;
  quantity_per_unit: string;
  waste_factor: string;
};
type WorkOrderRow = {
  id: number;
  wo_number: string;
  status: string;
  quantity_target: number;
  quantity_produced: number;
  quantity_good: number;
  quantity_rejected: number;
  purpose: string;
  part_number: string;
  creator_email: string;
  machine_code: string | null;
  mold_code: string | null;
};
type InspectionRow = {
  id: number;
  inspection_number: string;
  stage: string;
  status: string;
  sample_size: number;
  batch_quantity: number;
  inspection_mode: string;
  capture_method: string;
  output_id: number | null;
  inspector_email: string | null;
  reviewer_email: string | null;
  recorded_result: string | null;
  accepted_quantity: number;
};

const journeyId = 'PROD01-HAPPY';
const runActors = ['hr_officer', 'warehouse_staff', 'ppc_head', 'production_manager', 'qc_inspector', 'employee'];

function actorByName(actors: E2EActor[], actor: string): E2EActor {
  const match = actors.find((candidate) => candidate.actor === actor);
  if (!match) throw new Error(`Required seeded E2E actor is missing: ${actor}`);
  return match;
}

function sqlText(value: string): string {
  return `'${value.replaceAll("'", "''")}'`;
}

function contextValue<T>(context: JourneyContext, key: string): T {
  const value = context.values[key];
  if (value === undefined) throw new Error(`Journey context is missing ${key}.`);
  return value as T;
}

function currentInstant(): string {
  const instant = new Date();
  instant.setUTCSeconds(0, 0);
  return instant.toISOString();
}

function shiftPlan(instant: string): { name: string; effectiveFrom: string } {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).formatToParts(new Date(instant));
  const part = (type: string) => parts.find((entry) => entry.type === type)?.value;
  const year = part('year');
  const month = part('month');
  const day = part('day');
  const hour = Number(part('hour'));
  if (!year || !month || !day || !Number.isInteger(hour)) {
    throw new Error('Could not derive the Manila shift date from the E2E instant.');
  }
  const today = `${year}-${month}-${day}`;
  if (hour >= 6 && hour < 18) return { name: 'Extended Day', effectiveFrom: today };
  if (hour >= 18) return { name: 'Night Shift', effectiveFrom: today };
  const previousDay = new Date(Date.parse(`${today}T00:00:00+08:00`) - 86_400_000);
  return {
    name: 'Night Shift',
    effectiveFrom: new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
    }).format(previousDay),
  };
}

async function openSession(session: ActorPage): Promise<void> {
  await session.page.goto('/sign-in');
  await expect(session.page).toHaveURL(/\/dashboard(?:\/|$)/);
}

async function openMenuItem(
  page: ActorPage['page'], section: string, label: string, href: string,
): Promise<void> {
  const target = page.getByRole('navigation', { name: 'Modules' }).locator(`a[href="${href}"]`);
  await expect(target, `sidebar should contain its ${label} link`).toHaveCount(1);
  if (!(await target.isVisible())) {
    const toggle = page.locator(`[data-module="${section}"]`).getByRole('button');
    if (await toggle.count()) {
      await expect(toggle).toBeVisible();
      if ((await toggle.getAttribute('aria-expanded')) !== 'true') await toggle.click();
    }
  }
  await expect(target).toBeVisible();
  await target.click();
}

async function openNotification(session: ActorPage, needle: string): Promise<void> {
  const page = session.page;
  await page.getByRole('button', { name: /^Notifications(?:, \d+ unread)?$/ }).click();
  const tray = page.getByRole('dialog', { name: 'Notifications' });
  await expect(tray).toBeVisible();
  const handoff = tray.getByRole('link').filter({ hasText: needle });
  await expect(handoff, `the real notification hand-off should identify ${needle}`).toHaveCount(1);
  await handoff.click();
}

async function readWorkOrder(context: JourneyContext): Promise<WorkOrderRow> {
  const number = contextValue<string>(context, 'woNumber');
  const rows = await readOnlyDatabaseJson<WorkOrderRow[]>(`
    SELECT wo.id, wo.wo_number, wo.status, wo.quantity_target, wo.quantity_produced,
           wo.quantity_good, wo.quantity_rejected, wo.production_purpose AS purpose,
           product.part_number, creator.email AS creator_email,
           machine.machine_code, mold.mold_code
    FROM work_orders wo
    JOIN products product ON product.id = wo.product_id
    JOIN users creator ON creator.id = wo.created_by
    LEFT JOIN machines machine ON machine.id = wo.machine_id
    LEFT JOIN molds mold ON mold.id = wo.mold_id
    WHERE wo.wo_number = ${sqlText(number)}
  `);
  expect(rows, `one stock work order should exist for ${number}`).toHaveLength(1);
  return rows[0];
}

async function readWorkOrderId(context: JourneyContext): Promise<number> {
  return (await readWorkOrder(context)).id;
}

async function readInspectionRows(context: JourneyContext): Promise<InspectionRow[]> {
  const id = await readWorkOrderId(context);
  return readOnlyDatabaseJson<InspectionRow[]>(`
    SELECT inspection.id, inspection.inspection_number, inspection.stage,
           inspection.status, inspection.sample_size, inspection.batch_quantity,
           inspection.inspection_mode, inspection.capture_method,
           inspection.work_order_output_id AS output_id,
           inspector.email AS inspector_email, reviewer.email AS reviewer_email,
           inspection.recorded_result, inspection.accepted_quantity
    FROM inspections inspection
    LEFT JOIN users inspector ON inspector.id = inspection.inspector_id
    LEFT JOIN users reviewer ON reviewer.id = inspection.reviewed_by
    WHERE inspection.entity_type = 'work_order' AND inspection.entity_id = ${id}
    ORDER BY inspection.id
  `);
}

async function assertWorkOrderState(
  context: JourneyContext,
  expected: Partial<WorkOrderRow>,
): Promise<WorkOrderRow> {
  const row = await readWorkOrder(context);
  expect(row).toMatchObject(expected);
  return row;
}

async function expectedConfiguredRecipients(
  key: string,
  actors: E2EActor[],
): Promise<string[]> {
  const rows = await readOnlyDatabaseJson<Array<{ email: string }>>(`
    SELECT DISTINCT recipient.email
    FROM settings setting
    CROSS JOIN LATERAL jsonb_array_elements_text(setting.value) configured_role(slug)
    JOIN roles role ON role.slug = configured_role.slug
    JOIN role_permissions role_permission ON role_permission.role_id = role.id
    JOIN permissions permission ON permission.id = role_permission.permission_id
      AND permission.slug = 'quality.inspections.view'
    JOIN users recipient ON recipient.role_id = role.id AND recipient.is_active = true
    WHERE setting.key = ${sqlText(key)}
    ORDER BY recipient.email
  `);
  const expected = rows.map((row) => row.email.toLowerCase()).sort();
  const seeded = actors.filter((actor) => actor.realm === 'internal').map((actor) => actor.email.toLowerCase());
  expect(expected.every((email) => seeded.includes(email)), `${key} recipients should be E2E-seeded users`).toBe(true);
  return expected;
}

async function expectedReviewers(inspectionNumber: string): Promise<string[]> {
  const rows = await readOnlyDatabaseJson<Array<{ email: string }>>(`
    SELECT DISTINCT reviewer.email
    FROM inspections inspection
    JOIN users maker ON maker.id = inspection.inspector_id
    JOIN users creator ON creator.id = (
      SELECT work_order.created_by FROM work_orders work_order
      WHERE work_order.id = inspection.entity_id
    )
    JOIN users reviewer ON reviewer.is_active = true
    JOIN role_permissions review_permission ON review_permission.role_id = reviewer.role_id
    JOIN permissions review_slug ON review_slug.id = review_permission.permission_id
      AND review_slug.slug = 'quality.inspections.review'
    JOIN role_permissions view_permission ON view_permission.role_id = reviewer.role_id
    JOIN permissions view_slug ON view_slug.id = view_permission.permission_id
      AND view_slug.slug = 'quality.inspections.view'
    WHERE inspection.inspection_number = ${sqlText(inspectionNumber)}
      AND reviewer.id <> maker.id AND reviewer.id <> creator.id
      AND NOT EXISTS (
        SELECT 1 FROM inspection_result_authors author
        WHERE author.inspection_id = inspection.id AND author.user_id = reviewer.id
      )
    ORDER BY reviewer.email
  `);
  return rows.map((row) => row.email.toLowerCase()).sort();
}

async function assertNotificationRecipients(
  context: JourneyContext,
  type: string,
  expectedEmails: string[],
  inspectionNumber?: string,
): Promise<void> {
  const number = contextValue<string>(context, 'woNumber');
  await expect.poll(async () => {
    const rows = await readOnlyDatabaseJson<Array<{ email: string }>>(`
      SELECT DISTINCT recipient.email
      FROM notifications notice
      JOIN users recipient ON recipient.id = notice.notifiable_id
      WHERE notice.notifiable_type LIKE '%User'
        AND notice.type = ${sqlText(type)}
        AND (
          notice.data ->> 'wo_number' = ${sqlText(number)}
          OR notice.data ->> 'title' LIKE ${sqlText(`%${number}%`)}
          OR (${inspectionNumber ? `notice.data ->> 'title' LIKE ${sqlText(`%${inspectionNumber}%`)}` : 'false'})
        )
      ORDER BY recipient.email
    `);
    return rows.map((row) => row.email.toLowerCase()).sort();
  }, { message: `${type} should reach exactly its authorized recipients`, timeout: 15_000 }).toEqual(expectedEmails);
}

async function setPassingEvidence(session: ActorPage, sampleSize: number): Promise<void> {
  const page = session.page;
  const checklist = page.getByRole('region', { name: 'Inspection checklist' });
  await expect(checklist).toBeVisible();
  const coverage = checklist.getByRole('spinbutton', { name: 'Inspected pieces', exact: true });
  if (await coverage.count()) {
    await coverage.fill(String(sampleSize));
    await checklist.getByRole('spinbutton', { name: 'Defective pieces found', exact: true }).fill('0');
    const confirmation = checklist.getByRole('checkbox', { name: /I confirm all required checks on the planned sample were completed/ });
    if (await confirmation.count()) await confirmation.check();
  }
  const pass = checklist.getByRole('button', { name: 'PASS Visual appearance', exact: true });
  await expect(pass, 'the current WB-001 specification should present its visual criterion').toBeVisible();
  await pass.click();
  await checklist.getByRole('button', { name: 'Submit inspection', exact: true }).click();
}

async function currentStockByItem(itemIds: number[]): Promise<Array<{ item_id: number; on_hand: string; ledger_on_hand: string }>> {
  if (itemIds.length === 0) return [];
  const ids = itemIds.join(',');
  return readOnlyDatabaseJson<Array<{ item_id: number; on_hand: string; ledger_on_hand: string }>>(`
    SELECT item.id AS item_id,
           COALESCE((SELECT SUM(level.quantity) FROM stock_levels level WHERE level.item_id = item.id), 0)::numeric(18, 3)::text AS on_hand,
           (COALESCE((SELECT SUM(movement.quantity) FROM stock_movements movement
             WHERE movement.item_id = item.id AND movement.to_location_id IS NOT NULL), 0)
           - COALESCE((SELECT SUM(movement.quantity) FROM stock_movements movement
             WHERE movement.item_id = item.id AND movement.from_location_id IS NOT NULL), 0))::numeric(18, 3)::text AS ledger_on_hand
    FROM items item WHERE item.id IN (${ids}) ORDER BY item.id
  `);
}

test('PROD01-HAPPY — stock work order, Warehouse handover, startup QC, production, and outgoing release', async ({ browser, request }, testInfo) => {
  test.setTimeout(12 * 60 * 1000);
  const actors = await loadActors();
  for (const actor of runActors) actorByName(actors, actor);
  const hrIdentity = actorByName(actors, 'hr_officer');
  const warehouseIdentity = actorByName(actors, 'warehouse_staff');
  const ppcIdentity = actorByName(actors, 'ppc_head');
  const managerIdentity = actorByName(actors, 'production_manager');
  const qcIdentity = actorByName(actors, 'qc_inspector');
  const workerIdentity = actorByName(actors, 'employee');
  const workerProfileRows = await readOnlyDatabaseJson<Array<{
    employee_no: string; first_name: string; middle_name: string | null; last_name: string; suffix: string | null;
  }>>(`
    SELECT employee.employee_no, employee.first_name, employee.middle_name, employee.last_name, employee.suffix
    FROM employees employee JOIN users account ON account.employee_id = employee.id
    WHERE account.email = ${sqlText(workerIdentity.email)} AND employee.status = 'active'
  `);
  expect(workerProfileRows).toHaveLength(1);
  const workerProfileRow = workerProfileRows[0];
  const workerProfile = {
    employee_no: workerProfileRow.employee_no,
    full_name: [
      workerProfileRow.first_name,
      workerProfileRow.middle_name ? `${workerProfileRow.middle_name.slice(0, 1)}.` : null,
      workerProfileRow.last_name,
      workerProfileRow.suffix,
    ].filter(Boolean).join(' '),
  };
  const context = createJourneyContext(journeyId);
  const instant = currentInstant();
  const plannedShift = shiftPlan(instant);
  const baseline = await readOnlyDatabaseJson<{
    materials: Material[];
    sample_size: number;
    finished_item_id: number;
    setup_minutes: number;
  }[]>(`
    SELECT json_agg(json_build_object(
      'item_id', item.id, 'code', item.code, 'name', item.name,
      'unit_of_measure', item.unit_of_measure, 'standard_cost', item.standard_cost::text,
      'safety_stock', item.safety_stock::text,
      'quantity_per_unit', bom_item.quantity_per_unit::text,
      'waste_factor', bom_item.waste_factor::text
    ) ORDER BY bom_item.sort_order) AS materials,
    (SELECT COALESCE((setting.value #>> '{}')::int, 5) FROM settings setting
      WHERE setting.key = 'quality.in_process.sample_size') AS sample_size,
    (SELECT finished.id FROM items finished
      JOIN products product ON product.part_number = finished.code
      WHERE product.part_number = 'WB-001' AND finished.item_type = 'finished_good'
      ORDER BY finished.id LIMIT 1) AS finished_item_id,
    (SELECT mold.setup_time_minutes FROM product_routings routing
      JOIN routing_operations operation ON operation.routing_id = routing.id
      JOIN molds mold ON mold.id = operation.mold_id
      JOIN products product ON product.id = routing.product_id
      WHERE product.part_number = 'WB-001' AND routing.is_active = true
      ORDER BY operation.sequence LIMIT 1) AS setup_minutes
    FROM products product
    JOIN bill_of_materials bom ON bom.product_id = product.id AND bom.is_active = true
    JOIN bom_items bom_item ON bom_item.bom_id = bom.id
    JOIN items item ON item.id = bom_item.item_id
    WHERE product.part_number = 'WB-001'
  `);
  expect(baseline).toHaveLength(1);
  const fixture = baseline[0];
  expect(fixture.materials.length, 'active WB-001 BOM should have component rows').toBeGreaterThan(0);
  expect(fixture.sample_size).toBeGreaterThan(0);
  expect(fixture.finished_item_id).toBeTruthy();
  expect(fixture.setup_minutes).toBeGreaterThan(0);
  const target = fixture.sample_size + 1;
  context.values.product = 'WB-001';
  context.values.target = target;
  context.values.sampleSize = fixture.sample_size;
  context.values.shiftName = plannedShift.name;
  context.values.shiftEffectiveDate = plannedShift.effectiveFrom;
  context.values.instant = instant;

  const materials = fixture.materials.map((material) => {
    const gross = target * Number(material.quantity_per_unit) * (1 + Number(material.waste_factor) / 100)
      + Number(material.safety_stock);
    const quantity = material.unit_of_measure.toLowerCase() === 'pcs'
      ? Math.ceil(gross)
      : Math.ceil(gross * 1000) / 1000;
    return { ...material, opening_quantity: quantity };
  });
  context.values.materials = materials;
  const baselineBalances = await currentStockByItem(materials.map((material) => material.item_id));
  expect(baselineBalances.every((row) => Number(row.on_hand) === 0), 'the fresh E2E baseline should have no opening transactional stock').toBe(true);

  const plannedSteps = [
    { name: 'HR assigns Production shift coverage to the named E2E operator', actor: hrIdentity.actor, uiPath: 'Human Resources → Attendance & DTR → Shifts → Bulk assign', expectedStatus: 'shift active for the assigned Production employee' },
    ...materials.map((material) => ({ name: `Warehouse posts baseline ${material.code} stock through Stock Adjustments`, actor: warehouseIdentity.actor, uiPath: 'Warehouse → Stock Adjustments → New adjustment → Submit', expectedStatus: 'approved; stock movement posted' })),
    { name: 'PPC creates a stock production work order from the eligible WB-001 product', actor: ppcIdentity.actor, uiPath: 'Production → Production Work Orders → Produce for stock → Plan production', expectedStatus: 'planned' },
    { name: 'Production Manager finds the planned WO through the awaiting-confirmation signal, assigns the operator, and confirms its schedule', actor: managerIdentity.actor, uiPath: 'Production → Production Work Orders signals → Planned work orders → Confirm → crew and schedule', expectedStatus: 'confirmed; named operator assigned' },
    { name: 'Production Manager starts the confirmed work order after readiness and physical-presence checks', actor: managerIdentity.actor, uiPath: 'Work order detail → Start → Start production', expectedStatus: 'in_progress; startup QC required' },
    { name: 'Warehouse finds the issued materials in Material Handover and confirms receipt', actor: warehouseIdentity.actor, uiPath: 'Warehouse → Material Handover → work-order row → Confirm handover', expectedStatus: 'all issued material lines handed over' },
    { name: 'Assigned Production employee follows the crew notice and records the startup trial in My Work Orders', actor: workerIdentity.actor, uiPath: 'Notifications → assigned WO → My Work Orders → Samples ready for QC', expectedStatus: 'trial captured; awaiting QC' },
    { name: 'QC follows the in-process notification and passes the startup sample', actor: qcIdentity.actor, uiPath: 'Notifications → In-process QC required → inspection → PASS → Submit inspection', expectedStatus: 'passed; trial disposition and FG receipt settled' },
    { name: 'Production Manager records the seeded routing setup', actor: managerIdentity.actor, uiPath: 'Production Work Order → Operations → Setup → End setup', expectedStatus: 'setup recorded' },
    { name: 'Production Manager starts the routing operation after QC release', actor: managerIdentity.actor, uiPath: 'Production Work Order → Operations → Start', expectedStatus: 'in_progress' },
    { name: 'Production Manager records the remaining operation quantity', actor: managerIdentity.actor, uiPath: 'Production Work Order → Operations → Output → Record output', expectedStatus: 'routing quantity reconciles to normal output' },
    { name: 'Production Manager completes the routing operation', actor: managerIdentity.actor, uiPath: 'Production Work Order → Operations → Complete', expectedStatus: 'completed' },
    { name: 'Assigned Production employee records the remaining good output in My Work Orders', actor: workerIdentity.actor, uiPath: 'My Work Orders → assigned WO → Record output', expectedStatus: 'target good quantity and finished-goods receipt settled' },
    { name: 'Production Manager completes the WO after its production and ledger prerequisites settle', actor: managerIdentity.actor, uiPath: 'Production Work Order → Complete → Confirm', expectedStatus: 'completed; outgoing QC queued for each good batch' },
    { name: 'QC inspects the startup trial output batch and submits its passing result', actor: qcIdentity.actor, uiPath: 'Notifications → Outgoing QC required → Inspections → trial batch → PASS → Submit', expectedStatus: 'awaiting_review' },
    { name: 'Production Manager follows the checker notice and approves the trial-batch result', actor: managerIdentity.actor, uiPath: 'Notifications → inspection-awaiting-review → Approve pass', expectedStatus: 'passed; next batch still held' },
    { name: 'QC inspects the remaining output batch and submits its passing result', actor: qcIdentity.actor, uiPath: 'Quality → Inspections → remaining outgoing batch → PASS → Submit', expectedStatus: 'awaiting_review' },
    { name: 'Production Manager follows the second checker notice and releases the final batch', actor: managerIdentity.actor, uiPath: 'Notifications → inspection-awaiting-review → Approve pass', expectedStatus: 'passed; all output batches released' },
    { name: 'Production Manager verifies automatic work-order closure and the final cross-module ledger', actor: managerIdentity.actor, uiPath: 'Open Production Work Order detail', expectedStatus: 'closed; all production and quality records reconciled' },
  ];

  const sessions: ActorPage[] = [];
  const openActor = async (actor: string, tablet = false): Promise<ActorPage> => {
    const options = tablet
      ? { viewport: { width: 810, height: 1080 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2, timezoneId: 'Asia/Manila' }
      : { timezoneId: 'Asia/Manila' };
    const session = await actorPage(browser, actor, options);
    sessions.push(session);
    await openSession(session);
    await session.page.clock.setFixedTime(new Date(instant));
    return session;
  };
  let hr: ActorPage;
  let warehouse: ActorPage;
  let ppc: ActorPage;
  let manager: ActorPage;
  let qc: ActorPage;
  let worker: ActorPage;
  const checkDiagnostics = () => sessions.forEach((session) => session.diagnostics.assertClean(journeyId));
  const step = (
    index: number,
    session: ActorPage,
    action: () => Promise<void>,
    verify: () => Promise<void>,
    invariants: () => Promise<void>,
  ) => runProcessStep({
    ...plannedSteps[index], page: session.page, plannedSteps, context, testInfo,
    action, verify,
    assertInvariants: async () => { await invariants(); checkDiagnostics(); },
  });

  let clockFrozen = false;
  try {
    await freezeClock(request, instant);
    clockFrozen = true;
    expect(Date.parse(await readClock(request))).toBe(Date.parse(instant));

    hr = await openActor(hrIdentity.actor);
    await step(0, hr, async () => {
      await openMenuItem(hr.page, 'Human Resources', 'Attendance & DTR', '/hr/attendance');
      await expect(hr.page.getByRole('heading', { name: 'Daily Time Records', exact: true })).toBeVisible();
      await hr.page.getByRole('button', { name: 'Shifts', exact: true }).click();
      await expect(hr.page.getByRole('heading', { name: 'Shifts', exact: true })).toBeVisible();
      await hr.page.getByRole('button', { name: 'Bulk assign', exact: true }).click();
      await expect(hr.page.getByRole('heading', { name: 'Bulk assign shift', exact: true })).toBeVisible();
      await hr.page.getByRole('combobox', { name: 'Department', exact: true }).selectOption({ label: 'Production' });
      await hr.page.getByRole('combobox', { name: 'Shift', exact: true }).selectOption({ label: plannedShift.name });
      await hr.page.getByRole('textbox', { name: 'Effective from', exact: true }).fill(plannedShift.effectiveFrom);
      for (const day of ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']) {
        await hr.page.getByRole('checkbox', { name: day, exact: true }).check();
      }
      await hr.page.getByRole('button', { name: 'Assign shift', exact: true }).click();
      await expect(hr.page.getByText(/Assigned shift to \d+ employees\./)).toBeVisible();
    }, async () => {
      const assignments = await readOnlyDatabaseJson<Array<{ count: number; employee_email: string }>>(`
        SELECT COUNT(*)::int AS count, employee.email AS employee_email
        FROM employee_shift_assignments assignment
        JOIN employees employee ON employee.id = assignment.employee_id
        JOIN shifts shift ON shift.id = assignment.shift_id
        WHERE employee.email = ${sqlText(workerIdentity.email)} AND shift.name = ${sqlText(plannedShift.name)}
          AND assignment.effective_date = ${sqlText(plannedShift.effectiveFrom)}::date
          AND assignment.effective_date <= (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date
          AND (assignment.end_date IS NULL OR assignment.end_date >= (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date)
        GROUP BY employee.email
      `);
      expect(assignments).toEqual([{ count: 1, employee_email: workerIdentity.email }]);
      context.values.shiftAssignmentEmployee = assignments[0].employee_email;
    }, async () => {
      expect(context.values.shiftAssignmentEmployee).toBe(workerIdentity.email);
    });
    await hr.context.close();

    warehouse = await openActor(warehouseIdentity.actor);
    for (let materialIndex = 0; materialIndex < materials.length; materialIndex += 1) {
      const material = materials[materialIndex];
      const stepIndex = 1 + materialIndex;
      const reason = `${context.values.runPrefix} opening stock ${material.code}`;
      context.values[`stockReason:${material.code}`] = reason;
      await step(stepIndex, warehouse, async () => {
        await openMenuItem(warehouse.page, 'Warehouse', 'Stock Adjustments', '/inventory/stock-adjustments');
        await expect(warehouse.page.getByRole('heading', { name: 'Stock Adjustments', exact: true })).toBeVisible();
        await warehouse.page.getByRole('link', { name: 'New adjustment', exact: true }).click();
        await expect(warehouse.page.getByRole('heading', { name: 'Stock adjustment', exact: true })).toBeVisible();
        await warehouse.page.getByRole('combobox', { name: /Direction/ }).selectOption('in');
        const itemInput = warehouse.page.getByRole('combobox', { name: 'Item', exact: true });
        await itemInput.fill(material.code);
        const itemOption = warehouse.page.getByRole('option').filter({ hasText: material.code }).first();
        await expect(itemOption).toBeVisible();
        await itemOption.click();
        const location = warehouse.page.getByRole('combobox', { name: /Location/ });
        await expect(location).toBeEnabled();
        await location.click();
        const mainWarehouse = warehouse.page.getByRole('option').filter({ hasText: 'Main Warehouse' }).first();
        await expect(mainWarehouse).toBeVisible();
        const locationLabel = (await mainWarehouse.innerText()).trim();
        context.values[`location:${material.code}`] = locationLabel;
        await expect(mainWarehouse).toBeInViewport();
        const optionBounds = await mainWarehouse.evaluate((option) => {
          const bounds = option.getBoundingClientRect();
          return {
            x: bounds.x,
            y: bounds.y,
            width: bounds.width,
            height: bounds.height,
            viewportWidth: window.innerWidth,
            viewportHeight: window.innerHeight,
          };
        });
        expect(optionBounds.width, 'visible location option should have a touch target').toBeGreaterThan(0);
        expect(optionBounds.height, 'visible location option should have a touch target').toBeGreaterThan(0);
        const touchX = optionBounds.x + optionBounds.width / 2;
        const touchY = optionBounds.y + optionBounds.height / 2;
        expect(touchX).toBeGreaterThanOrEqual(0);
        expect(touchX).toBeLessThan(optionBounds.viewportWidth);
        expect(touchY).toBeGreaterThanOrEqual(0);
        expect(touchY).toBeLessThan(optionBounds.viewportHeight);
        await warehouse.page.touchscreen.tap(
          touchX,
          touchY,
        );
        await expect(location).toHaveValue(locationLabel);
        await warehouse.page.getByRole('spinbutton', { name: /Quantity/ }).fill(String(material.opening_quantity));
        await warehouse.page.getByRole('spinbutton', { name: /Unit cost/ }).fill(String(material.standard_cost));
        await warehouse.page.getByRole('combobox', { name: /Reason code/ }).selectOption('found_stock');
        await warehouse.page.getByRole('textbox', { name: /Reason \(recorded for audit\)/ }).fill(reason);
        await expect(warehouse.page.getByText('Finance approval required', { exact: true })).toHaveCount(0);
        await warehouse.page.getByRole('button', { name: 'Review adjustment', exact: true }).click();
        const confirmation = warehouse.page.getByRole('dialog');
        await expect(confirmation.getByRole('heading', { name: 'Submit stock adjustment?' })).toBeVisible();
        await confirmation.getByRole('button', { name: 'Submit adjustment', exact: true }).click();
        await expect(warehouse.page).toHaveURL('/inventory/stock-levels?view=movements');
        await openMenuItem(warehouse.page, 'Warehouse', 'Stock Adjustments', '/inventory/stock-adjustments');
        await expect(warehouse.page.getByRole('row').filter({ hasText: reason })).toContainText('Approved by');
      }, async () => {
        const rows = await readOnlyDatabaseJson<Array<{
          status: string; direction: string; quantity: string; requester: string; approver: string;
          movement_type: string; movement_count: number; on_hand: string;
        }>>(`
          SELECT adjustment.status, adjustment.direction, adjustment.quantity::text,
                 requester.email AS requester, approver.email AS approver,
                 movement.movement_type,
                 (SELECT COUNT(*)::int FROM stock_movements duplicate
                   WHERE duplicate.reference_type = 'stock_adjustment' AND duplicate.reference_id = adjustment.id) AS movement_count,
                 COALESCE((SELECT SUM(level.quantity) FROM stock_levels level WHERE level.item_id = item.id), 0)::numeric(18, 3)::text AS on_hand
          FROM stock_adjustments adjustment
          JOIN items item ON item.id = adjustment.item_id
          JOIN users requester ON requester.id = adjustment.requested_by
          LEFT JOIN users approver ON approver.id = adjustment.approved_by
          LEFT JOIN stock_movements movement ON movement.id = adjustment.stock_movement_id
          WHERE adjustment.reason = ${sqlText(reason)} AND item.id = ${material.item_id}
        `);
        expect(rows).toEqual([{
          status: 'approved', direction: 'in', quantity: material.opening_quantity.toFixed(3),
          requester: warehouseIdentity.email, approver: warehouseIdentity.email,
          movement_type: 'adjustment_in', movement_count: 1, on_hand: material.opening_quantity.toFixed(3),
        }]);
      }, async () => {
        expect(await readOnlyDatabaseJson<Array<{ count: number }>>(`
          SELECT COUNT(*)::int AS count FROM stock_adjustments WHERE reason = ${sqlText(reason)}
        `)).toEqual([{ count: 1 }]);
      });
    }

    const planStep = 1 + materials.length;
    ppc = await openActor(ppcIdentity.actor);
    await step(planStep, ppc, async () => {
      await openMenuItem(ppc.page, 'Production', 'Production Work Orders', '/production/work-orders');
      await expect(ppc.page.getByRole('heading', { name: 'Work orders', exact: true })).toBeVisible();
      await ppc.page.getByRole('button', { name: 'Produce for stock', exact: true }).click();
      await expect(ppc.page.getByRole('heading', { name: 'Produce for stock', exact: true })).toBeVisible();
      const productSelect = ppc.page.getByRole('combobox', { name: 'Product', exact: true });
      const productOption = productSelect.locator('option').filter({ hasText: 'WB-001' });
      await expect(productOption).toHaveCount(1);
      const productLabel = (await productOption.innerText()).trim();
      await productSelect.selectOption({ label: productLabel });
      await ppc.page.getByRole('spinbutton', { name: 'Quantity', exact: true }).fill(String(target));
      await ppc.page.getByRole('button', { name: 'Plan production', exact: true }).click();
      await expect(ppc.page.getByRole('heading', { level: 1 })).toContainText(/WO-/);
      const heading = await ppc.page.getByRole('heading', { level: 1 }).innerText();
      const number = heading.match(/WO-[A-Z0-9-]+/i)?.[0];
      expect(number, 'generated work-order number should appear in the detail heading').toBeTruthy();
      context.values.woNumber = number;
      context.values.woRoute = new URL(ppc.page.url()).pathname;
      await expect(ppc.page.getByRole('heading', { level: 1 })).toContainText('Planned');
    }, async () => {
      const row = await assertWorkOrderState(context, {
        status: 'planned', quantity_target: target, quantity_produced: 0, quantity_good: 0,
        quantity_rejected: 0, purpose: 'stock', part_number: 'WB-001', creator_email: ppcIdentity.email,
      });
      expect(row.machine_code).toBeNull();
      expect(row.mold_code).toBeNull();
      const materialPlan = await readOnlyDatabaseJson<Array<{ lines: number; shortage_prs: number }>>(`
        SELECT (SELECT COUNT(*)::int FROM work_order_materials material WHERE material.work_order_id = ${row.id}) AS lines,
               (SELECT COUNT(*)::int FROM purchase_requests request WHERE request.stock_production_work_order_id = ${row.id}) AS shortage_prs
      `);
      expect(materialPlan).toEqual([{ lines: materials.length, shortage_prs: 0 }]);
      expect(await readOnlyDatabaseJson<Array<{ count: number }>>(`
        SELECT COUNT(*)::int AS count FROM work_orders WHERE wo_number = ${sqlText(contextValue<string>(context, 'woNumber'))}
      `)).toEqual([{ count: 1 }]);
    }, async () => {
      expect(context.values.woRoute).toMatch(/^\/production\/work-orders\//);
      expect(context.values.woNumber).toBeTruthy();
    });

    const confirmStep = planStep + 1;
    manager = await openActor(managerIdentity.actor, true);
    await step(confirmStep, manager, async () => {
      await openMenuItem(manager.page, 'Production', 'Production Work Orders', '/production/work-orders');
      const signalButton = manager.page.getByRole('button', { name: /View all Production Work Orders signals/ });
      await expect(signalButton).toBeVisible();
      await signalButton.click();
      const signal = manager.page.getByRole('link', { name: /Planned production work orders awaiting confirmation/ });
      await expect(signal).toBeVisible();
      await signal.click();
      await expect(manager.page).toHaveURL(/\/production\/work-orders\?.*signal=release/);
      const row = manager.page.getByRole('row').filter({ hasText: contextValue<string>(context, 'woNumber') });
      await expect(row).toHaveCount(1);
      await row.click();
      await expect(manager.page.getByRole('heading', { level: 1 })).toContainText(contextValue<string>(context, 'woNumber'));
      await expect(manager.page.getByRole('button', { name: 'Confirm', exact: true })).toBeVisible();
      await manager.page.getByRole('button', { name: 'Confirm', exact: true }).click();
      const review = manager.page.getByRole('dialog', { name: 'Review and confirm work order' });
      await expect(review).toBeVisible();
      await review.getByRole('combobox', { name: 'Number of workers', exact: true }).selectOption('1');
      await expect(review.getByText('Scheduled time', { exact: true })).toBeVisible();
      const assignManually = review.getByRole('button', { name: 'Manual assign', exact: true });
      await expect(assignManually).toBeVisible();
      await assignManually.click();
      const proposedRemovers = review.getByRole('button', { name: /^Remove / });
      const proposedCount = await proposedRemovers.count();
      expect(proposedCount).toBeLessThanOrEqual(1);
      if (proposedCount === 1) await proposedRemovers.first().click();
      const workerSearch = review.getByRole('combobox', { name: 'Add worker', exact: true });
      await workerSearch.fill(workerProfile.employee_no);
      // SearchableSelect renders its popup through a document-level portal, so
      // the option is outside the review dialog's DOM subtree.
      const workerOption = manager.page.getByRole('listbox', { name: 'Add worker' }).getByRole('option').filter({ hasText: workerProfile.employee_no });
      await expect(workerOption).toHaveCount(1);
      await expect(workerOption).toBeEnabled();
      await workerOption.click();
      await review.getByRole('combobox', { name: 'Output recorder', exact: true }).selectOption({ label: workerProfile.full_name });
      const confirm = review.getByRole('button', { name: 'Confirm work order', exact: true });
      await expect(confirm).toBeEnabled();
      await confirm.click();
      await expect(manager.page.getByRole('heading', { level: 1 })).toContainText('Confirmed');
    }, async () => {
      const row = await assertWorkOrderState(context, { status: 'confirmed', quantity_target: target });
      expect(row.machine_code).toBeTruthy();
      expect(row.mold_code).toBeTruthy();
      await expect(ppc.page.getByRole('heading', { level: 1 })).toContainText('Confirmed');
      await expect(manager.page.getByRole('button', { name: 'Confirm', exact: true })).toHaveCount(0);
    }, async () => {
      expect((await readWorkOrder(context)).creator_email).toBe(ppcIdentity.email);
      const scheduled = await readOnlyDatabaseJson<Array<{ status: string; confirmed_at: string }>>(`
        SELECT schedule.status, schedule.confirmed_at::text AS confirmed_at
        FROM production_schedules schedule
        JOIN work_orders wo ON wo.id = schedule.work_order_id
        WHERE wo.wo_number = ${sqlText(contextValue<string>(context, 'woNumber'))}
          AND schedule.status = 'confirmed'
      `);
      expect(scheduled).toEqual([{ status: 'confirmed', confirmed_at: expect.any(String) }]);
      const assignedCrew = await readOnlyDatabaseJson<Array<{
        assigned_by: string; recorder_email: string; member_email: string; crew_size: number;
      }>>(`
        SELECT assigner.email AS assigned_by, recorder.email AS recorder_email,
               member.email AS member_email, COUNT(crew_member.id)::int AS crew_size
        FROM work_order_crews crew
        JOIN work_orders wo ON wo.id = crew.work_order_id
        JOIN users assigner ON assigner.id = crew.assigned_by
        JOIN employees recorder_employee ON recorder_employee.id = crew.recorder_employee_id
        JOIN users recorder ON recorder.employee_id = recorder_employee.id
        JOIN work_order_crew_members crew_member ON crew_member.work_order_crew_id = crew.id
        JOIN employees member_employee ON member_employee.id = crew_member.employee_id
        JOIN users member ON member.employee_id = member_employee.id
        WHERE wo.wo_number = ${sqlText(contextValue<string>(context, 'woNumber'))}
          AND crew.ended_at IS NULL AND crew.rework_ncr_id IS NULL
        GROUP BY assigner.email, recorder.email, member.email
      `);
      expect(assignedCrew).toEqual([{
        assigned_by: managerIdentity.email,
        recorder_email: workerIdentity.email,
        member_email: workerIdentity.email,
        crew_size: 1,
      }]);
      await assertNotificationRecipients(context, 'production.crew.assigned', [workerIdentity.email.toLowerCase()]);
    });
    await ppc.context.close();

    const startStep = confirmStep + 1;
    await step(startStep, manager, async () => {
      await manager.page.getByRole('button', { name: 'Start', exact: true }).click();
      const dialog = manager.page.getByRole('dialog', { name: new RegExp(`Start ${contextValue<string>(context, 'woNumber')}`) });
      await expect(dialog).toBeVisible();
      await expect(dialog.getByText('Checking schedule, workers, materials, equipment and quality…')).toHaveCount(0);
      await expect(dialog.getByText('Production checks passed. Starting records actual time and issues reserved materials.', { exact: false })).toBeVisible();
      const presence = dialog.getByRole('checkbox', { name: /Confirm physically present:/ });
      if (await presence.count()) await presence.check();
      await dialog.getByRole('button', { name: 'Start production', exact: true }).click();
      await expect(manager.page.getByRole('heading', { level: 1 })).toContainText('In progress');
    }, async () => {
      const row = await assertWorkOrderState(context, { status: 'in_progress' });
      expect(row.machine_code).toBeTruthy();
      expect(row.mold_code).toBeTruthy();
      const inspection = await readOnlyDatabaseJson<Array<{ count: number; status: string; startup_trial_required: boolean }>>(`
        SELECT COUNT(*)::int AS count, MIN(inspection.status) AS status,
               bool_and(inspection.startup_trial_required) AS startup_trial_required
        FROM inspections inspection
        WHERE inspection.entity_type = 'work_order' AND inspection.entity_id = ${row.id}
          AND inspection.stage = 'in_process' AND inspection.wo_operation_id IS NULL
          AND inspection.work_order_output_id IS NULL
      `);
      expect(inspection).toEqual([{ count: 1, status: 'draft', startup_trial_required: true }]);
      const issue = await readOnlyDatabaseJson<Array<{ count: number; actor_email: string }>>(`
        SELECT COUNT(*)::int AS count, MIN(actor.email) AS actor_email
        FROM stock_movements movement
        JOIN users actor ON actor.id = movement.created_by
        WHERE movement.reference_type = 'work_order' AND movement.reference_id = ${row.id}
          AND movement.movement_type = 'material_issue'
      `);
      expect(issue).toEqual([{ count: materials.length, actor_email: managerIdentity.email }]);
    }, async () => {
      expect((await readWorkOrder(context)).status).toBe('in_progress');
      await expect(manager.page.getByText(/Material handover pending:/)).toBeVisible();
    });

    const handoverStep = startStep + 1;
    await step(handoverStep, warehouse, async () => {
      await openMenuItem(warehouse.page, 'Warehouse', 'Material Handover', '/inventory/picking');
      await expect(warehouse.page.getByRole('heading', { name: 'Material Handover', exact: true })).toBeVisible();
      const row = warehouse.page.getByRole('row').filter({ hasText: contextValue<string>(context, 'woNumber') });
      await expect(row).toHaveCount(1);
      await row.click();
      const details = warehouse.page.getByRole('region', { name: `Handover details for ${contextValue<string>(context, 'woNumber')}` });
      await expect(details).toBeVisible();
      const confirm = details.getByRole('button', { name: /Confirm handover \(/ });
      await expect(confirm).toBeEnabled();
      await confirm.click();
      await expect(details.getByText('Handed over', { exact: true })).toHaveCount(materials.length);
    }, async () => {
      const row = await readWorkOrder(context);
      const handovers = await readOnlyDatabaseJson<Array<{ count: number; pending: number; recorder_email: string; recipient_email: string }>>(`
        SELECT COUNT(*)::int AS count, COUNT(*) FILTER (WHERE handover.handed_over_at IS NULL)::int AS pending,
               MIN(recorder.email) AS recorder_email, MIN(recipient.email) AS recipient_email
        FROM material_handovers handover
        JOIN stock_movements movement ON movement.id = handover.stock_movement_id
        JOIN users recorder ON recorder.id = handover.recorded_by
        JOIN employees recipient_employee ON recipient_employee.id = handover.received_by_employee_id
        JOIN users recipient ON recipient.employee_id = recipient_employee.id
        WHERE movement.reference_type = 'work_order' AND movement.reference_id = ${row.id}
      `);
      expect(handovers).toEqual([{ count: materials.length, pending: 0, recorder_email: warehouseIdentity.email, recipient_email: workerIdentity.email }]);
      expect(row.status).toBe('in_progress');
    }, async () => {
      await expect(manager.page.getByText('Materials handed over.', { exact: true })).toBeVisible();
      await expect(ppc.page.getByRole('heading', { level: 1 }).filter({ hasText: contextValue<string>(context, 'woNumber') })).toHaveCount(0);
    });
    await warehouse.context.close();

    const trialStep = handoverStep + 1;
    worker = await openActor(workerIdentity.actor, true);
    await step(trialStep, worker, async () => {
      await openNotification(worker, contextValue<string>(context, 'woNumber'));
      await expect(worker.page).toHaveURL(/\/self-service\/work-orders\//);
      await expect(worker.page.getByRole('heading', { level: 1 })).toContainText(contextValue<string>(context, 'woNumber'));
      const selfServiceOrder = worker.page.getByRole('heading', { level: 1 });
      await expect(selfServiceOrder).toContainText(contextValue<string>(context, 'woNumber'));
      const panel = worker.page.getByRole('region', { name: 'Startup production' });
      await expect(panel).toBeVisible();
      await panel.getByRole('checkbox', { name: new RegExp(`Samples ready for QC: ${fixture.sample_size} pieces physically produced`) }).check();
      await panel.getByRole('button', { name: 'Samples ready for QC', exact: true }).click();
      await expect(panel.getByRole('status')).toContainText('Waiting for QC');

      // The Production Manager stays on the same work-order detail as a live
      // observer while the assigned employee records through self-service.
      await expect(manager.page.getByRole('heading', { level: 1 })).toContainText(contextValue<string>(context, 'woNumber'));
    }, async () => {
      const row = await readWorkOrder(context);
      expect(row).toMatchObject({ status: 'in_progress', quantity_target: target, quantity_produced: fixture.sample_size, quantity_good: 0 });
      const inspection = (await readInspectionRows(context)).find((item) => item.stage === 'in_process');
      expect(inspection).toMatchObject({ status: 'draft', sample_size: fixture.sample_size, batch_quantity: target });
      const output = await readOnlyDatabaseJson<Array<{ count: number; quantity: number; recorder_email: string; settled_at: string | null }>>(`
        SELECT COUNT(*)::int AS count, MIN(output.trial_quantity)::int AS quantity,
               MIN(actor.email) AS recorder_email, MIN(output.trial_settled_at)::text AS settled_at
        FROM work_order_outputs output JOIN users actor ON actor.id = output.recorded_by
        WHERE output.work_order_id = ${row.id} AND output.trial_inspection_id IS NOT NULL
      `);
      expect(output).toEqual([{ count: 1, quantity: fixture.sample_size, recorder_email: workerIdentity.email, settled_at: null }]);
    }, async () => {
      await expect(manager.page.getByRole('status')).toContainText('Waiting for QC');
      await expect(manager.page.getByRole('heading', { level: 1 })).toContainText(contextValue<string>(context, 'woNumber'));
      expect(context.values.shiftAssignmentEmployee).toBe(workerIdentity.email);
    });

    const processQcStep = trialStep + 1;
    qc = await openActor(qcIdentity.actor);
    const inProcessRecipients = await expectedConfiguredRecipients('quality.in_process_qc.notification_roles', actors);
    await step(processQcStep, qc, async () => {
      await openNotification(qc, contextValue<string>(context, 'woNumber'));
      const inspection = await readInspectionRows(context);
      const inProcess = inspection.find((item) => item.stage === 'in_process');
      expect(inProcess).toBeTruthy();
      await expect(qc.page.getByRole('heading', { level: 1 })).toContainText(inProcess!.inspection_number);
      await setPassingEvidence(qc, inProcess!.sample_size);
      await expect(qc.page.getByRole('heading', { level: 1 })).toContainText('Passed');
    }, async () => {
      const row = await readWorkOrder(context);
      expect(row).toMatchObject({ status: 'in_progress', quantity_good: fixture.sample_size, quantity_produced: fixture.sample_size });
      const inspection = (await readInspectionRows(context)).filter((item) => item.stage === 'in_process');
      expect(inspection).toHaveLength(1);
      expect(inspection[0]).toMatchObject({ status: 'passed', recorded_result: 'passed', accepted_quantity: 0, inspector_email: qcIdentity.email });
      const trial = await readOnlyDatabaseJson<Array<{ good: number; reject: number; settled: boolean; receipt_status: string; movement_count: number }>>(`
        SELECT output.good_count AS good, output.reject_count AS reject,
               output.trial_settled_at IS NOT NULL AS settled,
               output.production_receipt_handoff_status AS receipt_status,
               (SELECT COUNT(*)::int FROM stock_movements movement
                 WHERE movement.reference_type = 'work_order_output' AND movement.reference_id = output.id
                   AND movement.movement_type = 'production_receipt') AS movement_count
        FROM work_order_outputs output WHERE output.work_order_id = ${row.id} AND output.trial_inspection_id IS NOT NULL
      `);
      expect(trial).toEqual([{ good: fixture.sample_size, reject: 0, settled: true, receipt_status: 'generated', movement_count: 1 }]);
      await assertNotificationRecipients(context, 'chain.in_process_qc_required', inProcessRecipients);
    }, async () => {
      await expect(manager.page.getByText('Ready for production', { exact: true })).toBeVisible();
      expect((await readWorkOrder(context)).quantity_good).toBe(fixture.sample_size);
    });

    const setupStep = processQcStep + 1;
    await step(setupStep, manager, async () => {
      await openMenuItem(manager.page, 'Production', 'Production Work Orders', '/production/work-orders');
      const row = manager.page.getByRole('row').filter({ hasText: contextValue<string>(context, 'woNumber') });
      await expect(row).toHaveCount(1);
      await row.click();
      await expect(manager.page.getByRole('heading', { level: 1 })).toContainText(contextValue<string>(context, 'woNumber'));
      await manager.page.getByRole('tab', { name: 'Operations', exact: true }).click();
      const operation = manager.page.getByRole('row').filter({ hasText: 'Injection molding' });
      await expect(operation).toHaveCount(1);
      await expect(operation.getByRole('button', { name: 'Setup', exact: true })).toBeEnabled();
      await operation.getByRole('button', { name: 'Setup', exact: true }).click();
      await expect(operation.getByRole('button', { name: 'End setup', exact: true })).toBeEnabled();
      await operation.getByRole('button', { name: 'End setup', exact: true }).click();
      await expect(operation.getByRole('button', { name: 'Start', exact: true })).toBeEnabled();
    }, async () => {
      const operation = await readOnlyDatabaseJson<Array<{ status: string; operator_email: string; setup_start: string | null; setup_end: string | null }>>(`
        SELECT operation.status, actor.email AS operator_email, operation.setup_start::text, operation.setup_end::text
        FROM wo_operations operation
        JOIN work_orders wo ON wo.id = operation.work_order_id
        JOIN employees employee ON employee.id = operation.operator_id
        JOIN users actor ON actor.employee_id = employee.id
        WHERE wo.wo_number = ${sqlText(contextValue<string>(context, 'woNumber'))}
          AND operation.operation_name = 'Injection molding'
      `);
      expect(operation).toEqual([{ status: 'setup', operator_email: managerIdentity.email, setup_start: expect.any(String), setup_end: expect.any(String) }]);
    }, async () => {
      await expect(manager.page.getByRole('row').filter({ hasText: 'Injection molding' }).getByRole('button', { name: 'Start', exact: true })).toBeEnabled();
    });

    const operationStartStep = setupStep + 1;
    await step(operationStartStep, manager, async () => {
      const operation = manager.page.getByRole('row').filter({ hasText: 'Injection molding' });
      await operation.getByRole('button', { name: 'Start', exact: true }).click();
      await expect(operation.getByRole('button', { name: 'Output', exact: true })).toBeVisible();
    }, async () => {
      const operation = await readOnlyDatabaseJson<Array<{ status: string; operator_email: string; started: boolean }>>(`
        SELECT operation.status, actor.email AS operator_email, operation.actual_start IS NOT NULL AS started
        FROM wo_operations operation JOIN work_orders wo ON wo.id = operation.work_order_id
        JOIN employees employee ON employee.id = operation.operator_id JOIN users actor ON actor.employee_id = employee.id
        WHERE wo.wo_number = ${sqlText(contextValue<string>(context, 'woNumber'))} AND operation.operation_name = 'Injection molding'
      `);
      expect(operation).toEqual([{ status: 'in_progress', operator_email: managerIdentity.email, started: true }]);
    }, async () => {
      await expect(manager.page.getByRole('button', { name: 'Complete', exact: true })).toHaveCount(1);
    });

    const operationOutputStep = operationStartStep + 1;
    await step(operationOutputStep, manager, async () => {
      const operation = manager.page.getByRole('row').filter({ hasText: 'Injection molding' });
      await operation.getByRole('button', { name: 'Output', exact: true }).click();
      const dialog = manager.page.getByRole('dialog', { name: 'Record operation output' });
      await expect(dialog).toBeVisible();
      await dialog.getByRole('spinbutton', { name: 'Quantity', exact: true }).fill('1');
      await dialog.getByRole('spinbutton', { name: 'Scrap', exact: true }).fill('0');
      await dialog.getByRole('button', { name: 'Record output', exact: true }).click();
      await expect(manager.page.getByText('1 / 6', { exact: true })).toBeVisible();
    }, async () => {
      const operation = await readOnlyDatabaseJson<Array<{ qty_completed: string; qty_scrapped: string; log_count: number }>>(`
        SELECT operation.qty_completed::text, operation.qty_scrapped::text,
               (SELECT COUNT(*)::int FROM production_logs log WHERE log.wo_operation_id = operation.id AND log.event = 'record_output') AS log_count
        FROM wo_operations operation JOIN work_orders wo ON wo.id = operation.work_order_id
        WHERE wo.wo_number = ${sqlText(contextValue<string>(context, 'woNumber'))} AND operation.operation_name = 'Injection molding'
      `);
      expect(operation).toEqual([{ qty_completed: '1.0000', qty_scrapped: '0.0000', log_count: 1 }]);
    }, async () => {
      await expect(manager.page.getByRole('row').filter({ hasText: 'Injection molding' })).toContainText('1 / 6');
    });

    const operationCompleteStep = operationOutputStep + 1;
    await step(operationCompleteStep, manager, async () => {
      const operation = manager.page.getByRole('row').filter({ hasText: 'Injection molding' });
      await operation.getByRole('button', { name: 'Complete', exact: true }).click();
      await expect(operation).toContainText('Completed');
    }, async () => {
      const operation = await readOnlyDatabaseJson<Array<{ status: string; completed: boolean; output: string }>>(`
        SELECT operation.status, operation.actual_end IS NOT NULL AS completed, operation.qty_completed::text AS output
        FROM wo_operations operation JOIN work_orders wo ON wo.id = operation.work_order_id
        WHERE wo.wo_number = ${sqlText(contextValue<string>(context, 'woNumber'))} AND operation.operation_name = 'Injection molding'
      `);
      expect(operation).toEqual([{ status: 'completed', completed: true, output: '1.0000' }]);
    }, async () => {
      await expect(manager.page.getByRole('row').filter({ hasText: 'Injection molding' })).toContainText('Completed');
    });

    const outputStep = operationCompleteStep + 1;
    await step(outputStep, worker, async () => {
      await expect(worker.page).toHaveURL(/\/self-service\/work-orders\//);
      await expect(worker.page.getByRole('heading', { level: 1 })).toContainText(contextValue<string>(context, 'woNumber'));
      const goodCount = worker.page.getByRole('spinbutton', { name: 'Good count', exact: true });
      await goodCount.fill('1');
      await worker.page.getByRole('button', { name: 'Record output', exact: true }).click();
      await expect(worker.page.getByRole('heading', { level: 1 })).toContainText(contextValue<string>(context, 'woNumber'));
      await expect(manager.page.getByRole('button', { name: 'Complete', exact: true })).toBeVisible();
    }, async () => {
      const row = await assertWorkOrderState(context, { status: 'in_progress', quantity_target: target, quantity_produced: target, quantity_good: target, quantity_rejected: 0 });
      const outputs = await readOnlyDatabaseJson<Array<{ count: number; good: number; trial_count: number; receipts: number; receipt_statuses: string[]; recorders: string[] }>>(`
        SELECT COUNT(*)::int AS count, SUM(output.good_count)::int AS good,
               COUNT(*) FILTER (WHERE output.trial_inspection_id IS NOT NULL)::int AS trial_count,
               COUNT(*) FILTER (WHERE output.production_receipt_movement_id IS NOT NULL)::int AS receipts,
               array_agg(output.production_receipt_handoff_status ORDER BY output.id) AS receipt_statuses,
               array_agg(actor.email ORDER BY output.id) AS recorders
        FROM work_order_outputs output JOIN users actor ON actor.id = output.recorded_by
        WHERE output.work_order_id = ${row.id}
      `);
      expect(outputs).toEqual([{
        count: 2, good: target, trial_count: 1, receipts: 2,
        receipt_statuses: ['generated', 'generated'], recorders: [workerIdentity.email, workerIdentity.email],
      }]);
      const movements = await readOnlyDatabaseJson<Array<{ count: number; quantity: number; movement_type: string }>>(`
        SELECT COUNT(*)::int AS count, SUM(movement.quantity)::int AS quantity, MIN(movement.movement_type) AS movement_type
        FROM stock_movements movement
        JOIN items item ON item.id = movement.item_id
        JOIN products product ON product.part_number = item.code
        WHERE product.part_number = 'WB-001' AND movement.movement_type = 'production_receipt'
          AND movement.reference_type = 'work_order_output'
          AND movement.reference_id IN (SELECT id FROM work_order_outputs WHERE work_order_id = ${row.id})
      `);
      expect(movements).toEqual([{ count: 2, quantity: target, movement_type: 'production_receipt' }]);
    }, async () => {
      expect((await readWorkOrder(context)).quantity_good).toBe(target);
      await expect(worker.page.getByRole('button', { name: 'Record output', exact: true })).toBeDisabled();
    });

    const completeStep = outputStep + 1;
    await step(completeStep, manager, async () => {
      await manager.page.getByRole('button', { name: 'Complete', exact: true }).click();
      const dialog = manager.page.getByRole('dialog');
      await expect(dialog).toBeVisible();
      const confirm = dialog.getByRole('button', { name: /Confirm|Complete work order|Finish production/ }).last();
      await expect(confirm).toBeEnabled();
      await confirm.click();
      await expect(manager.page.getByRole('heading', { level: 1 })).toContainText('Production finished — awaiting quality');
    }, async () => {
      const row = await assertWorkOrderState(context, { status: 'completed', quantity_produced: target, quantity_good: target });
      const outgoing = (await readInspectionRows(context)).filter((inspection) => inspection.stage === 'outgoing');
      expect(outgoing).toHaveLength(2);
      expect(outgoing.every((inspection) => inspection.status === 'draft')).toBe(true);
      context.values.outgoingInspections = outgoing.map((inspection) => ({ id: inspection.id, number: inspection.inspection_number, sample: inspection.sample_size, output: inspection.output_id }));
      const link = await readOnlyDatabaseJson<Array<{ status: string; count: number }>>(`
        SELECT listener.status, COUNT(*)::int AS count
        FROM chain_listener_runs listener
        JOIN chain_step_runs step_run ON step_run.outbox_id = listener.outbox_id
        WHERE step_run.entity_type = 'work_order' AND step_run.entity_id = ${row.id}
          AND listener.listener_class LIKE '%TriggerOutgoingQC%'
        GROUP BY listener.status
      `);
      expect(link.some((entry) => entry.status === 'completed' && entry.count === 1)).toBe(true);
    }, async () => {
      await expect(manager.page.getByText(/Waiting for independently approved QC/)).toHaveCount(0);
      expect((await readWorkOrder(context)).status).toBe('completed');
    });

    const outgoingRecipients = await expectedConfiguredRecipients('quality.outgoing_qc.notification_roles', actors);
    const outgoingMakerStep = completeStep + 1;
    const outgoing = contextValue<Array<{ id: number; number: string; sample: number; output: number }>>(context, 'outgoingInspections');
    const trialOutputs = await readOnlyDatabaseJson<Array<{ id: number }>>(`
      SELECT id FROM work_order_outputs WHERE work_order_id = ${(await readWorkOrderId(context))}
        AND trial_inspection_id IS NOT NULL
    `);
    expect(trialOutputs).toHaveLength(1);
    const trialInspection = outgoing.find((inspection) => inspection.output === trialOutputs[0].id);
    const normalInspection = outgoing.find((inspection) => inspection.id !== trialInspection?.id);
    expect(trialInspection).toBeTruthy();
    expect(normalInspection).toBeTruthy();

    await step(outgoingMakerStep, qc, async () => {
      await openNotification(qc, contextValue<string>(context, 'woNumber'));
      await expect(qc.page).toHaveURL(/\/quality\/inspections\?.*stage=outgoing/);
      const row = qc.page.getByRole('row').filter({ hasText: trialInspection!.number });
      await expect(row).toHaveCount(1);
      await row.click();
      await expect(qc.page.getByRole('heading', { level: 1 })).toContainText(trialInspection!.number);
      await setPassingEvidence(qc, trialInspection!.sample);
      await expect(qc.page.getByRole('heading', { level: 1 })).toContainText('Awaiting review');
    }, async () => {
      const inspection = (await readInspectionRows(context)).find((item) => item.id === trialInspection!.id);
      expect(inspection).toMatchObject({ stage: 'outgoing', status: 'awaiting_review', inspector_email: qcIdentity.email, output_id: trialInspection!.output });
      expect(await expectedReviewers(trialInspection!.number)).toContain(managerIdentity.email.toLowerCase());
      await assertNotificationRecipients(context, 'chain.outgoing_qc_required', outgoingRecipients);
    }, async () => {
      await expect(qc.page.getByRole('button', { name: 'Approve pass', exact: true })).toHaveCount(0);
      expect((await readWorkOrder(context)).status).toBe('completed');
    });

    const firstReviewStep = outgoingMakerStep + 1;
    await step(firstReviewStep, manager, async () => {
      await openNotification(manager, trialInspection!.number);
      await expect(manager.page.getByRole('heading', { level: 1 })).toContainText(trialInspection!.number);
      await manager.page.getByRole('button', { name: 'Approve pass', exact: true }).click();
      const dialog = manager.page.getByRole('dialog', { name: 'Approve inspection pass?' });
      await expect(dialog).toBeVisible();
      await dialog.getByRole('button', { name: 'Approve pass', exact: true }).click();
      await expect(manager.page.getByRole('heading', { level: 1 })).toContainText('Passed');
    }, async () => {
      const inspection = (await readInspectionRows(context)).find((item) => item.id === trialInspection!.id);
      expect(inspection).toMatchObject({ status: 'passed', inspector_email: qcIdentity.email, reviewer_email: managerIdentity.email, recorded_result: 'passed', accepted_quantity: fixture.sample_size });
      expect((await readWorkOrder(context)).status).toBe('completed');
    }, async () => {
      await expect(manager.page.getByRole('button', { name: 'Approve pass', exact: true })).toHaveCount(0);
    });

    const secondMakerStep = firstReviewStep + 1;
    await step(secondMakerStep, qc, async () => {
      await openMenuItem(qc.page, 'Quality', 'Quality Inspections', '/quality/inspections');
      await expect(qc.page.getByRole('heading', { name: 'Inspections', exact: true })).toBeVisible();
      const row = qc.page.getByRole('row').filter({ hasText: normalInspection!.number });
      await expect(row).toHaveCount(1);
      await row.click();
      await expect(qc.page.getByRole('heading', { level: 1 })).toContainText(normalInspection!.number);
      await setPassingEvidence(qc, normalInspection!.sample);
      await expect(qc.page.getByRole('heading', { level: 1 })).toContainText('Awaiting review');
    }, async () => {
      const inspection = (await readInspectionRows(context)).find((item) => item.id === normalInspection!.id);
      expect(inspection).toMatchObject({ stage: 'outgoing', status: 'awaiting_review', inspector_email: qcIdentity.email, output_id: normalInspection!.output });
      expect(await expectedReviewers(normalInspection!.number)).toContain(managerIdentity.email.toLowerCase());
    }, async () => {
      await expect(qc.page.getByRole('button', { name: 'Approve pass', exact: true })).toHaveCount(0);
    });

    const secondReviewStep = secondMakerStep + 1;
    await step(secondReviewStep, manager, async () => {
      await openNotification(manager, normalInspection!.number);
      await expect(manager.page.getByRole('heading', { level: 1 })).toContainText(normalInspection!.number);
      await manager.page.getByRole('button', { name: 'Approve pass', exact: true }).click();
      const dialog = manager.page.getByRole('dialog', { name: 'Approve inspection pass?' });
      await expect(dialog).toBeVisible();
      await dialog.getByRole('button', { name: 'Approve pass', exact: true }).click();
      await expect(manager.page.getByRole('heading', { level: 1 })).toContainText('Passed');
    }, async () => {
      const inspections = (await readInspectionRows(context)).filter((inspection) => inspection.stage === 'outgoing');
      expect(inspections).toHaveLength(2);
      expect(inspections.every((inspection) => inspection.status === 'passed' && inspection.inspector_email === qcIdentity.email && inspection.reviewer_email === managerIdentity.email && inspection.recorded_result === 'passed')).toBe(true);
      const row = await assertWorkOrderState(context, { status: 'closed', quantity_target: target, quantity_produced: target, quantity_good: target, quantity_rejected: 0 });
      context.values.closedWorkOrderId = row.id;
    }, async () => {
      expect((await readWorkOrder(context)).status).toBe('closed');
      await expect(manager.page.getByRole('heading', { level: 1 })).toContainText(contextValue<string>(context, 'woNumber'));
    });

    const finalStep = secondReviewStep + 1;
    await step(finalStep, manager, async () => {
      await expect(manager.page.getByRole('heading', { level: 1 })).toContainText('closed');
      await expect(manager.page.getByText('Current quality obligations', { exact: true })).toBeVisible();
      await expect(manager.page.getByText('Production history is closed.', { exact: false })).toBeVisible();
    }, async () => {
      const row = await assertWorkOrderState(context, { status: 'closed', quantity_target: target, quantity_produced: target, quantity_good: target, quantity_rejected: 0 });
      const outputs = await readOnlyDatabaseJson<Array<{ count: number; good: number; trial_quantity: number; receipt_count: number; receipt_status: string[] }>>(`
        SELECT COUNT(*)::int AS count, SUM(output.good_count)::int AS good,
               SUM(COALESCE(output.trial_quantity, 0))::int AS trial_quantity,
               COUNT(movement.id)::int AS receipt_count,
               array_agg(output.production_receipt_handoff_status ORDER BY output.id) AS receipt_status
        FROM work_order_outputs output
        LEFT JOIN stock_movements movement ON movement.id = output.production_receipt_movement_id
          AND movement.movement_type = 'production_receipt'
        WHERE output.work_order_id = ${row.id}
      `);
      expect(outputs).toEqual([{ count: 2, good: target, trial_quantity: fixture.sample_size, receipt_count: 2, receipt_status: ['generated', 'generated'] }]);
      const inventoryIds = [...materials.map((material) => material.item_id), fixture.finished_item_id];
      const balances = await currentStockByItem(inventoryIds);
      expect(balances).toHaveLength(inventoryIds.length);
      for (const balance of balances) {
        expect(Number(balance.on_hand), `on-hand for item ${balance.item_id} should reconcile to its movement ledger`).toBe(Number(balance.ledger_on_hand));
      }
      const finishedGood = await readOnlyDatabaseJson<Array<{ on_hand: string; ledger_on_hand: string }>>(`
        SELECT COALESCE(SUM(level.quantity), 0)::numeric(18, 3)::text AS on_hand,
               (COALESCE((SELECT SUM(movement.quantity) FROM stock_movements movement WHERE movement.item_id = item.id AND movement.to_location_id IS NOT NULL), 0)
                - COALESCE((SELECT SUM(movement.quantity) FROM stock_movements movement WHERE movement.item_id = item.id AND movement.from_location_id IS NOT NULL), 0))::numeric(18, 3)::text AS ledger_on_hand
        FROM items item LEFT JOIN stock_levels level ON level.item_id = item.id
        WHERE item.id = ${fixture.finished_item_id} GROUP BY item.id
      `);
      expect(Number(finishedGood[0].on_hand)).toBe(target);
      expect(Number(finishedGood[0].on_hand)).toBe(Number(finishedGood[0].ledger_on_hand));
      const runAudit = await readOnlyDatabaseJson<Array<{ status: string; email: string }>>(`
        SELECT audit.new_values ->> 'status' AS status, actor.email
        FROM audit_logs audit JOIN users actor ON actor.id = audit.user_id
        WHERE audit.model_type ILIKE '%WorkOrder' AND audit.model_id = ${row.id}
          AND audit.new_values::jsonb ? 'status'
        ORDER BY audit.id
      `);
      expect(runAudit).toContainEqual({ status: 'planned', email: ppcIdentity.email });
      expect(runAudit).toContainEqual({ status: 'confirmed', email: managerIdentity.email });
      expect(runAudit).toContainEqual({ status: 'in_progress', email: managerIdentity.email });
      expect(runAudit).toContainEqual({ status: 'completed', email: managerIdentity.email });
      expect(runAudit).toContainEqual({ status: 'closed', email: managerIdentity.email });
      const outstanding = await readOnlyDatabaseJson<Array<{ count: number }>>(`
        SELECT COUNT(*)::int AS count FROM notifications notice
        JOIN users recipient ON recipient.id = notice.notifiable_id
        WHERE notice.notifiable_type LIKE '%User'
          AND recipient.email IN (${sqlText(qcIdentity.email)}, ${sqlText(managerIdentity.email)})
          AND (notice.data ->> 'wo_number' = ${sqlText(contextValue<string>(context, 'woNumber'))}
            OR notice.data ->> 'title' LIKE ${sqlText(`%${contextValue<string>(context, 'woNumber')}%`)})
          AND notice.read_at IS NULL
      `);
      expect(outstanding[0].count).toBe(0);
    }, async () => {
      await expect(manager.page.getByRole('button', { name: 'Complete', exact: true })).toHaveCount(0);
      await expect(manager.page.getByRole('button', { name: 'Start', exact: true })).toHaveCount(0);
      expect(context.values.closedWorkOrderId).toEqual(expect.any(Number));
    });
  } finally {
    if (clockFrozen) await clearClock(request).catch(() => undefined);
    for (const session of sessions.reverse()) await session.context.close().catch(() => undefined);
  }
});
