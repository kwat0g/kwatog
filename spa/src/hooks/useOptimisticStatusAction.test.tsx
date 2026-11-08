import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { useOptimisticStatusAction } from './useOptimisticStatusAction';

describe('useOptimisticStatusAction', () => {
  it('reconciles the optimistic record from the server response, including permitted actions', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const detailKey = ['purchasing', 'purchase-requests', 'pr-1'];
    const freshRecord = { id: 'pr-1', status: 'approved', actions: { can_approve: false, can_cancel: false } };
    const mutationFn = vi.fn().mockResolvedValue(freshRecord);
    queryClient.setQueryData(detailKey, {
      id: 'pr-1',
      status: 'pending',
      actions: { can_approve: true, can_cancel: true },
    });
    const wrapper = ({ children }: { children: React.ReactNode }) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );
    const { result } = renderHook(
      () => useOptimisticStatusAction({
        detailKey,
        mutationFn,
        nextStatus: 'approved',
        successMsg: 'Approved.',
        errorMsg: 'Could not approve.',
      }),
      { wrapper },
    );

    await act(async () => result.current.mutateAsync(undefined));

    expect(queryClient.getQueryData(detailKey)).toEqual(freshRecord);
    expect(mutationFn).toHaveBeenCalledOnce();
    queryClient.clear();
  });

  it('does not let broad related invalidation refetch and overwrite a fresh detail response', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, staleTime: Infinity } },
    });
    const detailKey = ['leaves', 'request', 'leave-1'];
    const listKey = ['leaves', { status: 'pending' }];
    const before = { id: 'leave-1', status: 'pending', available_actions: { approve: true } };
    const fresh = { id: 'leave-1', status: 'approved', available_actions: { approve: false } };
    const detailQuery = vi.fn().mockResolvedValueOnce(before).mockResolvedValue(before);
    const listQuery = vi.fn().mockResolvedValue({ data: [before] });
    const wrapper = ({ children }: { children: React.ReactNode }) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );
    const { result } = renderHook(() => {
      useQuery({ queryKey: detailKey, queryFn: detailQuery });
      useQuery({ queryKey: listKey, queryFn: listQuery });
      return useOptimisticStatusAction({
        detailKey,
        invalidateKey: ['leaves'],
        mutationFn: async () => fresh,
        nextStatus: 'approved',
        successMsg: 'Approved.',
        errorMsg: 'Could not approve.',
      });
    }, { wrapper });

    await waitFor(() => expect(queryClient.getQueryData(detailKey)).toEqual(before));
    detailQuery.mockClear();
    listQuery.mockClear();

    await act(async () => result.current.mutateAsync(undefined));

    await waitFor(() => expect(queryClient.getQueryData(detailKey)).toEqual(fresh));
    await waitFor(() => expect(listQuery).toHaveBeenCalledOnce());
    expect(detailQuery).not.toHaveBeenCalled();
    queryClient.clear();
  });

  it('uses the entity map for linked families and preserves a fresh Purchase Request detail response', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, staleTime: Infinity } },
    });
    const detailKey = ['purchasing', 'purchase-requests', 'pr-1'];
    const listKey = ['purchasing', 'purchase-requests', { status: 'pending' }];
    const billsKey = ['accounting', 'bills', { status: 'draft' }];
    const before = { id: 'pr-1', status: 'pending', actions: { can_approve: true } };
    const fresh = { id: 'pr-1', status: 'approved', actions: { can_approve: false } };
    const detailQuery = vi.fn().mockResolvedValue(before);
    const listQuery = vi.fn().mockResolvedValue({ data: [before] });
    const billsQuery = vi.fn().mockResolvedValue({ data: [] });
    const wrapper = ({ children }: { children: React.ReactNode }) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );
    const { result } = renderHook(() => {
      useQuery({ queryKey: detailKey, queryFn: detailQuery });
      useQuery({ queryKey: listKey, queryFn: listQuery });
      useQuery({ queryKey: billsKey, queryFn: billsQuery });
      return useOptimisticStatusAction({
        detailKey,
        realtimeChange: { entityType: 'purchase_request', entityId: 'pr-1' },
        mutationFn: async () => fresh,
        nextStatus: 'approved',
        successMsg: 'Approved.',
        errorMsg: 'Could not approve.',
      });
    }, { wrapper });

    await waitFor(() => expect(queryClient.getQueryData(detailKey)).toEqual(before));
    await waitFor(() => expect(queryClient.getQueryData(listKey)).toEqual({ data: [before] }));
    await waitFor(() => expect(queryClient.getQueryData(billsKey)).toEqual({ data: [] }));
    detailQuery.mockClear();
    listQuery.mockClear();
    billsQuery.mockClear();

    await act(async () => result.current.mutateAsync(undefined));

    await waitFor(() => expect(queryClient.getQueryData(detailKey)).toEqual(fresh));
    await waitFor(() => expect(listQuery).toHaveBeenCalledOnce());
    await waitFor(() => expect(billsQuery).toHaveBeenCalledOnce());
    expect(detailQuery).not.toHaveBeenCalled();
    queryClient.clear();
  });

  it('refetches the current leave detail and linked calendar after a stale action returns 409', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, staleTime: Infinity } },
    });
    const detailKey = ['leaves', 'request', 'leave-1'];
    const calendarKey = ['leave-calendar', 2026, 11, ''];
    const stale = { id: 'leave-1', status: 'pending_dept', available_actions: ['approve_dept'] };
    const current = { id: 'leave-1', status: 'pending_hr', available_actions: [] };
    const detailQuery = vi.fn().mockResolvedValueOnce(stale).mockResolvedValue(current);
    const calendarQuery = vi.fn().mockResolvedValue({ days: [] });
    const conflict = new AxiosError('This request changed.', '409', undefined, undefined, {
      status: 409,
      data: { code: 'leave_request_state_conflict', message: 'This request changed.' },
      statusText: 'Conflict',
      headers: {},
      config: {} as never,
    });
    const wrapper = ({ children }: { children: React.ReactNode }) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );
    const { result } = renderHook(() => {
      useQuery({ queryKey: detailKey, queryFn: detailQuery });
      useQuery({ queryKey: calendarKey, queryFn: calendarQuery });
      return useOptimisticStatusAction({
        detailKey,
        realtimeChange: { entityType: 'leave_request', entityId: 'leave-1' },
        mutationFn: async () => { throw conflict; },
        nextStatus: 'pending_hr',
        successMsg: 'Approved.',
        errorMsg: 'Could not approve.',
      });
    }, { wrapper });

    await waitFor(() => expect(queryClient.getQueryData(detailKey)).toEqual(stale));
    detailQuery.mockClear();
    calendarQuery.mockClear();

    await act(async () => {
      await expect(result.current.mutateAsync(undefined)).rejects.toBe(conflict);
    });

    await waitFor(() => expect(queryClient.getQueryData(detailKey)).toEqual(current));
    await waitFor(() => expect(calendarQuery).toHaveBeenCalledOnce());
    expect(detailQuery).toHaveBeenCalledOnce();
    queryClient.clear();
  });
});
