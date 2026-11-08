import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import { invalidateQueryPrefixExcept } from './queryInvalidation';

describe('invalidateQueryPrefixExcept', () => {
  it('keeps an authoritative detail query fresh while refetching matching lists', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, staleTime: Infinity } },
    });
    const detailKey = ['work-orders', 'detail', 'wo-1'];
    const listKey = ['work-orders', 'list', { status: 'open' }];
    const detailQuery = vi.fn().mockResolvedValue({ id: 'wo-1', status: 'ready' });
    const listQuery = vi.fn().mockResolvedValue({ data: [{ id: 'wo-1', status: 'ready' }] });
    const wrapper = ({ children }: { children: React.ReactNode }) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );

    renderHook(() => {
      useQuery({ queryKey: detailKey, queryFn: detailQuery });
      useQuery({ queryKey: listKey, queryFn: listQuery });
    }, { wrapper });

    await waitFor(() => expect(queryClient.getQueryData(detailKey)).toBeDefined());
    await waitFor(() => expect(queryClient.getQueryData(listKey)).toBeDefined());
    detailQuery.mockClear();
    listQuery.mockClear();
    queryClient.setQueryData(detailKey, { id: 'wo-1', status: 'complete' });

    await act(async () => {
      await invalidateQueryPrefixExcept(queryClient, ['work-orders'], [detailKey]);
    });

    await waitFor(() => expect(listQuery).toHaveBeenCalledOnce());
    expect(detailQuery).not.toHaveBeenCalled();
    expect(queryClient.getQueryData(detailKey)).toEqual({ id: 'wo-1', status: 'complete' });
    expect(queryClient.getQueryState(detailKey)?.isInvalidated).toBe(false);
    queryClient.clear();
  });

  it('cancels an older in-flight detail read before preserving a mutation response', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, staleTime: Infinity } },
    });
    const detailKey = ['work-orders', 'detail', 'wo-2'];
    let finishOldRead: ((value: { id: string; status: string }) => void) | undefined;
    const detailQuery = vi.fn(() => new Promise<{ id: string; status: string }>((resolve) => {
      finishOldRead = resolve;
    }));
    const wrapper = ({ children }: { children: React.ReactNode }) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );

    renderHook(() => useQuery({ queryKey: detailKey, queryFn: detailQuery }), { wrapper });
    await waitFor(() => expect(detailQuery).toHaveBeenCalledOnce());
    const fresh = { id: 'wo-2', status: 'complete' };
    queryClient.setQueryData(detailKey, fresh);

    await act(async () => {
      await invalidateQueryPrefixExcept(queryClient, ['work-orders'], [detailKey]);
    });
    expect(queryClient.getQueryState(detailKey)?.status).toBe('success');
    await act(async () => {
      finishOldRead?.({ id: 'wo-2', status: 'pending' });
      await Promise.resolve();
    });

    expect(queryClient.getQueryData(detailKey)).toEqual(fresh);
    expect(detailQuery).toHaveBeenCalledOnce();
    queryClient.clear();
  });

  it('preserves exact keys when object segments use a different property order', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, staleTime: Infinity } },
    });
    const detailKey = ['work-orders', 'detail', { id: 'wo-3', view: 'full' }] as const;
    const equivalentDetailKey = ['work-orders', 'detail', { view: 'full', id: 'wo-3' }] as const;
    const detailQuery = vi.fn().mockResolvedValue({ id: 'wo-3', status: 'ready' });
    const wrapper = ({ children }: { children: React.ReactNode }) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );

    renderHook(() => useQuery({ queryKey: detailKey, queryFn: detailQuery }), { wrapper });
    await waitFor(() => expect(queryClient.getQueryData(detailKey)).toBeDefined());
    detailQuery.mockClear();
    queryClient.setQueryData(detailKey, { id: 'wo-3', status: 'complete' });

    await act(async () => {
      await invalidateQueryPrefixExcept(queryClient, ['work-orders'], [equivalentDetailKey]);
    });

    expect(detailQuery).not.toHaveBeenCalled();
    expect(queryClient.getQueryData(detailKey)).toEqual({ id: 'wo-3', status: 'complete' });
    queryClient.clear();
  });
});
