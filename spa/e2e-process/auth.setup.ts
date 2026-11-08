import { mkdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { test, expect } from './fixtures';
import { monitorPage } from './helpers/page-diagnostics';

type Actor = {
  actor: string;
  realm: 'internal' | 'customer' | 'supplier';
  email: string;
  role?: string;
};

type ActorManifest = { accounts: Actor[] };

test('sign in selected seeded actors through the real UI and save each role session', async ({ browser, baseURL }, testInfo) => {
  test.setTimeout(5 * 60 * 1000);
  const manifestPath = process.env.E2E_ACCOUNT_MANIFEST;
  const password = process.env.E2E_ACCOUNT_PASSWORD;
  if (!manifestPath || !password) throw new Error('E2E actor manifest or password is missing from the guarded container.');

  const manifest = JSON.parse(await readFile(manifestPath, 'utf8')) as ActorManifest;
  if (!Array.isArray(manifest.accounts) || manifest.accounts.length === 0) {
    throw new Error('E2E actor manifest is empty.');
  }

  const requestedActors = process.env.E2E_AUTH_ACTORS?.split(',').map((actor) => actor.trim()).filter(Boolean) ?? [];
  const requestedSet = requestedActors.length > 0 ? new Set(requestedActors) : null;
  if (requestedSet && requestedSet.size !== requestedActors.length) {
    throw new Error('E2E_AUTH_ACTORS must contain unique actor names.');
  }
  const actors = requestedSet
    ? manifest.accounts.filter((actor) => requestedSet.has(actor.actor))
    : manifest.accounts;
  const unmatchedActors = requestedActors.filter((actor) => !manifest.accounts.some((account) => account.actor === actor));
  if (unmatchedActors.length > 0 || actors.length === 0) {
    throw new Error('E2E_AUTH_ACTORS did not match seeded actors.');
  }

  const stateDirectory = path.resolve('.auth');
  await mkdir(stateDirectory, { recursive: true, mode: 0o700 });

  for (const actor of actors) {
    await test.step(`UI sign-in as ${actor.actor}${actor.role ? ` (${actor.role})` : ''}`, async () => {
      const context = await browser.newContext({ baseURL });
      const page = await context.newPage();
      const diagnostics = await monitorPage(page);
      const actorFile = actor.actor.replace(/[^a-zA-Z0-9_-]/g, '_');
      try {
        await page.goto('/sign-in');
        await page.getByLabel('Email').fill(actor.email);
        await page.getByLabel('Password', { exact: true }).fill(password);
        const signInResponse = page.waitForResponse(
          (response) => response.url().includes('/api/v1/auth/sign-in'),
          { timeout: 20_000 },
        );
        await page.getByRole('button', { name: 'Sign in' }).click();
        const response = await signInResponse;
        expect(response.status(), `sign-in response for ${actor.actor}`).toBe(200);

        if (actor.realm === 'internal') {
          await expect(page).toHaveURL(/\/dashboard(?:\/|$)/, { timeout: 20_000 });
        } else {
          await expect(page).toHaveURL(new RegExp(`/portal/${actor.realm}(?:/|$)`), { timeout: 20_000 });
        }
        await expect(page).not.toHaveURL(/change-password/);
        diagnostics.assertClean(`sign-in as ${actor.actor}`);

        await context.storageState({ path: path.join(stateDirectory, `${actorFile}.json`) });
      } catch (error) {
        await page.getByLabel('Password', { exact: true }).fill('').catch(() => undefined);
        await page.screenshot({ path: testInfo.outputPath(`${actorFile}.png`), fullPage: true }).catch(() => undefined);
        throw error;
      } finally {
        await context.close();
      }
    });
  }
});
