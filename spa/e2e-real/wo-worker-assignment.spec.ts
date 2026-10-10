import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { assignCrewThroughUi, loginAs, statefulHeaders, statefulCsrfHeaders } from './helpers';

const fixture = JSON.parse(readFileSync('../api/storage/app/wo-crew-e2e.json', 'utf8'));
if (process.env.E2E_BASE_URL !== 'http://localhost:18080')
  throw new Error('Crew test requires isolated audit application.');

test('real crew assignment, schedule ownership, output, and handover', async ({ browser }) => {
  test.setTimeout(180_000);
  const pages: Record<string, Page> = {};
  for (const [name, account] of Object.entries(fixture.accounts)) {
    const context = await browser.newContext();
    const page = await context.newPage();
    await loginAs(page, (account as { email: string }).email, 'Crew-E2E-Test-2026!');
    pages[name] = page;
  }
  const api = async (page: Page, method: string, path: string, data?: unknown, key?: string) =>
    page.request.fetch(`/api/v1${path}`, {
      method,
      headers:
        method === 'GET'
          ? statefulHeaders()
          : {
              ...(await statefulCsrfHeaders(page)),
              ...(key ? { 'X-Idempotency-Key': key } : {}),
            },
      data,
    });
  const executionData = async (woId: string) => {
    const response = await api(pages.manager, 'GET', `/production/work-orders/${woId}/crew`);
    expect(response.status(), await response.text()).toBe(200);
    return { expected_crew_id: (await response.json()).data.current?.id ?? null };
  };
  const crewData = (
    employeeIds = [fixture.accounts.recorder.employee_id, fixture.accounts.support.employee_id],
  ) => ({
    employee_ids: employeeIds,
    recorder_employee_id: fixture.accounts.recorder.employee_id,
    minimum_workers: 2,
    maximum_workers: 3,
    expected_crew_id: null,
    reason: 'PPC scheduled two-worker crew',
  });
  try {
    const initialWoResponse = await api(pages.ppc, 'GET', `/production/work-orders/${fixture.wo}`);
    expect((await initialWoResponse.json()).data.status).toBe('confirmed');
    const initialCrewResponse = await api(
      pages.ppc,
      'GET',
      `/production/work-orders/${fixture.wo}/crew`,
    );
    const initialCrew = (await initialCrewResponse.json()).data;
    expect(initialCrew.current).toBeNull();
    expect(initialCrew.history).toHaveLength(0);
    const employeeOptions = (
      await (
        await api(pages.ppc, 'GET', `/production/work-orders/${fixture.wo}/crew-options`)
      ).json()
    ).data;
    expect(employeeOptions).not.toContainEqual(
      expect.objectContaining({ id: fixture.accounts.manager.employee_id }),
    );
    await pages.ppc.goto(`/production/work-orders/${fixture.wo}`);
    await expect(
      pages.ppc.getByRole('button', { name: 'Assign workers', exact: true }),
    ).toBeVisible();
    await expect(
      pages.ppc.getByRole('button', { name: 'Change workers', exact: true }),
    ).toHaveCount(0);

    const wrongDepartment = await api(
      pages.ppc,
      'POST',
      `/production/work-orders/${fixture.wo}/crew`,
      crewData([fixture.accounts.recorder.employee_id, fixture.accounts.outsider.employee_id]),
    );
    expect(wrongDepartment.status()).toBe(422);
    expect(
      (await (await api(pages.ppc, 'GET', `/production/work-orders/${fixture.wo}/crew`)).json())
        .data,
    ).toEqual(initialCrew);

    const invalidSize = await api(pages.ppc, 'POST', `/production/work-orders/${fixture.wo}/crew`, {
      ...crewData(),
      minimum_workers: 3,
    });
    expect(invalidSize.status()).toBe(422);
    expect(
      (await (await api(pages.ppc, 'GET', `/production/work-orders/${fixture.wo}/crew`)).json())
        .data,
    ).toEqual(initialCrew);

    const assigned = await assignCrewThroughUi(
      pages.ppc,
      fixture.wo,
      [pages.recorder, pages.support],
      {
        reason: 'PPC scheduled two-worker crew',
      },
    );
    const crew = assigned.current;
    expect(crew.members).toHaveLength(2);

    const overlap = await api(
      pages.ppc,
      'POST',
      `/production/work-orders/${fixture.overlap_wo}/crew`,
      crewData(),
    );
    expect(overlap.status()).toBe(422);
    expect((await overlap.json()).message).toContain('overlaps');

    const previewResponse = await api(
      pages.ppc,
      'GET',
      `/production/work-orders/${fixture.future_wo}/assignment-suggestion`,
    );
    expect(previewResponse.status()).toBe(200);
    const preview = (await previewResponse.json()).data;
    expect(preview.blockers).toHaveLength(0);
    expect(preview.worker_options).not.toContainEqual(
      expect.objectContaining({ id: fixture.accounts.manager.employee_id }),
    );
    await pages.ppc.goto('/production/schedule');
    await pages.ppc.getByRole('tab', { name: /Schedule list/ }).click();
    const scheduledRow = pages.ppc
      .getByRole('row')
      .filter({ has: pages.ppc.locator(`a[href="/production/work-orders/${fixture.future_wo}"]`) });
    await expect(pages.ppc.getByRole('button', { name: /Confirm selected/ })).toHaveCount(0);
    await expect(scheduledRow.getByRole('checkbox')).toHaveCount(0);
    await expect(scheduledRow.getByRole('button', { name: 'Review and assign' })).toHaveCount(0);
    await scheduledRow.locator(`a[href="/production/work-orders/${fixture.future_wo}"]`).click();
    await pages.ppc.getByRole('button', { name: 'Confirm', exact: true }).click();
    const reviewDialog = pages.ppc.getByRole('dialog');
    await expect(
      reviewDialog.getByRole('heading', { name: 'Review and confirm work order' }),
    ).toBeVisible();
    await expect(reviewDialog.getByLabel('Machine', { exact: true })).toHaveCount(0);
    await expect(reviewDialog.getByLabel('Number of workers')).toHaveValue('');
    await expect(reviewDialog.getByLabel('Maximum workers')).toHaveCount(0);
    await reviewDialog.getByLabel('Number of workers').selectOption('2');
    await expect(reviewDialog.getByText(/Selected: 2/)).toBeVisible();
    await expect(reviewDialog.getByRole('combobox', { name: 'Add worker' })).toHaveCount(0);
    await reviewDialog.getByRole('button', { name: 'Manual assign' }).click();
    await expect(reviewDialog.getByLabel('Number of workers')).toHaveCount(0);
    await reviewDialog
      .getByRole('button', { name: /^Remove / })
      .first()
      .click();
    const addWorker = reviewDialog.getByRole('combobox', { name: 'Add worker' });
    await addWorker.fill('No shift');
    await expect(
      pages.ppc.getByRole('option', { name: new RegExp(`No shift ${fixture.tag}`) }),
    ).toBeDisabled();
    await addWorker.fill(`Backup ${fixture.tag}`);
    await pages.ppc.getByRole('option', { name: new RegExp(`Backup ${fixture.tag}`) }).click();
    await expect(reviewDialog.getByLabel('Handover reason')).toHaveCount(0);
    await reviewDialog.locator('.overflow-y-auto').evaluate((element) => {
      element.scrollTop = 0;
    });
    await pages.ppc.screenshot({
      path: 'e2e-real/results/wo-assignment-review-desktop.png',
      fullPage: true,
    });
    await pages.ppc.setViewportSize({ width: 390, height: 844 });
    await reviewDialog.locator('.overflow-y-auto').evaluate((element) => {
      element.scrollTop = 0;
    });
    await pages.ppc.screenshot({
      path: 'e2e-real/results/wo-assignment-review-mobile.png',
      fullPage: true,
    });
    await pages.ppc.setViewportSize({ width: 1440, height: 900 });
    await reviewDialog
      .getByRole('button', { name: new RegExp(`Remove Backup ${fixture.tag}`) })
      .click();
    await reviewDialog.getByRole('button', { name: 'Auto assign' }).click();
    await expect(reviewDialog.getByText(/Selected: 2/)).toBeVisible();
    const applyResponse = pages.ppc.waitForResponse(
      (response) =>
        response.url().includes('/assignment-apply') && response.request().method() === 'POST',
    );
    await reviewDialog.getByRole('button', { name: 'Apply assignment and confirm' }).click();
    const futureAssigned = await applyResponse;
    expect(futureAssigned.status(), await futureAssigned.text()).toBe(200);
    let futureCrew = (await futureAssigned.json()).data.current;
    expect(futureCrew.members).toHaveLength(2);
    const approvedState = (
      await (
        await api(pages.ppc, 'GET', `/production/work-orders/${fixture.future_wo}/crew`)
      ).json()
    ).data;
    expect(approvedState.schedule.id).toBe(preview.schedule.id);
    expect(approvedState.schedule.status).toBe('confirmed');
    const futurePayload = futureAssigned.request().postDataJSON();
    await expect(reviewDialog).toHaveCount(0);
    await pages.ppc.goto(`/production/work-orders/${fixture.wo}`);
    const duplicateApply = await api(
      pages.ppc,
      'POST',
      `/production/work-orders/${fixture.future_wo}/assignment-apply`,
      futurePayload,
    );
    expect(duplicateApply.status(), await duplicateApply.text()).toBe(200);
    expect((await duplicateApply.json()).data.current.id).toBe(futureCrew.id);

    await pages.ppc.reload();
    await expect(
      pages.ppc.getByRole('heading', { name: 'Assigned workers', exact: true }),
    ).toHaveCount(0);
    const workers = pages.ppc.getByText('Assigned workers', { exact: true }).locator('..');
    await expect(workers).toContainText(fixture.accounts.recorder.name);
    await expect(workers).toContainText(fixture.accounts.support.name);
    await expect(workers).toContainText('(Output recorder)');
    await pages.ppc.screenshot({
      path: 'e2e-real/results/wo-workers-details-desktop.png',
      fullPage: true,
    });
    await pages.ppc.setViewportSize({ width: 390, height: 844 });
    await pages.ppc.screenshot({
      path: 'e2e-real/results/wo-workers-details-mobile.png',
      fullPage: true,
    });
    await pages.ppc.setViewportSize({ width: 1440, height: 900 });
    await pages.ppc.getByRole('button', { name: 'Change workers' }).click();
    const assignmentDialog = pages.ppc.getByRole('dialog');
    await expect(assignmentDialog.getByRole('heading', { name: 'Change workers' })).toBeVisible();
    await expect(assignmentDialog.getByLabel('Number of workers')).toHaveCount(0);
    await expect(assignmentDialog.getByLabel('Handover reason')).toHaveCount(0);
    await expect(assignmentDialog.getByLabel('Reviewed production start')).toHaveCount(0);
    await expect(assignmentDialog.getByRole('button', { name: 'Save workers' })).toBeDisabled();
    await expect(
      assignmentDialog.getByText(`${fixture.accounts.recorder.name} · ${fixture.shift}`, {
        exact: true,
      }),
    ).toBeVisible();
    await expect(assignmentDialog.getByLabel('Output recorder')).toHaveValue(
      fixture.accounts.recorder.employee_id,
    );
    await pages.ppc.screenshot({
      path: 'e2e-real/results/wo-change-workers-desktop.png',
      fullPage: true,
    });
    await pages.ppc.setViewportSize({ width: 390, height: 844 });
    await pages.ppc.screenshot({
      path: 'e2e-real/results/wo-change-workers-mobile.png',
      fullPage: true,
    });
    await pages.ppc.setViewportSize({ width: 1440, height: 900 });
    await assignmentDialog.getByRole('button', { name: 'Close modal' }).click();

    await pages.recorder.goto(`/self-service/work-orders/${fixture.wo}`);
    await expect(pages.recorder.getByText(fixture.shift).first()).toBeVisible();
    expect(
      (await api(pages.outsider, 'GET', `/production/my-work-orders/${fixture.wo}`)).status(),
    ).toBe(404);
    expect((await api(pages.recorder, 'GET', '/production/work-orders')).status()).toBe(403);

    const started = await api(
      pages.manager,
      'POST',
      `/production/work-orders/${fixture.wo}/start`,
      await executionData(fixture.wo),
    );
    expect(started.status(), await started.text()).toBe(200);
    const inspection = (await started.json()).data.inspections.find(
      (row: { stage: string }) => row.stage === 'in_process',
    );
    expect(inspection).toBeTruthy();
    const qcDetail = await api(pages.qc, 'GET', `/quality/inspections/${inspection.id}`);
    const qc = (await qcDetail.json()).data;
    const items = qc.measurements.map((row: { id: string }) => ({ id: row.id, result: 'passed' }));
    expect(
      (
        await api(pages.qc, 'POST', `/quality/inspections/${inspection.id}/item-results`, { items })
      ).status(),
    ).toBe(200);
    const passed = await api(
      pages.qc,
      'POST',
      `/quality/inspections/${inspection.id}/batch-result`,
      {
        result: 'passed',
        coverage_confirmed: true,
        inspected_quantity: qc.sample_size,
        sample_defect_count: 0,
        critical_defect: false,
        defects_removed_confirmed: false,
      },
    );
    expect(passed.status(), await passed.text()).toBe(200);
    await pages.recorder.reload();
    await expect(pages.recorder.getByRole('heading', { name: 'Record output' })).toBeVisible();
    await pages.support.goto(`/self-service/work-orders/${fixture.wo}`);
    await expect(
      pages.support.getByText(
        'View only. Only the assigned output recorder can record quantities.',
      ),
    ).toBeVisible();

    const output = {
      crew_id: crew.id,
      good_count: 45,
      reject_count: 5,
      defects: [{ defect_type_id: fixture.defect, count: 5 }],
    };
    expect(
      (
        await api(
          pages.support,
          'POST',
          `/production/my-work-orders/${fixture.wo}/outputs`,
          output,
          'support-denied',
        )
      ).status(),
    ).toBe(422);
    const saved = await api(
      pages.recorder,
      'POST',
      `/production/my-work-orders/${fixture.wo}/outputs`,
      output,
      'crew-e2e-batch',
    );
    expect(saved.status(), await saved.text()).toBe(200);
    const savedId = (await saved.json()).data.id;

    await pages.manager.goto(`/production/work-orders/${fixture.wo}`);
    await pages.manager.getByRole('button', { name: 'Change workers', exact: true }).click();
    const handoverDialog = pages.manager.getByRole('dialog');
    await handoverDialog
      .getByLabel('Output recorder')
      .selectOption(fixture.accounts.support.employee_id);
    await expect(handoverDialog.getByRole('button', { name: 'Save workers' })).toBeDisabled();
    await handoverDialog.getByLabel('Handover reason').fill('Manager recorder handover');
    const handoverResponse = pages.manager.waitForResponse(
      (response) =>
        response.url().endsWith(`/production/work-orders/${fixture.wo}/crew`) &&
        response.request().method() === 'POST',
    );
    await handoverDialog.getByRole('button', { name: 'Save workers' }).click();
    const handover = await handoverResponse;
    expect(handover.status(), await handover.text()).toBe(200);
    expect(
      (
        await api(pages.ppc, 'POST', `/production/work-orders/${fixture.wo}/crew`, crewData())
      ).status(),
    ).toBe(403);
    const replayAfterHandover = await api(
      pages.recorder,
      'POST',
      `/production/my-work-orders/${fixture.wo}/outputs`,
      output,
      'crew-e2e-batch',
    );
    expect((await replayAfterHandover.json()).data.id).toBe(savedId);
    expect(
      (
        await api(
          pages.recorder,
          'POST',
          `/production/my-work-orders/${fixture.wo}/outputs`,
          output,
          'stale-crew-new',
        )
      ).status(),
    ).toBe(422);
    const detail = await api(pages.support, 'GET', `/production/my-work-orders/${fixture.wo}`);
    expect((await detail.json()).data.can_record).toBe(true);
    expect(
      (
        await api(
          pages.recorder,
          'GET',
          `/production/my-output-receipts/${fixture.wo}?idempotency_key=crew-e2e-batch`,
        )
      ).status(),
    ).toBe(200);

    await pages.support.reload();
    await expect(pages.support.getByRole('heading', { name: 'Record output' })).toBeVisible();
    await expect(pages.support.getByText('Loading output options…')).toHaveCount(0);
    await expect(pages.support.getByText('Loading receipts…')).toHaveCount(0);
    await pages.support.screenshot({
      path: 'e2e-real/results/wo-crew-desktop.png',
      fullPage: true,
    });
    await pages.support.setViewportSize({ width: 390, height: 844 });
    await pages.support.reload();
    await expect(pages.support.getByRole('heading', { name: 'Record output' })).toBeVisible();
    await expect(pages.support.getByText('Loading output options…')).toHaveCount(0);
    await expect(pages.support.getByText('Loading receipts…')).toHaveCount(0);
    await pages.support.screenshot({ path: 'e2e-real/results/wo-crew-mobile.png', fullPage: true });

    const futureConfirmed = await api(
      pages.ppc,
      'GET',
      `/production/work-orders/${fixture.future_wo}`,
    );
    expect(futureConfirmed.status(), await futureConfirmed.text()).toBe(200);
    expect((await futureConfirmed.json()).data.status).toBe('confirmed');
    const futureTakeoverCrew = await api(
      pages.manager,
      'GET',
      `/production/work-orders/${fixture.future_wo}/crew`,
    );
    futureCrew = (await futureTakeoverCrew.json()).data.current;
    const backupHandover = await api(
      pages.manager,
      'POST',
      `/production/work-orders/${fixture.future_wo}/crew`,
      {
        employee_ids: [fixture.backup_worker.employee_id],
        recorder_employee_id: fixture.backup_worker.employee_id,
        minimum_workers: 1,
        maximum_workers: 1,
        expected_crew_id: futureCrew.id,
        reason: 'Release shared crew before equipment takeover',
      },
    );
    expect(backupHandover.status(), await backupHandover.text()).toBe(200);

    const takeoverWhileRunning = await api(
      pages.manager,
      'POST',
      `/production/work-orders/${fixture.future_wo}/start`,
      await executionData(fixture.future_wo),
    );
    expect(takeoverWhileRunning.status()).toBe(422);
    expect((await takeoverWhileRunning.json()).message).toContain('Equipment is occupied by WO');
    const paused = await api(pages.manager, 'POST', `/production/work-orders/${fixture.wo}/pause`, {
      reason: 'Crew E2E planned changeover',
      category: 'changeover',
    });
    expect(paused.status(), await paused.text()).toBe(200);
    const takeoverWhilePaused = await api(
      pages.manager,
      'POST',
      `/production/work-orders/${fixture.future_wo}/start`,
      await executionData(fixture.future_wo),
    );
    expect(takeoverWhilePaused.status()).toBe(422);
    expect((await takeoverWhilePaused.json()).message).toContain('Equipment is occupied by WO');

    const released = await api(
      pages.manager,
      'POST',
      `/production/work-orders/${fixture.wo}/release-resources`,
      {
        reason: 'Production manager released paused equipment for the reviewed schedule',
      },
    );
    expect(released.status(), await released.text()).toBe(200);
    expect((await released.json()).data.production_released_at).toBeTruthy();

    const futureStart = new Date(Date.now() - 60_000).toISOString();
    const futureEnd = new Date(Date.now() + 90 * 60_000).toISOString();
    const futureSlot = (
      await (
        await api(pages.ppc, 'GET', `/production/work-orders/${fixture.future_wo}/crew`)
      ).json()
    ).data.schedule;
    const reviewedFuture = await api(
      pages.ppc,
      'POST',
      `/production/work-orders/${fixture.future_wo}/review-slot`,
      {
        expected_schedule_id: futureSlot?.id ?? null,
        expected_schedule_updated_at: futureSlot?.updated_at ?? null,
        scheduled_start: futureStart,
        scheduled_end: futureEnd,
        reason: 'PPC reviewed released mold for next WO',
      },
    );
    expect(reviewedFuture.status(), await reviewedFuture.text()).toBe(200);
    const takeover = await api(
      pages.manager,
      'POST',
      `/production/work-orders/${fixture.future_wo}/start`,
      await executionData(fixture.future_wo),
    );
    expect(takeover.status(), await takeover.text()).toBe(200);

    const replacementStart = new Date(Date.now() + 91 * 60_000).toISOString();
    const replacementEnd = new Date(Date.now() + 151 * 60_000).toISOString();
    const reviewedPaused = await api(
      pages.manager,
      'POST',
      `/production/work-orders/${fixture.wo}/review-slot`,
      {
        expected_schedule_id: null,
        expected_schedule_updated_at: null,
        scheduled_start: replacementStart,
        scheduled_end: replacementEnd,
        reason: 'PPC reviewed remaining production after release',
      },
    );
    expect(reviewedPaused.status(), await reviewedPaused.text()).toBe(200);
    const resumeWhileOccupied = await api(
      pages.manager,
      'POST',
      `/production/work-orders/${fixture.wo}/resume`,
      await executionData(fixture.wo),
    );
    expect(resumeWhileOccupied.status()).toBe(422);
    expect((await resumeWhileOccupied.json()).message).toContain('Equipment is occupied by WO');
  } finally {
    await Promise.allSettled(Object.values(pages).map((page) => page.context().close()));
  }
});
