import { expect, test } from '@playwright/test';
import { mockAuth, ROLES } from './helpers-extended';

for (const width of [1280, 390]) {
  test(`verified recovery evidence and locked actions at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await mockAuth(page, { ...ROLES.maintenance, permissions: [...ROLES.maintenance.permissions, 'maintenance.wo.verify'] });
    let stage: 'execute' | 'pending' | 'closed' = 'execute';
    let submissions = 0;
    const logs: Array<{ id: string; description: string; created_at: string; logger: { id: string; name: string } }> = [];
    const evidence = { diagnosis: 'Worn bearing', repair_action: 'Replaced bearing', functional_test_result: 'Trial cycle passed' };
    await page.route('**/api/v1/maintenance/work-orders/options', (route) => route.fulfill({ json: { data: { statuses: [], types: [], priorities: [] } } }));
    await page.route('**/api/v1/maintenance/work-orders/repair-1', (route) => route.fulfill({ json: { data: {
      id: 'repair-1', mwo_number: 'MWO-RECOVERY-1', maintainable_type: 'machine', type: 'corrective', priority: 'high',
      status: stage === 'closed' ? 'completed' : 'in_progress', verification_required: true,
      available_actions: stage === 'execute' ? ['submit_repair'] : stage === 'pending' ? ['verify_repair'] : [],
      cost: '125.50', description: 'Repair motor', maintainable: { id: 'machine-1', name: 'Motor', code: 'IMM-01' },
      downtime_minutes: stage === 'closed' ? 72 : 0, open_downtime_started_at: stage === 'closed' ? null : '2026-10-09T09:00:00Z', server_time: '2026-10-09T10:00:00Z',
      response_minutes: 10, technician_repair_minutes: stage === 'execute' ? null : 50,
      repair_evidence: stage === 'execute' ? null : evidence, repair_submitted_at: stage === 'execute' ? null : '2026-10-09T10:00:00Z',
      submitted_by: { id: 'tech', name: 'Repair Technician' }, verified_at: stage === 'closed' ? '2026-10-09T10:12:00Z' : null,
      verifier: stage === 'closed' ? { id: 'verifier', name: 'Production Verifier' } : null, logs, spare_parts: [],
      readiness: { equipment_status: stage === 'closed' ? 'idle' : 'maintenance', can_start: false, production_work_order: null, blockers: [] },
    } } }));
    await page.route('**/api/v1/maintenance/work-orders/repair-1/logs', async (route) => {
      expect(route.request().postDataJSON()).toEqual({ description: 'Bearing removed' });
      logs.push({ id: 'log-1', description: 'Bearing removed', created_at: '2026-10-09T09:30:00Z', logger: { id: 'tech', name: 'Repair Technician' } });
      await route.fulfill({ json: { data: logs[0] } });
    });
    await page.route('**/api/v1/maintenance/work-orders/repair-1/submit-repair', async (route) => {
      expect(route.request().postDataJSON()).toEqual(evidence);
      submissions++;
      stage = 'pending';
      await route.fulfill({ json: { data: {} } });
    });
    await page.route('**/api/v1/maintenance/work-orders/repair-1/verify', async (route) => {
      const decision = route.request().postDataJSON();
      if (decision.decision === 'reject') {
        expect(decision.reason).toBe('Repeat functional test');
        stage = 'execute';
      } else stage = 'closed';
      await route.fulfill({ json: { data: {} } });
    });
    await page.goto('/maintenance/work-orders/repair-1');
    await expect(page.getByText('Recovery downtime (provisional)', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Complete', exact: true })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Complete work order' })).toHaveCount(0);
    await page.getByLabel('Work log note').fill('Bearing removed');
    await page.getByRole('button', { name: 'Add work log', exact: true }).click();
    await expect(page.getByText('Bearing removed', { exact: true })).toBeVisible();
    for (const [label, value] of [['Diagnosis', evidence.diagnosis], ['Repair action', evidence.repair_action], ['Functional test result', evidence.functional_test_result]]) await page.getByRole('textbox', { name: label, exact: true }).fill(value);
    await page.getByRole('button', { name: 'Submit repair', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Awaiting verification' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Add work log', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Reject repair', exact: true })).toBeDisabled();
    await page.getByLabel('Rejection reason').fill('Repeat functional test');
    await page.getByRole('button', { name: 'Reject repair', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Submit repair', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Submit repair', exact: true }).click();
    await page.getByRole('button', { name: 'Accept recovery', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Verified recovery' })).toBeVisible();
    await expect(page.getByText(/Verified .*Production Verifier/)).toBeVisible();
    await expect(page.getByText('72 min', { exact: true })).toBeVisible();
    await expect(page.getByText('Recovery downtime (provisional)', { exact: true })).toHaveCount(0);
    expect(submissions).toBe(2);
  });
}

for (const width of [1280, 390]) {
  test(`technician cannot verify recovery at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await mockAuth(page, ROLES.maintenance);
    await page.route('**/api/v1/maintenance/work-orders/options', (route) => route.fulfill({ json: { data: { statuses: [], types: [], priorities: [] } } }));
    await page.route('**/api/v1/maintenance/work-orders/pending-1', (route) => route.fulfill({ json: { data: {
      id: 'pending-1', mwo_number: 'MWO-PENDING-1', status: 'in_progress', type: 'corrective', priority: 'high', maintainable_type: 'machine',
      verification_required: true, available_actions: ['verify_repair'], description: 'Pending acceptance', downtime_minutes: 0, cost: '0.00',
      repair_submitted_at: '2026-10-09T10:00:00Z', logs: [], spare_parts: [],
    } } }));
    await page.goto('/maintenance/work-orders/pending-1');
    await expect(page.getByRole('heading', { name: 'Awaiting verification' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Accept recovery', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Reject repair', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Add work log', exact: true })).toHaveCount(0);
  });
}

for (const width of [1280, 390]) {
  test(`production blocker survives refresh at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await mockAuth(page, ROLES.maintenance);
    await page.route('**/api/v1/maintenance/work-orders/options', (route) => route.fulfill({ json: { data: { statuses: [], types: [], priorities: [] } } }));
    await page.route('**/api/v1/maintenance/work-orders/blocked-1', (route) => route.fulfill({ json: { data: {
      id: 'blocked-1', mwo_number: 'MWO-BLOCKED-1', status: 'open', type: 'corrective', priority: 'high', maintainable_type: 'machine',
      verification_required: true, available_actions: [], description: 'Waiting for production', downtime_minutes: 0, cost: '0.00', logs: [], spare_parts: [],
      readiness: { equipment_status: 'breakdown', can_start: false,
        production_work_order: { id: 'prod-1', wo_number: 'PWO-001', status: 'paused', link: '/production/work-orders/prod-1' },
        blockers: [{ code: 'production_owned', message: 'Production owns this machine.', required_action: 'Production must release resources.' }],
      },
    } } }));
    await page.goto('/maintenance/work-orders/blocked-1');
    await expect(page.getByText('Production owns this machine. Production must release resources.', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Production order PWO-001', exact: true })).toHaveAttribute('href', '/production/work-orders/prod-1');
    await page.reload();
    await expect(page.getByRole('link', { name: 'Production order PWO-001', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: /^(Start|Start work)$/ })).toHaveCount(0);
  });
}
