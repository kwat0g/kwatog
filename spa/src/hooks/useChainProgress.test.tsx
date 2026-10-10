import { act, cleanup, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query';
import { afterEach, expect, it, vi } from 'vitest';
import { useChainProgress } from './useChainProgress';

vi.mock('@/lib/echo', () => ({ getEcho: () => new Promise(() => {}) }));

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.useRealTimers();
});

it('refreshes active process views without broadcasts, pauses when hidden, and stops on unmount', async () => {
  vi.useFakeTimers();
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity } } });
  let status = 'pending';
  const fetchDetail = vi.fn(async () => status);
  const fetchChain = vi.fn(async () => status);
  function Record() {
    const detail = useQuery({ queryKey: ['delivery', 'one'], queryFn: fetchDetail });
    const chain = useQuery({ queryKey: ['chain', 'one'], queryFn: fetchChain });
    useChainProgress('delivery', 'one', ['delivery', 'one'], [['chain', 'one']]);
    return <div>{detail.data} / {chain.data}</div>;
  }
  const view = render(<QueryClientProvider client={client}><Record /></QueryClientProvider>);
  await act(() => vi.advanceTimersByTimeAsync(1));
  expect(screen.getByText('pending / pending')).toBeInTheDocument();
  status = 'generated';
  await act(() => vi.advanceTimersByTimeAsync(30_000));
  expect(screen.getByText('generated / generated')).toBeInTheDocument();
  expect(fetchDetail).toHaveBeenCalledTimes(2);
  expect(fetchChain).toHaveBeenCalledTimes(2);
  const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
  await act(() => vi.advanceTimersByTimeAsync(30_000));
  expect(fetchDetail).toHaveBeenCalledTimes(2);
  visibility.mockReturnValue('visible');
  view.unmount();
  await act(() => vi.advanceTimersByTimeAsync(30_000));
  expect(client.getQueryState(['delivery', 'one'])?.isInvalidated).toBe(false);
  client.clear();
});
