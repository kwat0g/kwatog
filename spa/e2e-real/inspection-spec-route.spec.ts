import { expect, test } from '@playwright/test';
import { loginAs, ROLES } from './helpers';

test('QC can start an inspection spec from the New spec route', async ({ page }) => {
  const qc = ROLES.find((candidate) => candidate.slug === 'qc_inspector')!;
  await loginAs(page, qc.email, qc.password);

  await page.goto('/quality/inspection-specs');
  const newSpec = page.getByRole('button', { name: 'New spec' });
  await expect(newSpec).toBeVisible();
  await newSpec.click();
  await expect(page).toHaveURL(/\/quality\/inspection-specs\/new$/);

  const product = page.getByRole('combobox', { name: 'Product' });
  await expect(product).toBeVisible();
  await product.click();
  await expect(page.getByRole('listbox', { name: 'Product' }).getByRole('option').first()).toBeVisible();
});
