import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query';
import { afterEach, expect, it, vi } from 'vitest';
import { useChainProgress } from './useChainProgress';

const echoMocks = vi.hoisted(() => {
  const handlers: Record<string, (payload: { refresh: true }) => void> = {};
  const channel = {
    listen: vi.fn((event: string, handler: (payload: { refresh: true }) => void) => {
      handlers[event] = handler;
    }),
    stopListening: vi.fn(),
  };
  const echo = { private: vi.fn(() => channel), leave: vi.fn() };

  return { handlers, channel, echo };
});

vi.mock('@/lib/echo', () => ({ getEcho: async () => echoMocks.echo }));

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.useRealTimers();
  Object.keys(echoMocks.handlers).forEach((event) => delete echoMocks.handlers[event]);
  echoMocks.channel.listen.mockClear();
  echoMocks.channel.stopListening.mockClear();
  echoMocks.echo.private.mockClear();
  echoMocks.echo.leave.mockClear();
});

it('refreshes the current record on a content-free chain broadcast', async () => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity } } });
  const fetchDetail = vi.fn(async () => 'current');
  function Record() {
    const detail = useQuery({ queryKey: ['record', 'one'], queryFn: fetchDetail, staleTime: Infinity });
    useChainProgress('sales_order', 'one', ['record', 'one']);
    return <div>{detail.data}</div>;
  }

  render(<QueryClientProvider client={client}><Record /></QueryClientProvider>);
  await waitFor(() => expect(echoMocks.handlers['.chain.refresh']).toBeDefined());
  expect(fetchDetail).toHaveBeenCalledTimes(1);

  await act(async () => echoMocks.handlers['.chain.refresh']({ refresh: true }));

  await waitFor(() => expect(fetchDetail).toHaveBeenCalledTimes(2));
  client.clear();
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
