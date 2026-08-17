/**
 * The interceptor's job is the failures a page cannot diagnose.
 *
 * It used to gate every toast on `showErrorToast === true` and nothing in the
 * app ever set it, so a rate-limited, locked-out, timed-out or offline user got
 * silence from here and a misleading "Failed to create PO." from the page.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { AxiosError, AxiosHeaders, type InternalAxiosRequestConfig } from 'axios';
import toast from 'react-hot-toast';

vi.mock('react-hot-toast', () => ({
  default: { error: vi.fn(), success: vi.fn() },
}));

// The 401 branch navigates; keep it inert and assert on the cache clear instead.
vi.mock('@/lib/queryClient', () => ({ queryClient: { clear: vi.fn() } }));

import { client, wasReportedGlobally } from '../client';
import { queryClient } from '@/lib/queryClient';

/** Drive the response interceptor directly — no network, no MSW. */
function reject(opts: {
  status?: number;
  method?: string;
  url?: string;
  data?: unknown;
  code?: string;
  headers?: Record<string, string>;
}): Promise<never> {
  const config = {
    method: opts.method ?? 'get',
    url: opts.url ?? '/things',
    headers: new AxiosHeaders(),
  } as InternalAxiosRequestConfig;

  const error = new AxiosError('Request failed with status code ' + (opts.status ?? 500));
  error.config = config;
  error.code = opts.code;
  if (opts.status !== undefined) {
    error.response = {
      status: opts.status,
      statusText: '',
      data: opts.data ?? {},
      headers: opts.headers ?? {},
      config,
    };
  }

  // interceptors.handlers is the documented shape; index 0 is the only handler.
  const handler = (client.interceptors.response as unknown as {
    handlers: Array<{ rejected: (e: unknown) => Promise<never> }>;
  }).handlers[0];
  return handler.rejected(error);
}

const errorToast = toast.error as unknown as ReturnType<typeof vi.fn>;

describe('response error interceptor', () => {
  beforeEach(() => vi.clearAllMocks());
  afterEach(() => vi.clearAllMocks());

  it('reports a 429 and quotes Retry-After', async () => {
    await expect(
      reject({ status: 429, method: 'post', headers: { 'retry-after': '45' } }),
    ).rejects.toBeInstanceOf(AxiosError);
    expect(errorToast).toHaveBeenCalledOnce();
    expect(errorToast.mock.calls[0][0]).toContain('45 seconds');
  });

  it('rounds a long Retry-After to minutes', async () => {
    await expect(
      reject({ status: 429, headers: { 'retry-after': '120' } }),
    ).rejects.toBeTruthy();
    expect(errorToast.mock.calls[0][0]).toContain('2 minutes');
  });

  it('reports a timeout distinctly from a server error', async () => {
    await expect(reject({ code: 'ECONNABORTED' })).rejects.toBeTruthy();
    expect(errorToast.mock.calls[0][0]).toMatch(/took too long/i);
  });

  it('reports an unreachable server', async () => {
    await expect(reject({})).rejects.toBeTruthy();
    expect(errorToast).toHaveBeenCalledOnce();
  });

  it('reports a 5xx on a read, where the page usually has no handler', async () => {
    await expect(reject({ status: 500, method: 'get' })).rejects.toBeTruthy();
    expect(errorToast).toHaveBeenCalledOnce();
  });

  it('leaves a 5xx on a mutation to the page, which has the context', async () => {
    await expect(reject({ status: 500, method: 'post' })).rejects.toBeTruthy();
    expect(errorToast).not.toHaveBeenCalled();
  });

  it('stays silent on 422 so forms own field-level errors', async () => {
    await expect(reject({ status: 422, method: 'post' })).rejects.toBeTruthy();
    expect(errorToast).not.toHaveBeenCalled();
  });

  it('stays silent on 404 so pages render their own not-found state', async () => {
    await expect(reject({ status: 404 })).rejects.toBeTruthy();
    expect(errorToast).not.toHaveBeenCalled();
  });

  it('marks what it reported so a page does not contradict it', async () => {
    const err = await reject({ status: 429 }).catch((e) => e);
    expect(wasReportedGlobally(err)).toBe(true);
  });

  it('does not mark what it left to the page', async () => {
    const err = await reject({ status: 422, method: 'post' }).catch((e) => e);
    expect(wasReportedGlobally(err)).toBe(false);
  });

  it('clears the query cache on 401 so no stale cross-user data survives', async () => {
    await expect(reject({ status: 401, url: '/things' })).rejects.toBeTruthy();
    expect(queryClient.clear).toHaveBeenCalled();
  });

  it('does not redirect on the login attempt itself', async () => {
    await expect(reject({ status: 401, url: '/auth/login', method: 'post' })).rejects.toBeTruthy();
    expect(queryClient.clear).not.toHaveBeenCalled();
  });

  it('honours an explicit opt-out', async () => {
    const config = {
      method: 'get',
      url: '/things',
      headers: new AxiosHeaders(),
      skipErrorToast: true,
    } as InternalAxiosRequestConfig & { skipErrorToast: boolean };
    const error = new AxiosError('boom');
    error.config = config;
    error.response = { status: 429, statusText: '', data: {}, headers: {}, config };
    const handler = (client.interceptors.response as unknown as {
      handlers: Array<{ rejected: (e: unknown) => Promise<never> }>;
    }).handlers[0];
    await expect(handler.rejected(error)).rejects.toBeTruthy();
    expect(errorToast).not.toHaveBeenCalled();
  });
});
