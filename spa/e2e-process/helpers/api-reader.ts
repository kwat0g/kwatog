import type { APIRequestContext, BrowserContext } from '@playwright/test';

export async function apiReader(context: BrowserContext): Promise<APIRequestContext> {
  return context.request;
}

function sameOriginBrowserHeaders(context: BrowserContext): Record<string, string> {
  const page = [...context.pages()].reverse().find((candidate) => /^https?:\/\//.test(candidate.url()));
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  };
  if (page) {
    const origin = new URL(page.url()).origin;
    headers.Origin = origin;
    headers.Referer = page.url();
  }
  return headers;
}

export async function readApiJson<T>(context: BrowserContext, apiPath: string): Promise<T> {
  if (!apiPath.startsWith('/api/v1/') || apiPath.startsWith('//')) {
    throw new Error('Read-only API checks must use a same-origin /api/v1/ path.');
  }
  const response = await context.request.get(apiPath, {
    headers: sameOriginBrowserHeaders(context),
    failOnStatusCode: false,
  });
  if (!response.ok()) {
    throw new Error(`Read-only API GET ${apiPath} returned ${response.status()}.`);
  }
  return response.json() as Promise<T>;
}

export async function attemptDeniedMutation(
  context: BrowserContext,
  method: 'POST' | 'PUT' | 'PATCH' | 'DELETE',
  apiPath: string,
  data?: unknown,
  expectedStatuses: number[] = [403, 422],
): Promise<number> {
  if (!apiPath.startsWith('/api/v1/') || apiPath.startsWith('//')) {
    throw new Error('Negative authorization checks must use a same-origin /api/v1/ path.');
  }
  const response = await context.request.fetch(apiPath, {
    method,
    data,
    headers: sameOriginBrowserHeaders(context),
    failOnStatusCode: false,
  });
  if (!expectedStatuses.includes(response.status())) {
    throw new Error(`Negative ${method} ${apiPath} check returned unexpected status ${response.status()}.`);
  }
  return response.status();
}
