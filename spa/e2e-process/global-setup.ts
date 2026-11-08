import { assertE2EEnvironment } from '../../scripts/e2e/environment-guard.mjs';

export default async function globalSetup(): Promise<void> {
  const guarded = assertE2EEnvironment(process.env);
  if (guarded.baseURL !== process.env.E2E_BASE_URL?.replace(/\/+$/, '')) {
    throw new Error('E2E_BASE_URL changed after the process configuration was loaded.');
  }
}
