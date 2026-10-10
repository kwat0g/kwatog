import { expect, test } from './fixtures';
import { loginAs } from './helpers-extended';

test('a late sample preview cannot replace the plan for the selected output batch', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  await page.route('**/quality/inspections/options', route => route.fulfill({ json: { data: { stages: [{ value: 'outgoing', label: 'Outgoing' }], sampling_methods: [{ stage: 'outgoing', value: 'aql', label: 'AQL Level II' }] } } }));
  await page.route('**/crm/products/options**', route => route.fulfill({ json: { data: [{ id: 'product-1', part_number: 'P-1', name: 'Part' }] } }));
  await page.route('**/quality/inspections/work-order-outputs**', route => route.fulfill({ json: { data: [
    { id: 'batch-10', batch_code: 'B-10', good_count: 10 },
    { id: 'batch-20', batch_code: 'B-20', good_count: 20 },
  ] } }));
  let releaseOld!: () => void;
  const oldResponse = new Promise<void>(resolve => { releaseOld = resolve; });
  let oldStarted = false;
  await page.route('**/quality/inspections/aql-preview**', async route => {
    const old = new URL(route.request().url()).searchParams.get('batch_quantity') === '10';
    if (old) { oldStarted = true; await oldResponse; }
    await route.fulfill({ json: { data: { code: old ? 'OLD-PLAN' : 'CURRENT-PLAN', sample_size: old ? 2 : 5, accept: 0, reject: 1 } } });
  });
  try {
    await loginAs(page, 'admin', '/quality/inspections/new');
    await page.getByRole('combobox', { name: 'Stage', exact: true }).selectOption('outgoing');
    await page.getByRole('combobox', { name: 'Product', exact: true }).click();
    await page.getByRole('option', { name: 'P-1 — Part', exact: true }).click();
    await page.getByRole('combobox', { name: 'Output batch', exact: true }).click();
    await page.getByRole('option', { name: /B-10/ }).click();
    await expect.poll(() => oldStarted).toBe(true);
    await page.getByRole('combobox', { name: 'Output batch', exact: true }).click();
    await page.getByRole('option', { name: /B-20/ }).click();
    await page.getByText('Sample plan', { exact: true }).click();
    await expect(page.getByText('CURRENT-PLAN')).toBeVisible();
    const oldRead = page.waitForResponse(response => response.url().includes('aql-preview') && response.url().includes('batch_quantity=10'));
    releaseOld();
    await oldRead;
    await expect(page.getByText('OLD-PLAN')).toBeHidden();
    await expect(page.getByText('CURRENT-PLAN')).toBeVisible();
  } finally { releaseOld(); }
});
