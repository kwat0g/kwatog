import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query';
import { afterEach, expect, it, vi } from 'vitest';
import { useChainProgress } from './useChainProgress';

const echoMocks = vi.hoisted(() => {
  const handlers: Record<string, (payload: { refresh: true }) => void> = {};
  const subscriptionCallbacks: Array<() => void> = [];
  const errorCallbacks: Array<(error: unknown) => void> = [];
  const channel = {
    listen: vi.fn((event: string, handler: (payload: { refresh: true }) => void) => {
      handlers[event] = handler;
      return channel;
    }),
    stopListening: vi.fn(() => channel),
    subscribed: vi.fn((handler: () => void) => {
      subscriptionCallbacks.push(handler);
      return channel;
    }),
    error: vi.fn((handler: (error: unknown) => void) => {
      errorCallbacks.push(handler);
      return channel;
    }),
  };
  const echo = { private: vi.fn(() => channel), leave: vi.fn() };

  return { handlers, channel, echo, subscriptionCallbacks, errorCallbacks };
});

vi.mock('@/lib/echo', () => ({ getEcho: async () => echoMocks.echo }));

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.useRealTimers();
  Object.keys(echoMocks.handlers).forEach((event) => delete echoMocks.handlers[event]);
  echoMocks.subscriptionCallbacks.length = 0;
  echoMocks.errorCallbacks.length = 0;
  echoMocks.channel.listen.mockClear();
  echoMocks.channel.stopListening.mockClear();
  echoMocks.channel.subscribed.mockClear();
  echoMocks.channel.error.mockClear();
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

it('does not add a second polling loop outside the shared realtime provider', async () => {
  vi.useFakeTimers();
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity } } });
  const fetchDetail = vi.fn(async () => 'pending');
  const fetchChain = vi.fn(async () => 'pending');
  function Record() {
    const detail = useQuery({ queryKey: ['delivery', 'one'], queryFn: fetchDetail });
    const chain = useQuery({ queryKey: ['chain', 'one'], queryFn: fetchChain });
    useChainProgress('delivery', 'one', ['delivery', 'one'], [['chain', 'one']]);
    return <div>{detail.data} / {chain.data}</div>;
  }
  const view = render(<QueryClientProvider client={client}><Record /></QueryClientProvider>);
  await act(() => vi.advanceTimersByTimeAsync(1));
  expect(screen.getByText('pending / pending')).toBeInTheDocument();
  await act(() => vi.advanceTimersByTimeAsync(30_000));
  expect(fetchDetail).toHaveBeenCalledTimes(1);
  expect(fetchChain).toHaveBeenCalledTimes(1);
  view.unmount();
  expect(client.getQueryState(['delivery', 'one'])?.isInvalidated).toBe(false);
  client.clear();
});
