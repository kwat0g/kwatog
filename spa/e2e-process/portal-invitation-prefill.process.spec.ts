import { expect, test } from './fixtures';
import { actorPage, loadActors } from './actor-session';

test('PORTAL-INVITATION-PREFILL: system admin verifies selected customer contact details', async ({ browser }) => {
  const actors = await loadActors();
  const systemAdmin = actors.find((actor) => actor.actor === 'system_admin');
  if (!systemAdmin) throw new Error('The seeded system_admin E2E account is missing.');

  const { context, page, diagnostics } = await actorPage(browser, systemAdmin.actor, {
    viewport: { width: 1440, height: 1000 },
  });
  let customerId: string | undefined;

  try {
    await page.route('**tile.openstreetmap.org/**', (route) => route.fulfill({
      status: 200,
      contentType: 'image/png',
      body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/nJ8AAAAASUVORK5CYII=', 'base64'),
    }));
    await page.goto('/crm/customers/create');
    await expect(page.getByRole('heading', { name: 'New customer' })).toBeVisible();
    await page.getByRole('textbox', { name: 'Customer name' }).fill('E2E Portal Invite Prefill');
    await page.getByRole('textbox', { name: 'Contact person' }).fill('Jamie Cruz');
    await page.getByRole('textbox', { name: 'Email' }).fill('e2e-portal-invite@example.test');
    await page.getByRole('textbox', { name: 'Delivery address' }).fill('Receiving Gate A, Santa Rosa, Laguna');
    await page.getByRole('textbox', { name: 'Delivery latitude' }).fill('14.2500000');
    await page.getByRole('textbox', { name: 'Delivery longitude' }).fill('121.1000000');

    const createResponse = page.waitForResponse((response) =>
      response.request().method() === 'POST'
      && new URL(response.url()).pathname === '/api/v1/crm/customers',
    );
    await page.getByRole('button', { name: 'Create customer' }).click();
    expect((await createResponse).status()).toBe(201);
    await expect(page.getByRole('heading', { name: 'Customer agreement setup' })).toBeVisible({ timeout: 20_000 });
    customerId = new URL(page.url()).searchParams.get('setup') ?? undefined;
    expect(customerId).toBeTruthy();
    await page.getByRole('button', { name: 'Finish and open customer record' }).click();
    await expect(page).toHaveURL(new RegExp(`/crm/customers/${customerId}$`), { timeout: 20_000 });

    await page.goto('/accounting/portal-access?tab=customers');
    await page.getByRole('button', { name: 'Invite customer' }).click();
    await expect(page.getByRole('option', { name: 'E2E Portal Invite Prefill' })).toBeAttached();
    await page.getByRole('combobox', { name: 'Customer' }).selectOption(customerId!);

    await expect(page.getByRole('textbox', { name: 'Contact name' })).toHaveValue('Jamie Cruz');
    await expect(page.getByRole('textbox', { name: 'Email', exact: true })).toHaveValue('e2e-portal-invite@example.test');
    await page.getByRole('textbox', { name: 'Contact name' }).fill('Jamie Cruz, Sales Officer');
    await expect(page.getByRole('textbox', { name: 'Contact name' })).toHaveValue('Jamie Cruz, Sales Officer');
    diagnostics.assertClean('customer portal invitation contact prefill');
  } finally {
    if (customerId) {
      const status = await page.evaluate(async (id) => {
        const xsrfCookie = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));
        const xsrfToken = xsrfCookie ? decodeURIComponent(xsrfCookie.slice('XSRF-TOKEN='.length)) : '';
        const response = await fetch(`/api/v1/crm/customers/${id}`, {
          method: 'DELETE',
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken,
          },
        });
        return response.status;
      }, customerId);
      expect(status).toBeLessThan(300);
    }
    await context.close();
  }
});
