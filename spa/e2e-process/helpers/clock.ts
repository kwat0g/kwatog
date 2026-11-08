import { expect, type APIRequestContext } from '@playwright/test';

const endpoint = '/api/v1/_e2e/clock';

function controlHeaders(): Record<string, string> {
  const token = process.env.E2E_CONTROL_TOKEN;
  if (!token || token.length < 32) throw new Error('E2E clock control token is missing.');
  return { 'X-E2E-Control': token };
}

export async function freezeClock(request: APIRequestContext, instant: string): Promise<void> {
  const response = await request.post(endpoint, { headers: controlHeaders(), data: { instant } });
  expect(response.status(), 'E2E clock freeze should be accepted by the gated control endpoint').toBe(200);
}

export async function clearClock(request: APIRequestContext): Promise<void> {
  const response = await request.delete(endpoint, { headers: controlHeaders() });
  expect(response.status(), 'E2E clock reset should be accepted by the gated control endpoint').toBe(200);
}

export async function readClock(request: APIRequestContext): Promise<string> {
  const response = await request.get(endpoint, { headers: controlHeaders() });
  expect(response.status(), 'E2E clock read should be accepted by the gated control endpoint').toBe(200);
  const result = await response.json() as { now: string };
  return result.now;
}
