import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook } from '@testing-library/react';
import { createElement, type ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';

const { subscribeMock, scheduleChangeMock, scheduleCatchUpMock } = vi.hoisted(() => ({
  subscribeMock: vi.fn(),
  scheduleChangeMock: vi.fn(),
  scheduleCatchUpMock: vi.fn(),
}));

vi.mock('./useRealtimeEvent', () => ({ useRealtimeEvent: subscribeMock }));
vi.mock('@/lib/realtime/invalidationMap', () => ({
  scheduleRealtimeChangeInvalidation: scheduleChangeMock,
  scheduleRealtimeEntityCatchUp: scheduleCatchUpMock,
}));

import { useCustomerOrderRealtime } from './useCustomerOrderRealtime';

afterEach(() => vi.clearAllMocks());

it('subscribes by customer account and invalidates only the signalled order or delivery families', () => {
  const queryClient = new QueryClient();
  const wrapper = ({ children }: { children: ReactNode }) => createElement(QueryClientProvider, { client: queryClient }, children);

  renderHook(() => useCustomerOrderRealtime('customer-hash'), { wrapper });

  expect(subscribeMock).toHaveBeenCalledWith(
    'customer.order.customer-hash',
    '.chain.refresh',
    expect.any(Function),
    expect.any(Function),
    expect.any(Function),
  );

  const [, , refresh, catchUp] = subscribeMock.mock.calls[0] as [string, string, (payload: unknown) => void, () => void, () => void];
  act(() => refresh({ entity_type: 'sales_order', entity_id: 'so-hash' }));
  act(() => refresh({ entity_type: 'delivery', entity_id: 'delivery-hash' }));
  act(() => refresh({ entity_type: 'invoice', entity_id: 'invoice-hash' }));

  expect(scheduleChangeMock).toHaveBeenNthCalledWith(1, queryClient, { entityType: 'sales_order', entityId: 'so-hash' });
  expect(scheduleChangeMock).toHaveBeenNthCalledWith(2, queryClient, { entityType: 'delivery', entityId: 'delivery-hash' });
  expect(scheduleChangeMock).toHaveBeenCalledTimes(2);

  act(() => catchUp());
  expect(scheduleCatchUpMock).toHaveBeenCalledWith(queryClient, ['sales_order', 'delivery']);
  queryClient.clear();
});

it('does not subscribe before the customer identity is available', () => {
  const queryClient = new QueryClient();
  const wrapper = ({ children }: { children: ReactNode }) => createElement(QueryClientProvider, { client: queryClient }, children);

  renderHook(() => useCustomerOrderRealtime(undefined), { wrapper });

  expect(subscribeMock).toHaveBeenCalledWith('', '.chain.refresh', expect.any(Function), expect.any(Function), expect.any(Function));
  queryClient.clear();
});
