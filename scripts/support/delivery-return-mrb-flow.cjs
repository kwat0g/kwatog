module.exports = async function deliveryReturnMrbFlow({
  manager,
  qc,
  checker,
  warehouse,
  warehouseChecker,
  rmaId,
  fixture,
  targetLocationId = fixture.location,
  request,
  ok,
  psql,
  expect,
  passed,
}) {
  const url = `/return-management/return-requests/${rmaId}`;
  const auditCount = (rmaNumber) => psql(`select count(*) from audit_logs a join return_requests r on r.id=a.model_id where a.model_type like '%ReturnRequest' and r.rma_number='${rmaNumber}'`, fixture.database);
  const mrbCount = (rmaNumber) => psql(`select count(*) from material_review_records m join return_request_items i on i.id=m.return_request_item_id join return_requests r on r.id=i.return_request_id where r.rma_number='${rmaNumber}'`, fixture.database);
  const movementCount = (mrbNumber) => psql(`select count(*) from stock_movements where reference_type='material_review_record' and reference_id=(select id from material_review_records where mrb_number='${mrbNumber}')`, fixture.database);
  const mrbAuditCount = (mrbNumber) => psql(`select count(*) from audit_logs a join material_review_records m on m.id=a.model_id where a.model_type like '%MaterialReviewRecord' and m.mrb_number='${mrbNumber}'`, fixture.database);
  const stockAt = async (page, itemId, locationId) => {
    const levels = await ok(page, 'GET', `/inventory/stock-levels?item_id=${itemId}&per_page=100`);
    return levels.find((level) => level.location?.id === locationId)?.quantity ?? '0.000';
  };
  const transferReleasedStockToFinishedGoods = async (completedRma) => {
    const items = completedRma.items.filter((item) => ['restock', 'rework'].includes(item.disposition ?? ''));
    expect(new Set(items.map((item) => item.item_id)).size).toBe(1);
    expect(new Set(items.map((item) => item.lot_number)).size).toBe(1);
    const itemId = items[0].item_id;
    const quantity = items.reduce((sum, item) => sum + Number(item.returned_quantity), 0).toFixed(3);
    const sourceBefore = await stockAt(warehouse, itemId, targetLocationId);
    const destinationBefore = await stockAt(warehouse, itemId, fixture.location);

    await warehouse.goto('/inventory/transfer-orders');
    await expect(warehouse.getByRole('heading', { name: 'Transfer Orders', exact: true })).toBeVisible();
    await warehouse.getByRole('button', { name: 'New transfer', exact: true }).click();
    const createDialog = warehouse.getByRole('dialog', { name: 'New transfer order' });
    const itemSearch = createDialog.getByRole('combobox', { name: 'Item', exact: true });
    await itemSearch.fill(fixture.product_code);
    // SearchableSelect renders its option list in a document-level portal.
    const itemOption = warehouse.getByRole('option', { name: new RegExp(fixture.product_code) });
    await expect(itemOption).toBeVisible();
    await itemOption.click();
    const source = createDialog.getByLabel('From location');
    await expect.poll(() => source.locator('option').count()).toBeGreaterThan(1);
    await source.selectOption(targetLocationId);
    const destination = createDialog.getByLabel('To location');
    await expect.poll(() => destination.locator('option').count()).toBeGreaterThan(1);
    await destination.selectOption(fixture.location);
    await createDialog.getByLabel('Quantity').fill(quantity);
    await createDialog.getByLabel('Reason (optional)').fill(`RMA ${completedRma.rma_number}: transfer MRB-released stock to finished goods.`);
    const createResponsePromise = warehouse.waitForResponse((response) =>
      response.request().method() === 'POST' && response.url().endsWith('/api/v1/inventory/transfer-orders'));
    await createDialog.getByRole('button', { name: 'Create transfer order', exact: true }).click();
    const createResponse = await createResponsePromise;
    const createBody = await createResponse.json();
    expect(createResponse.status(), JSON.stringify(createBody)).toBe(201);
    const transfer = createBody.data;
    await expect(createDialog).toBeHidden();
    expect(transfer).toMatchObject({
      status: 'pending',
      from_location: { id: targetLocationId },
      to_location: { id: fixture.location },
      item: { id: itemId },
      quantity,
      transferred_by: null,
      transferred_at: null,
    });
    const movementCount = () => psql(`select count(*) from stock_movements where reference_type='stock_transfer' and reference_id=(select id from transfer_orders where transfer_number='${transfer.transfer_number}')`, fixture.database);
    const transferAuditCount = () => psql(`select count(*) from audit_logs a join transfer_orders t on t.id=a.model_id where a.model_type like '%TransferOrder' and t.transfer_number='${transfer.transfer_number}'`, fixture.database);
    const auditBeforeExecute = transferAuditCount();
    const makerUser = await ok(warehouse, 'GET', '/auth/user');
    expect(makerUser.permissions).toContain('inventory.adjust');
    expect(transfer.created_by?.id).toBe(makerUser.id);
    expect(psql(`select count(*) from audit_logs a join transfer_orders t on t.id=a.model_id where a.model_type like '%TransferOrder' and t.transfer_number='${transfer.transfer_number}' and a.action='created' and a.user_id=(select id from users where email='${makerUser.email}') and a.new_values->>'status'='pending'`, fixture.database)).toBe('1');
    expect(movementCount()).toBe('0');
    expect(await stockAt(warehouse, itemId, targetLocationId)).toBe(sourceBefore);
    expect(await stockAt(warehouse, itemId, fixture.location)).toBe(destinationBefore);

    await warehouse.getByRole('button').filter({ hasText: transfer.transfer_number }).click();
    await expect(warehouse.getByRole('status').filter({ hasText: 'Another warehouse user must execute a transfer you created.' })).toBeVisible();
    await expect(warehouse.getByRole('button', { name: 'Execute', exact: true })).toHaveCount(0);
    const selfExecution = await request(warehouse, 'POST', `/inventory/transfer-orders/${transfer.id}/execute`, {});
    expect(selfExecution.status).toBe(422);
    expect(selfExecution.body.message).toBe('A transfer order creator cannot execute their own transfer.');
    expect((await ok(warehouse, 'GET', `/inventory/transfer-orders/${transfer.id}`)).status).toBe('pending');
    expect(movementCount()).toBe('0');
    expect(transferAuditCount()).toBe(auditBeforeExecute);
    expect(await stockAt(warehouse, itemId, targetLocationId)).toBe(sourceBefore);
    expect(await stockAt(warehouse, itemId, fixture.location)).toBe(destinationBefore);

    const checkerUser = await ok(warehouseChecker, 'GET', '/auth/user');
    expect(checkerUser.permissions).toContain('inventory.adjust');
    expect(checkerUser.id).not.toBe(makerUser.id);
    await warehouseChecker.goto('/inventory/transfer-orders');
    await expect(warehouseChecker.getByRole('heading', { name: 'Transfer Orders', exact: true })).toBeVisible();
    await warehouseChecker.getByRole('button').filter({ hasText: transfer.transfer_number }).click();
    const executeButton = warehouseChecker.getByRole('button', { name: 'Execute', exact: true });
    await expect(executeButton).toBeVisible();
    const executeResponsePromise = warehouseChecker.waitForResponse((response) =>
      response.request().method() === 'POST'
      && response.url().endsWith(`/api/v1/inventory/transfer-orders/${transfer.id}/execute`));
    await executeButton.click();
    const executeDialog = warehouseChecker.getByRole('dialog', { name: 'Execute transfer?' });
    await expect(executeDialog).toBeVisible();
    await executeDialog.getByRole('button', { name: 'Execute', exact: true }).click();
    const executeResponse = await executeResponsePromise;
    const executeBody = await executeResponse.json();
    expect(executeResponse.status(), JSON.stringify(executeBody)).toBe(200);
    const transferred = await ok(warehouseChecker, 'GET', `/inventory/transfer-orders/${transfer.id}`);
    expect(transferred).toMatchObject({ status: 'transferred', transferred_by: { id: checkerUser.id } });
    expect(movementCount()).toBe('1');
    const sourceAfter = await stockAt(warehouseChecker, itemId, targetLocationId);
    const destinationAfter = await stockAt(warehouseChecker, itemId, fixture.location);
    const milli = (value) => {
      const [whole, fraction = ''] = String(value).split('.');
      return BigInt(whole) * 1000n + BigInt(fraction.padEnd(3, '0').slice(0, 3));
    };
    expect(milli(sourceBefore) - milli(sourceAfter)).toBe(milli(quantity));
    expect(milli(destinationAfter) - milli(destinationBefore)).toBe(milli(quantity));
    const releasedLevel = (await ok(warehouseChecker, 'GET', `/inventory/stock-levels?item_id=${itemId}&per_page=100`))
      .find((level) => level.location?.id === fixture.location);
    expect(releasedLevel?.quality_held_quantity).toBe('0.000');
    expect(psql(`select count(*) from audit_logs a join transfer_orders t on t.id=a.model_id where a.model_type like '%TransferOrder' and t.transfer_number='${transfer.transfer_number}' and a.action='updated' and a.user_id=(select id from users where email='warehouse-checker@ogami.test') and a.new_values->>'status'='transferred'`, fixture.database)).toBe('1');
    const auditAfterExecute = transferAuditCount();
    const repeatedExecution = await request(warehouseChecker, 'POST', `/inventory/transfer-orders/${transfer.id}/execute`, {});
    expect(repeatedExecution.status).toBe(422);
    expect((await ok(warehouseChecker, 'GET', `/inventory/transfer-orders/${transfer.id}`)).status).toBe('transferred');
    expect(movementCount()).toBe('1');
    expect(transferAuditCount()).toBe(auditAfterExecute);
    expect(await stockAt(warehouseChecker, itemId, targetLocationId)).toBe(sourceAfter);
    expect(await stockAt(warehouseChecker, itemId, fixture.location)).toBe(destinationAfter);
    expect((await ok(manager, 'GET', `/return-management/return-requests/${completedRma.id}`)).status).toBe('completed');
    passed('Warehouse checker transfers MRB-released return stock to finished goods once');
  };
  const rmaActionViaUi = async (page, action, confirm, path) => {
    await page.goto(`/return-management/${rmaId}`, { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: action, exact: true }).click();
    const dialog = page.getByRole('dialog');
    const responsePromise = page.waitForResponse((response) =>
      response.request().method() === 'POST'
      && response.url().endsWith(`/api/v1${url}${path}`));
    await dialog.getByRole('button', { name: confirm, exact: true }).click();
    const response = await responsePromise;
    const body = await response.json();
    expect(response.status(), JSON.stringify(body)).toBe(200);
    return body.data;
  };

  let rma = await ok(manager, 'GET', url);
  expect(rma.status).toBe('received');
  const staged = await rmaActionViaUi(qc, 'Stage Quality Handoff', 'Stage Handoff', '/inspect');
  expect(staged.status).toBe('inspected');
  expect(staged.inspection_handoff.status).toBe('generated');
  rma = await ok(manager, 'GET', url);
  expect(rma).toMatchObject({
    status: 'inspected',
    disposition_status: null,
    stock_movement: null,
    inspection_handoff: { status: 'generated' },
  });
  expect(rma.inspections.length).toBeGreaterThan(0);
  expect(rma.inspections.every((inspection) => ['draft', 'in_progress'].includes(inspection.status))).toBe(true);
  passed('Quality stages the return through its role UI; RMA remains undisposed with draft inspections');

  const auditBeforeEarlyDisposition = auditCount(rma.rma_number);
  const quarantineBeforeEarlyDisposition = await Promise.all(rma.items.map((item) =>
    stockAt(warehouse, item.item_id, fixture.quarantine)));
  const earlyDisposition = await request(manager, 'POST', `${url}/dispose`, {
    dispositions: rma.items.map((item) => ({ item_id: item.id, disposition: 'restock' })),
    create_replacement_po: false,
  });
  expect(earlyDisposition.status).toBe(422);
  const unchangedBeforeQuality = await ok(manager, 'GET', url);
  expect(unchangedBeforeQuality).toMatchObject({ status: 'inspected', disposition_status: null, stock_movement: null, credit_note: null });
  expect(unchangedBeforeQuality.items.every((item) => item.mrb === null && item.moved_quantity === null && item.stock_movement_id === null)).toBe(true);
  expect(mrbCount(rma.rma_number)).toBe('0');
  expect(auditCount(rma.rma_number)).toBe(auditBeforeEarlyDisposition);
  expect(await Promise.all(rma.items.map((item) => stockAt(warehouse, item.item_id, fixture.quarantine)))).toEqual(quarantineBeforeEarlyDisposition);

  await manager.goto(`/return-management/${rmaId}`, { waitUntil: 'domcontentloaded' });
  await expect(manager.getByText(rma.rma_number, { exact: true }).first()).toBeVisible();
  // This expectation exposed the current UI defect: backend blocks disposition,
  // but the RMA page still offers the action while Quality reviews are pending.
  await expect(manager.getByRole('button', { name: 'Dispose Items', exact: true })).toHaveCount(0);
  await expect(manager.getByRole('status').filter({ hasText: 'Disposition is blocked until Quality completes and reviews every product-linked return inspection.' })).toBeVisible();
  passed('Pending Quality inspections block disposition in both the server and RMA UI');

  for (const row of rma.inspections) {
    let inspection = await ok(qc, 'GET', `/quality/inspections/${row.id}`);
    expect(inspection.inspection_mode).toBe('per_unit');
    expect(inspection.measurements.length).toBeGreaterThan(0);
    const qcUser = await ok(qc, 'GET', '/auth/user');
    const parameterNames = [...new Set(inspection.measurements.map((measurement) => measurement.parameter_name))];
    await qc.goto(`/quality/inspections/${row.id}`, { waitUntil: 'domcontentloaded' });
    for (const parameterName of parameterNames) {
      await qc.getByRole('button', { name: `PASS ${parameterName}`, exact: true }).click();
    }
    const resultPromise = qc.waitForResponse((response) =>
      response.request().method() === 'POST'
      && response.url().endsWith(`/api/v1/quality/inspections/${row.id}/item-results`));
    await qc.getByRole('button', { name: 'Submit inspection', exact: true }).click();
    const resultResponse = await resultPromise;
    const resultBody = await resultResponse.json();
    expect(resultResponse.status(), JSON.stringify(resultBody)).toBe(200);
    inspection = resultBody.data;
    expect(inspection).toMatchObject({ status: 'awaiting_review', recorded_result: 'passed' });
    expect(inspection.result_authors.some((author) => author.id === qcUser.id)).toBe(true);

    const selfReview = await request(qc, 'PATCH', `/quality/inspections/${row.id}/review`, { decision: 'passed' });
    expect(selfReview.status).toBe(403);
    const checkerUser = await ok(checker, 'GET', '/auth/user');
    await checker.goto(`/quality/inspections/${row.id}`, { waitUntil: 'domcontentloaded' });
    await checker.getByRole('button', { name: 'Approve pass', exact: true }).click();
    const reviewDialog = checker.getByRole('dialog');
    const reviewPromise = checker.waitForResponse((response) =>
      response.request().method() === 'PATCH'
      && response.url().endsWith(`/api/v1/quality/inspections/${row.id}/review`));
    await reviewDialog.getByRole('button', { name: 'Approve pass', exact: true }).click();
    const reviewResponse = await reviewPromise;
    const reviewBody = await reviewResponse.json();
    expect(reviewResponse.status(), JSON.stringify(reviewBody)).toBe(200);
    inspection = await ok(checker, 'GET', `/quality/inspections/${row.id}`);
    expect(inspection.status).toBe('passed');
    expect(inspection.reviewer.id).not.toBe(inspection.inspector.id);
    expect(inspection.reviewer.id).toBe(checkerUser.id);
    expect(inspection.result_authors.map((author) => author.id)).not.toContain(inspection.reviewer.id);
  }

  rma = await ok(manager, 'GET', url);
  expect(rma).toMatchObject({ status: 'inspected', disposition_status: null, stock_movement: null });
  expect(rma.inspections.every((inspection) => inspection.status === 'passed')).toBe(true);
  expect(rma.items.every((item) => item.mrb === null && item.moved_quantity === null)).toBe(true);
  passed('QC records return measurements and a separate checker approves each inspection before disposition');

  const auditBeforeDisposition = auditCount(rma.rma_number);
  const quarantineBeforeDisposition = await Promise.all(rma.items.map((item) => stockAt(warehouse, item.item_id, fixture.quarantine)));
  const goodStockBeforeDisposition = await Promise.all(rma.items.map((item) => stockAt(warehouse, item.item_id, targetLocationId)));
  await manager.goto(`/return-management/${rmaId}`, { waitUntil: 'domcontentloaded' });
  await manager.getByRole('button', { name: 'Dispose Items', exact: true }).click();
  const disposeDialog = manager.getByRole('dialog');
  const dispositionFields = disposeDialog.getByRole('combobox', { name: 'Disposition', exact: true });
  await expect(dispositionFields.first()).toBeVisible();
  expect(await dispositionFields.count()).toBe(rma.items.length);
  for (const field of await dispositionFields.all()) {
    await expect.poll(() => field.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
    await field.selectOption('restock');
  }
  await expect(disposeDialog.getByRole('combobox', { name: 'Restock location', exact: true })).toHaveCount(0);
  const disposePromise = manager.waitForResponse((response) =>
    response.request().method() === 'POST'
    && response.url().endsWith(`/api/v1${url}/dispose`));
  await disposeDialog.getByRole('button', { name: 'Record Disposition', exact: true }).click();
  const disposeResponse = await disposePromise;
  const disposeBody = await disposeResponse.json();
  expect(disposeResponse.status(), JSON.stringify(disposeBody)).toBe(200);
  rma = await ok(manager, 'GET', url);
  expect(rma).toMatchObject({ status: 'inspected', disposition_status: 'disposed' });
  expect(rma.items.every((item) => item.disposition === 'restock' && item.quarantine_status === 'held' && item.moved_quantity === null && item.stock_movement_id === null)).toBe(true);
  expect(rma.items.every((item) => item.mrb?.status === 'held' && item.mrb.release_movement_id === null)).toBe(true);
  expect(mrbCount(rma.rma_number)).toBe(String(rma.items.length));
  expect(Number(auditCount(rma.rma_number))).toBeGreaterThan(Number(auditBeforeDisposition));
  expect(await Promise.all(rma.items.map((item) => stockAt(warehouse, item.item_id, fixture.quarantine)))).toEqual(quarantineBeforeDisposition);
  expect(await Promise.all(rma.items.map((item) => stockAt(warehouse, item.item_id, targetLocationId)))).toEqual(goodStockBeforeDisposition);
  passed('Disposition creates one held MRB per customer-return line and leaves stock quarantined');

  const auditBeforeEarlyCompletion = auditCount(rma.rma_number);
  const earlyCompletion = await request(manager, 'POST', `${url}/complete`, {});
  expect(earlyCompletion.status).toBe(422);
  expect(earlyCompletion.body.message).toMatch(/completed MRB disposition/i);
  const unchangedBeforeMrbRelease = await ok(manager, 'GET', url);
  expect(unchangedBeforeMrbRelease).toMatchObject({ status: 'inspected', disposition_status: 'disposed', credit_note: null });
  expect(unchangedBeforeMrbRelease.items.every((item) => item.mrb?.status === 'held' && item.moved_quantity === null && item.stock_movement_id === null)).toBe(true);
  expect(auditCount(rma.rma_number)).toBe(auditBeforeEarlyCompletion);
  for (const item of rma.items) expect(movementCount(item.mrb.mrb_number)).toBe('0');
  passed('Server rejects RMA completion while its MRB is held, with no movement or audit write');

  for (const item of rma.items) {
    const mrbId = item.mrb.id;
    const mrbNumber = item.mrb.mrb_number;
    const detailUrl = `/inventory/mrb/${mrbId}`;
    let mrb = await ok(warehouse, 'GET', detailUrl);
    expect(mrb).toMatchObject({
      id: mrbId,
      mrb_number: mrbNumber,
      status: 'held',
      quantity: item.returned_quantity,
      held_by_id: (await ok(manager, 'GET', '/auth/user')).id,
      item: { id: item.item_id },
      quarantine_location: { id: fixture.quarantine },
      return_request: { id: rmaId, rma_number: rma.rma_number, line_id: item.id },
    });
    expect(mrb.hold_movement_id).toBeTruthy();
    expect(movementCount(mrbNumber)).toBe('0');
    const heldAuditCount = mrbAuditCount(mrbNumber);
    const quarantineAtHold = await stockAt(warehouse, item.item_id, fixture.quarantine);
    const goodAtHold = await stockAt(warehouse, item.item_id, targetLocationId);

    const qcMrb = await ok(qc, 'GET', detailUrl);
    expect(qcMrb.actions).toMatchObject({ can_release: true, can_release_good_stock: false });
    await qc.goto(detailUrl, { waitUntil: 'domcontentloaded' });
    await qc.getByRole('button', { name: 'Release', exact: true }).click();
    const qcReleaseDialog = qc.getByRole('dialog');
    await expect(qcReleaseDialog.getByRole('status').filter({ hasText: 'Another independent reviewer must release good stock; only scrap is available to your account.' })).toBeVisible();
    await expect(qcReleaseDialog.getByRole('combobox', { name: 'Disposition', exact: true }).locator('option[value="use_as_is"]')).toHaveCount(0);
    await qcReleaseDialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    const blockedRelease = await request(qc, 'POST', `${detailUrl}/release`, {
      disposition: 'use_as_is',
      target_location_id: targetLocationId,
      evidence_reference: 'QC-RETURN-REINSPECTION',
      inspected_quantity: item.returned_quantity,
      recovery_passed: true,
    });
    expect(blockedRelease.status).toBe(422);
    expect(blockedRelease.body.message).toMatch(/independent of every customer-return inspection maker/i);
    mrb = await ok(qc, 'GET', detailUrl);
    expect(mrb).toMatchObject({ status: 'held', release_movement_id: null, release_inspected_quantity: null, release_evidence_reference: null });
    expect(await stockAt(qc, item.item_id, fixture.quarantine)).toBe(quarantineAtHold);
    expect(await stockAt(qc, item.item_id, targetLocationId)).toBe(goodAtHold);
    expect(movementCount(mrbNumber)).toBe('0');
    expect(mrbAuditCount(mrbNumber)).toBe(heldAuditCount);

    const partialReinspection = await request(warehouse, 'POST', `${detailUrl}/release`, {
      disposition: 'use_as_is',
      target_location_id: targetLocationId,
      evidence_reference: `RMA-${rma.rma_number}-PARTIAL-REINSPECTION`,
      inspected_quantity: (Number(item.returned_quantity) - 0.001).toFixed(3),
      recovery_passed: true,
    });
    expect(partialReinspection.status).toBe(422);
    expect(partialReinspection.body.message).toBe('Good-stock release requires completed reinspection of the entire held quantity and an evidence reference.');
    mrb = await ok(warehouse, 'GET', detailUrl);
    expect(mrb).toMatchObject({ status: 'held', release_movement_id: null, release_inspected_quantity: null, release_evidence_reference: null });
    expect(await stockAt(warehouse, item.item_id, fixture.quarantine)).toBe(quarantineAtHold);
    expect(await stockAt(warehouse, item.item_id, targetLocationId)).toBe(goodAtHold);
    expect(movementCount(mrbNumber)).toBe('0');
    expect(mrbAuditCount(mrbNumber)).toBe(heldAuditCount);

    await warehouse.goto('/inventory/mrb', { waitUntil: 'domcontentloaded' });
    await expect(warehouse.getByRole('heading', { name: 'MRB / Quarantine' })).toBeVisible();
    const queueRow = warehouse.getByRole('row').filter({ hasText: mrbNumber });
    await expect(queueRow).toBeVisible();
    await queueRow.click();
    await expect(warehouse).toHaveURL(new RegExp(`/inventory/mrb/${mrbId}$`));
    await expect(warehouse.getByRole('button', { name: 'Release', exact: true })).toBeVisible();
    const warehouseUser = await ok(warehouse, 'GET', '/auth/user');
    const qcInspection = await ok(qc, 'GET', `/quality/inspections/${rma.inspections[0].id}`);
    expect(warehouseUser.id).not.toBe(qcInspection.inspector.id);

    await warehouse.getByRole('button', { name: 'Release', exact: true }).click();
    const releaseDialog = warehouse.getByRole('dialog');
    await releaseDialog.getByRole('combobox', { name: 'Disposition', exact: true }).selectOption('use_as_is');
    await releaseDialog.getByLabel('Completed reinspection evidence reference').fill(`RMA-${rma.rma_number}-FULL-REINSPECTION`);
    await releaseDialog.getByLabel('Completed reinspection confirms all held material passes every requirement.').check();
    const targetLocation = releaseDialog.getByLabel('Target good location');
    await expect(targetLocation.locator(`option[value="${targetLocationId}"]`)).toHaveCount(1);
    await targetLocation.selectOption(targetLocationId);
    await releaseDialog.getByLabel('Notes').fill('Independent full-quantity customer-return reinspection.');
    const releasePromise = warehouse.waitForResponse((response) =>
      response.request().method() === 'POST'
      && response.url().endsWith(`/api/v1${detailUrl}/release`));
    await releaseDialog.getByRole('button', { name: 'Release', exact: true }).click();
    const releaseResponse = await releasePromise;
    const releaseBody = await releaseResponse.json();
    expect(releaseResponse.status(), JSON.stringify(releaseBody)).toBe(200);
    const releasedRecord = releaseBody.data;
    expect(releasedRecord).toMatchObject({
      status: 'released',
      disposition: 'use_as_is',
      quantity: item.returned_quantity,
      release_inspected_quantity: item.returned_quantity,
      release_evidence_reference: `RMA-${rma.rma_number}-FULL-REINSPECTION`,
      release_location: { id: targetLocationId },
      released_by: warehouseUser.name,
    });
    expect(releasedRecord.release_movement_id).toBeTruthy();
    const releaseAudit = psql(`select count(*) from audit_logs a join material_review_records m on m.id=a.model_id where a.model_type like '%MaterialReviewRecord' and m.mrb_number='${mrbNumber}' and a.action='updated' and a.user_id=(select id from users where email='warehouse@ogami.test') and a.new_values->>'status'='released'`, fixture.database);
    expect(releaseAudit).toBe('1');
    expect(movementCount(mrbNumber)).toBe('1');

    const quarantineAfterRelease = await stockAt(warehouse, item.item_id, fixture.quarantine);
    const goodAfterRelease = await stockAt(warehouse, item.item_id, targetLocationId);
    const quantity = Number(item.returned_quantity);
    expect(Number(quarantineAfterRelease)).toBe(Number(quarantineAtHold) - quantity);
    expect(Number(goodAfterRelease)).toBe(Number(goodAtHold) + quantity);
    const releasedLevels = await ok(warehouse, 'GET', `/inventory/stock-levels?item_id=${item.item_id}&per_page=100`);
    const releasedLevel = releasedLevels.find((level) => level.location?.id === targetLocationId);
    expect(releasedLevel?.quality_held_quantity).toBe('0.000');
    await expect(warehouse.getByRole('button', { name: 'Release', exact: true })).toHaveCount(0);

    const releasedRma = await ok(manager, 'GET', url);
    const releasedLine = releasedRma.items.find((line) => line.id === item.id);
    expect(releasedLine).toMatchObject({
      quarantine_status: 'released',
      disposition: 'restock',
      moved_quantity: item.returned_quantity,
      stock_movement_id: releasedRecord.release_movement_id,
      mrb: { status: 'released', release_movement_id: releasedRecord.release_movement_id },
    });
    const auditAfterRelease = mrbAuditCount(mrbNumber);
    const duplicateRelease = await request(warehouse, 'POST', `${detailUrl}/release`, {
      disposition: 'use_as_is',
      target_location_id: targetLocationId,
      evidence_reference: `RMA-${rma.rma_number}-FULL-REINSPECTION`,
      inspected_quantity: item.returned_quantity,
      recovery_passed: true,
    });
    expect(duplicateRelease.status).toBe(422);
    expect((await ok(warehouse, 'GET', detailUrl)).status).toBe('released');
    expect(movementCount(mrbNumber)).toBe('1');
    expect(mrbAuditCount(mrbNumber)).toBe(auditAfterRelease);
    expect(await stockAt(warehouse, item.item_id, fixture.quarantine)).toBe(quarantineAfterRelease);
    expect(await stockAt(warehouse, item.item_id, targetLocationId)).toBe(goodAfterRelease);
  }

  rma = await ok(manager, 'GET', url);
  expect(rma.items.every((item) => item.mrb?.status === 'released' && item.mrb.release_movement_id && item.moved_quantity === item.returned_quantity)).toBe(true);
  await manager.goto(`/return-management/${rmaId}`, { waitUntil: 'domcontentloaded' });
  await expect(manager.getByRole('button', { name: 'Complete RMA', exact: true })).toBeVisible();
  const completionResponse = await rmaActionViaUi(manager, 'Complete RMA', 'Confirm Complete', '/complete');
  expect(completionResponse).toMatchObject({ status: 'completed', disposition_status: 'disposed' });
  const completed = await ok(manager, 'GET', url);
  expect(completed.credit_note ?? null).toBeNull();
  expect(completed.items.every((item) => item.mrb?.status === 'released' && item.moved_quantity === item.returned_quantity)).toBe(true);
  for (const item of completed.items) expect(movementCount(item.mrb.mrb_number)).toBe('1');
  const finalAuditCount = auditCount(completed.rma_number);
  const duplicateCompletion = await request(manager, 'POST', `${url}/complete`, {});
  expect(duplicateCompletion.status).toBe(422);
  const afterDuplicateCompletion = await ok(manager, 'GET', url);
  expect(afterDuplicateCompletion.status).toBe('completed');
  expect(auditCount(completed.rma_number)).toBe(finalAuditCount);
  passed('RMA completes through Customer Service UI only after independent MRB release; repeat completion creates no second movement');
  if (targetLocationId !== fixture.location) {
    await transferReleasedStockToFinishedGoods(completed);
  }
};
