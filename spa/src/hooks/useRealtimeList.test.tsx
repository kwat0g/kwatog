import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useClearanceListRealtime, useMaintenanceDashboardRealtime, useMaintenanceWorkOrderListRealtime, useRealtimeList, useRealtimeRecord, useReturnCasePortalRealtime, useReturnCaseSourceRealtime, useSupplierListingRealtime } from './useRealtimeRecord';
import { useAuthStore } from '@/stores/authStore';

const realtime = vi.hoisted(() => ({
  handler: undefined as ((payload: unknown) => void) | undefined,
  subscribe: vi.fn(),
}));

vi.mock('./useRealtimeEvent', () => ({
  useRealtimeEvent: (...args: unknown[]) => {
    const [channel, , handler] = args as [string, string, (payload: unknown) => void];
    if (!channel) return;
    realtime.handler = handler;
    realtime.subscribe(...args);
  },
}));

describe('useRealtimeList', () => {
  beforeEach(() => {
    realtime.handler = undefined;
    realtime.subscribe.mockClear();
    useAuthStore.setState({ user: null });
  });

  it('subscribes Return Case source forms to authorized source, request, and portal refreshes', () => {
    const queryClient = new QueryClient();
    const wrapper = ({ children }: { children: ReactNode }) =>
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const internal = renderHook(() => useReturnCaseSourceRealtime('internal', undefined, 'customer'), { wrapper });
    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.return_case.customer', '.return.case.refresh', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.return_request.customer', '.return.request.refresh', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.return_case.customer', '.chain.refresh', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    internal.unmount();

    realtime.subscribe.mockClear();
    const portal = renderHook(() => useReturnCaseSourceRealtime('customer', 'customer-hash', undefined), { wrapper });
    expect(realtime.subscribe).toHaveBeenCalledWith(
      'customer.account.customer-hash', '.return.case.refresh', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    portal.unmount();
    queryClient.clear();
  });

  it('subscribes to the authorized entity list signal and invalidates its query families', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries');

    const { unmount } = renderHook(() => useRealtimeList('purchase_order', 'visible'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.purchase_order.visible',
      '.chain.refresh',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );

    const onSubscribed = realtime.subscribe.mock.calls[0]?.[3] as (() => void) | undefined;
    act(() => onSubscribed?.());
    await waitFor(() => expect(invalidate).toHaveBeenCalled());

    invalidate.mockClear();
    act(() => realtime.handler?.({ refresh: true, event_id: 'test-event' }));
    await waitFor(() => expect(invalidate).toHaveBeenCalled());
    expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['purchasing', 'purchase-orders', 'list'],
    } as never))).toBe(true);

    unmount();
    queryClient.clear();
  });

  it('refreshes leave reference data for internal and self-service viewers', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries');
    const { unmount } = renderHook(() => useRealtimeList('leave_type', 'visible'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.leave_type.visible',
      '.leave.type.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );

    act(() => realtime.handler?.({ refresh: true, entity_type: 'leave_type' }));
    await waitFor(() => expect(invalidate).toHaveBeenCalled());
    const wasInvalidated = (queryKey: unknown[]) => invalidate.mock.calls.some(([filters]) =>
      filters?.predicate?.({ queryKey } as never) === true);
    expect(wasInvalidated(['leave-types', { page: 1 }])).toBe(true);
    expect(wasInvalidated(['leave-types-self'])).toBe(true);
    expect(wasInvalidated(['leave-request-options'])).toBe(true);
    expect(wasInvalidated(['self-service', 'leave'])).toBe(true);

    unmount();
    queryClient.clear();
  });

  it('refreshes an embedded Purchase Request detail when its linked Bill sends a chain ping', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries').mockResolvedValue();

    const { unmount } = renderHook(() => useRealtimeRecord('purchase_request', 'pr-hash'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    const registration = realtime.subscribe.mock.calls.find(([channel, event]) =>
      channel === 'chain.purchase_request.pr-hash' && event === '.chain.refresh');
    expect(registration).toBeDefined();

    act(() => (registration?.[2] as ((payload: unknown) => void) | undefined)?.({
      refresh: true,
      entity_type: 'bill',
      entity_id: 'bill-hash',
    }));

    const wasInvalidated = (queryKey: unknown[]) => invalidate.mock.calls.some(([filters]) =>
      filters?.predicate?.({ queryKey } as never) === true);
    await waitFor(() => expect(wasInvalidated(['purchasing', 'purchase-requests', { status: 'approved' }])).toBe(true));
    expect(wasInvalidated(['purchasing', 'purchase-requests', 'pr-hash'])).toBe(true);
    expect(wasInvalidated(['accounting', 'bills', { status: 'draft' }])).toBe(true);

    unmount();
    queryClient.clear();
  });

  it('invalidates the Purchasing dashboard when supplier-performance snapshots change', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries');

    const { unmount } = renderHook(() => useRealtimeList('supplier_performance', 'visible'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.supplier_performance.visible',
      '.purchasing.supplier-performance.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );

    act(() => realtime.handler?.({ refresh: true, entity_type: 'supplier_performance' }));
    await waitFor(() => expect(invalidate).toHaveBeenCalledTimes(1));
    expect(invalidate.mock.calls[0]?.[0]?.predicate?.({
      queryKey: ['dashboard', 'purchasing'],
    } as never)).toBe(true);

    unmount();
    queryClient.clear();
  });

  it('subscribes to the import-manager batch-history refresh channel', () => {
    const queryClient = new QueryClient();
    const { unmount } = renderHook(() => useRealtimeList('import_batch', 'visible'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.admin_import_batch.visible',
      '.admin.import-batch.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );

    unmount();
    queryClient.clear();
  });

  it('subscribes routings to the backend Production Routing list channel', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries');
    const { unmount } = renderHook(() => useRealtimeList('product_routing', 'visible'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.production_routing.visible',
      '.production.routing.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );

    act(() => realtime.handler?.({ refresh: true, entity_type: 'product_routing' }));
    await waitFor(() => expect(invalidate).toHaveBeenCalled());
    expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['production', 'routings', { is_active: true }],
    } as never))).toBe(true);

    unmount();
    queryClient.clear();
  });

  it('keeps routing detail catch-up on the backend entity list channel', () => {
    const queryClient = new QueryClient();
    const { unmount } = renderHook(() => useRealtimeRecord('product_routing', 'routing-hash'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'production.routing.routing-hash',
      '.production.routing.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.production_routing.visible',
      '.production.routing.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );

    unmount();
    queryClient.clear();
  });

  it('refreshes item details when a bulk catalog update has no item ID', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries').mockResolvedValue();
    const { unmount } = renderHook(() => useRealtimeRecord('item', 'item-hash'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    const registration = realtime.subscribe.mock.calls.find(([channel, event]) =>
      channel === 'entity.item.visible' && event === '.inventory.item.changed');
    expect(registration).toBeDefined();

    act(() => (registration?.[2] as ((payload: unknown) => void) | undefined)?.({
      refresh: true,
      event_id: 'item-catalog-batch',
      action: 'updated',
    }));

    await waitFor(() => expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['inventory', 'items', 'item-hash'],
    } as never))).toBe(true));

    unmount();
    queryClient.clear();
  });

  it('refreshes Admin User details when a linked directory source changes', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries').mockResolvedValue();
    const { unmount } = renderHook(() => useRealtimeRecord('admin_user', 'user-hash'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    const registration = realtime.subscribe.mock.calls.find(([channel, event]) =>
      channel === 'entity.admin_user.visible' && event === '.admin.user.changed');
    expect(registration).toBeDefined();

    act(() => (registration?.[2] as ((payload: unknown) => void) | undefined)?.({
      refresh: true,
      event_id: 'admin-user-directory-batch',
      entity_type: 'admin_user',
      entity_id: null,
      action: 'updated',
    }));

    await waitFor(() => expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['admin-user', 'user-hash'],
    } as never))).toBe(true));

    unmount();
    queryClient.clear();
  });

  it('routes a related Position signal through the shared employee detail invalidation', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries').mockResolvedValue();
    const { unmount } = renderHook(() => useRealtimeRecord('position', 'position-hash', [
      ['hr', 'employee', 'employee-hash'],
    ]), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    const registration = realtime.subscribe.mock.calls.find(([channel, event]) =>
      channel === 'position.position-hash' && event === '.hr.position.changed');
    expect(registration).toBeDefined();

    act(() => (registration?.[2] as ((payload: unknown) => void) | undefined)?.({
      refresh: true,
      event_id: 'position-detail-refresh',
      entity_type: 'position',
    }));

    await waitFor(() => expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['hr', 'employee', 'employee-hash'],
    } as never))).toBe(true));

    unmount();
    queryClient.clear();
  });

  it('subscribes to the stock-adjustment list refresh channel', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries');
    const { unmount } = renderHook(() => useRealtimeList('stock_adjustment' as never, 'visible'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.stock_adjustment.visible',
      '.inventory.stock-adjustment.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );

    act(() => realtime.handler?.({ refresh: true, event_id: 'stock-adjustment-test' }));
    await waitFor(() => expect(invalidate).toHaveBeenCalled());
    expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['inventory', 'stock-adjustments', { status: 'pending' }],
    } as never))).toBe(true);

    unmount();
    queryClient.clear();
  });

  it('subscribes to the supplier cost-review list refresh channel', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries');
    const { unmount } = renderHook(() => useRealtimeList('item_cost_review', 'visible'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.item_cost_review.visible',
      '.inventory.cost-review.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );

    act(() => realtime.handler?.({ refresh: true, entity_type: 'item_cost_review' }));
    await waitFor(() => expect(invalidate).toHaveBeenCalled());
    expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['cost-reviews', 1, null],
    } as never))).toBe(true);

    unmount();
    queryClient.clear();
  });

  it('subscribes to the MRB list refresh channel', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries');
    const { unmount } = renderHook(() => useRealtimeList('material_review_record', 'visible'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.material_review_record.visible',
      '.inventory.mrb.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );

    act(() => realtime.handler?.({ refresh: true, entity_type: 'material_review_record' }));
    await waitFor(() => expect(invalidate).toHaveBeenCalled());
    expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['inventory', 'mrb', { status: 'held' }],
    } as never))).toBe(true);

    unmount();
    queryClient.clear();
  });

  it('subscribes MRB detail viewers to the record channel', () => {
    const queryClient = new QueryClient();
    const { unmount } = renderHook(() => useRealtimeRecord('material_review_record', 'mrb-hash'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'inventory.mrb.mrb-hash',
      '.inventory.mrb.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );

    unmount();
    queryClient.clear();
  });

  it('subscribes material-issue list viewers to the private entity channel', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries');
    const { unmount } = renderHook(() => useRealtimeList('material_issue_slip', 'visible'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.material_issue_slip.visible',
      '.inventory.material-issue.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    act(() => realtime.handler?.({ refresh: true, entity_type: 'material_issue_slip' }));
    await waitFor(() => expect(invalidate).toHaveBeenCalled());
    expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['inventory', 'material-issues', { status: 'issued' }],
    } as never))).toBe(true);

    unmount();
    queryClient.clear();
  });

  it('subscribes material-issue detail viewers to the exact slip channel', () => {
    const queryClient = new QueryClient();
    const { unmount } = renderHook(() => useRealtimeRecord('material_issue_slip', 'slip-hash'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'inventory.material-issue.slip-hash',
      '.inventory.material-issue.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    unmount();
    queryClient.clear();
  });

  it('refreshes authorized work-order lists when production output is recorded', () => {
    const queryClient = new QueryClient();
    const { unmount } = renderHook(() => useRealtimeList('work_order', 'visible'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.work_order.visible',
      '.output.recorded',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.work_order.visible',
      '.InventoryStockChanged',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    unmount();
    queryClient.clear();
  });

  it('subscribes work-order list viewers to crew-readiness changes from attendance', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries').mockResolvedValue();
    const { unmount } = renderHook(() => useRealtimeList('work_order', 'visible'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    const registration = realtime.subscribe.mock.calls.find(([channel, event]) =>
      channel === 'entity.work_order.visible' && event === '.attendance.changed');
    expect(registration).toBeDefined();

    act(() => (registration?.[2] as ((payload: unknown) => void) | undefined)?.({ refresh: true }));
    await waitFor(() => expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['production', 'execution-readiness', 'wo-hash'],
    } as never))).toBe(true));
    expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['production', 'work-orders', { status: 'confirmed' }],
    } as never))).toBe(true);

    unmount();
    queryClient.clear();
  });

  it('routes work-order output detail pings through the central invalidation hook', () => {
    const queryClient = new QueryClient();
    const { unmount } = renderHook(() => useRealtimeRecord('work_order', 'wo-hash'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'production.wo.wo-hash',
      '.output.recorded',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );

    unmount();
    queryClient.clear();
  });

  it('invalidates active work-order readiness on finished-goods layout changes', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries').mockResolvedValue();
    useAuthStore.setState({
      user: { id: 'readiness-viewer', role: { id: 'role-1', slug: 'production_supervisor' } } as never,
      permissions: new Set(['production.work_orders.lifecycle']),
      features: new Set(['production']),
    });

    const { unmount } = renderHook(() => useRealtimeRecord('work_order', 'wo-hash', [
      ['production', 'execution-readiness', 'wo-hash'],
    ]), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });
    const registration = realtime.subscribe.mock.calls.find(([channel, event]) =>
      channel === 'production.readiness' && event === '.inventory.warehouse-layout.changed');

    expect(registration).toBeDefined();
    act(() => (registration?.[2] as ((payload: unknown) => void) | undefined)?.({ refresh: true }));
    await waitFor(() => expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['production', 'execution-readiness', 'wo-hash'],
    } as never))).toBe(true));

    unmount();
    queryClient.clear();
    useAuthStore.setState({ user: null, permissions: new Set(), features: new Set() });
  });

  it('subscribes to the exact employee-document list channel', () => {
    const queryClient = new QueryClient();
    const { unmount } = renderHook(() => useRealtimeRecord('employee_document', 'employee-hash'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'employee.documents.employee-hash',
      '.hr.employee-documents.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );

    unmount();
    queryClient.clear();
  });

  it('subscribes to the scoped employee-skill list refresh channel', () => {
    const queryClient = new QueryClient();
    const { unmount } = renderHook(() => useRealtimeList('employee_skill', 'visible'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.employee_skill.visible',
      '.hr.employee-skill.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );

    unmount();
    queryClient.clear();
  });

  it('uses the private RFQ refresh event for the RFQ list', () => {
    const queryClient = new QueryClient();
    const { unmount } = renderHook(() => useRealtimeList('request_for_quote', 'visible'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.request_for_quote.visible',
      '.rfq.refresh',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    unmount();
    queryClient.clear();
  });

  it('subscribes the payroll-period list using its authorized private entity signal', () => {
    const queryClient = new QueryClient();
    const { unmount } = renderHook(() => useRealtimeList('payroll_period', 'visible'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.payroll_period.visible',
      '.payroll.period.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    unmount();
    queryClient.clear();
  });

  it('subscribes the payroll-adjustment list using its authorized private entity signal', () => {
    const queryClient = new QueryClient();
    const { unmount } = renderHook(() => useRealtimeList('payroll_adjustment', 'visible'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.payroll_adjustment.visible',
      '.payroll.adjustment.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    unmount();
    queryClient.clear();
  });

  it('subscribes payroll-adjustment detail and invalidates its related period from the event', async () => {
    const queryClient = new QueryClient();
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries');
    const { unmount } = renderHook(() => useRealtimeRecord('payroll_adjustment', 'adjustment-1'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'payroll.adjustment.adjustment-1',
      '.payroll.adjustment.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    act(() => realtime.handler?.({ related: [{ entity_type: 'payroll_period', hash_id: 'period-1' }] }));
    await waitFor(() => expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['payroll-period', 'period-1'],
    } as never))).toBe(true));
    unmount();
    queryClient.clear();
  });

  it('subscribes authorized production schedule readers to private list and record channels', () => {
    const queryClient = new QueryClient();
    const wrapper = ({ children }: { children: ReactNode }) =>
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const list = renderHook(() => useRealtimeList('production_schedule', 'visible'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'entity.production_schedule.visible', '.chain.refresh', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    list.unmount();

    const record = renderHook(() => useRealtimeRecord('production_schedule', 'schedule-hash'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'production_schedule.schedule-hash', '.chain.refresh', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    record.unmount();
    queryClient.clear();
  });

  it('subscribes machine viewers to the scoped list and record channels', () => {
    const queryClient = new QueryClient();
    const wrapper = ({ children }: { children: ReactNode }) =>
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const list = renderHook(() => useRealtimeList('machine', 'visible'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'entity.machine.visible', '.chain.refresh', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    list.unmount();

    const record = renderHook(() => useRealtimeRecord('machine', 'machine-hash'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'production.machine.machine-hash', '.chain.refresh', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    record.unmount();
    queryClient.clear();
  });

  it('subscribes mold viewers to the scoped list and record channels', () => {
    const queryClient = new QueryClient();
    const wrapper = ({ children }: { children: ReactNode }) =>
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const list = renderHook(() => useRealtimeList('mold', 'visible'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'entity.mold.visible', '.chain.refresh', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    list.unmount();

    const record = renderHook(() => useRealtimeRecord('mold', 'mold-hash'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'production.mold.mold-hash', '.chain.refresh', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    record.unmount();
    queryClient.clear();
  });

  it('subscribes BOM viewers to the scoped list and record channels', () => {
    const queryClient = new QueryClient();
    const wrapper = ({ children }: { children: ReactNode }) =>
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const list = renderHook(() => useRealtimeList('bom', 'visible'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'entity.bom.visible', '.chain.refresh', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    list.unmount();

    const record = renderHook(() => useRealtimeRecord('bom', 'bom-hash'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'production.bom.bom-hash', '.chain.refresh', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    record.unmount();
    queryClient.clear();
  });

  it('subscribes MRP plan and run readers to their separate authorized list channels', () => {
    const queryClient = new QueryClient();
    const wrapper = ({ children }: { children: ReactNode }) =>
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const planList = renderHook(() => useRealtimeList('mrp_plan', 'visible'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'entity.mrp_plan.visible', '.chain.refresh', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    planList.unmount();

    const runList = renderHook(() => useRealtimeList('mrp_run', 'visible'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'entity.mrp_run.visible', '.chain.refresh', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    runList.unmount();
    queryClient.clear();
  });

  it('subscribes MRP plan detail viewers on the private record channel', () => {
    const queryClient = new QueryClient();
    const { unmount } = renderHook(() => useRealtimeRecord('mrp_plan', 'plan-hash'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'mrp.plan.plan-hash', '.chain.refresh', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    unmount();
    queryClient.clear();
  });

  it('subscribes maintenance viewers to the module channel and technicians to only their own list channel', async () => {
    const queryClient = new QueryClient();
    const wrapper = ({ children }: { children: ReactNode }) =>
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const viewer = renderHook(() => useMaintenanceWorkOrderListRealtime(true), { wrapper });
    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.maintenance_work_order.visible', '.maintenance.work-order.changed', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    viewer.unmount();

    useAuthStore.setState({ user: { id: 'tech-hash' } as never });
    const technician = renderHook(() => useMaintenanceWorkOrderListRealtime(false), { wrapper });
    expect(realtime.subscribe).toHaveBeenCalledWith(
      'maintenance.user.tech-hash', '.maintenance.work-order.changed', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries');
    act(() => realtime.handler?.({ refresh: true, entity_type: 'maintenance_work_order' }));
    await waitFor(() => expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['maintenance', 'work-orders', { status: 'open' }],
    } as never))).toBe(true));

    technician.unmount();
    queryClient.clear();
    useAuthStore.setState({ user: null });
  });

  it('subscribes a clearance list to the current user channel and refreshes its scoped list', async () => {
    const queryClient = new QueryClient();
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries');
    const wrapper = ({ children }: { children: ReactNode }) =>
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
    useAuthStore.setState({ user: { id: 'hr-viewer-hash' } as never });

    const list = renderHook(() => useClearanceListRealtime(), { wrapper });
    expect(realtime.subscribe).toHaveBeenCalledWith(
      'hr.clearance.user.hr-viewer-hash', '.hr.clearance.changed', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    act(() => realtime.handler?.({ refresh: true, entity_type: 'clearance' }));
    await waitFor(() => expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['hr', 'separations', { status: 'pending' }],
    } as never))).toBe(true));
    expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['hr', 'employees'],
    } as never))).toBe(true);

    list.unmount();
    queryClient.clear();
    useAuthStore.setState({ user: null });
  });

  it('refreshes maintenance dashboard widget and KPI queries from the private work-order signal', async () => {
    const queryClient = new QueryClient();
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries');
    const wrapper = ({ children }: { children: ReactNode }) =>
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const dashboard = renderHook(() => useMaintenanceDashboardRealtime(), { wrapper });
    expect(realtime.subscribe).toHaveBeenCalledWith(
      'maintenance.dashboard', '.maintenance.work-order.changed', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    expect(realtime.subscribe).toHaveBeenCalledWith(
      'maintenance.dashboard', '.maintenance.schedule.changed', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    act(() => realtime.handler?.({ refresh: true, entity_type: 'maintenance_work_order' }));
    await waitFor(() => expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['dashboard', 'widget-data', ['maintenance'] ],
    } as never))).toBe(true));
    expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['kpi', 'maintenance'],
    } as never))).toBe(true);

    dashboard.unmount();
    queryClient.clear();
  });

  it('subscribes maintenance schedule viewers to authorized record and list signals', () => {
    const queryClient = new QueryClient();
    const wrapper = ({ children }: { children: ReactNode }) =>
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const list = renderHook(() => useRealtimeList('maintenance_schedule', 'visible'), { wrapper });
    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.maintenance_schedule.visible', '.maintenance.schedule.changed', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    list.unmount();

    const record = renderHook(() => useRealtimeRecord('maintenance_schedule', 'schedule-hash'), { wrapper });
    expect(realtime.subscribe).toHaveBeenCalledWith(
      'maintenance_schedule.schedule-hash', '.maintenance.schedule.changed', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    record.unmount();
    queryClient.clear();
  });

  it('subscribes internal and portal return-case views to the matching authorized signals', () => {
    const queryClient = new QueryClient();
    const wrapper = ({ children }: { children: ReactNode }) =>
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const internalList = renderHook(() => useRealtimeList('return_case', 'customer'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'entity.return_case.customer', '.return.case.refresh', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    internalList.unmount();

    const record = renderHook(() => useRealtimeRecord('return_case', 'case-hash'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'return_case.case-hash', '.return.case.refresh', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    record.unmount();

    const portal = renderHook(() => useReturnCasePortalRealtime('customer', 'customer-hash'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'customer.account.customer-hash', '.return.case.refresh', expect.any(Function), expect.any(Function), expect.any(Function),
    );
    portal.unmount();
    queryClient.clear();
  });

  it('uses the supplier catalog signal for supplier listing and approved supplier lists', () => {
    const queryClient = new QueryClient();
    const wrapper = ({ children }: { children: ReactNode }) =>
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const listings = renderHook(() => useRealtimeList('supplier_listing', 'visible'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'entity.supplier_listing.visible',
      '.supplier.catalog.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    listings.unmount();

    const approvedSuppliers = renderHook(() => useRealtimeList('approved_supplier', 'visible'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'entity.approved_supplier.visible',
      '.supplier.catalog.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    approvedSuppliers.unmount();
    queryClient.clear();
  });

  it('subscribes to the private RFQ record refresh event', () => {
    const queryClient = new QueryClient();
    const { unmount } = renderHook(() => useRealtimeRecord('request_for_quote', 'rfq-hash'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'rfq.rfq-hash',
      '.rfq.refresh',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    unmount();
    queryClient.clear();
  });

  it('subscribes quality record and queue views to the authorized inspection signal', () => {
    const queryClient = new QueryClient();
    const wrapper = ({ children }: { children: ReactNode }) =>
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const list = renderHook(() => useRealtimeList('inspection', 'visible'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'entity.inspection.visible',
      '.inspection.refresh',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    list.unmount();

    const record = renderHook(() => useRealtimeRecord('inspection', 'qc-hash'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'inspection.qc-hash',
      '.inspection.refresh',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    record.unmount();
    queryClient.clear();
  });

  it('subscribes NCR detail and work queues to the authorized refresh signal', () => {
    const queryClient = new QueryClient();
    const wrapper = ({ children }: { children: ReactNode }) =>
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const list = renderHook(() => useRealtimeList('ncr', 'visible'), { wrapper });
    expect(realtime.subscribe).toHaveBeenCalledWith(
      'entity.ncr.visible',
      '.ncr.refresh',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    list.unmount();

    const record = renderHook(() => useRealtimeRecord('ncr', 'ncr-hash'), { wrapper });
    expect(realtime.subscribe).toHaveBeenCalledWith(
      'ncr.ncr-hash',
      '.ncr.refresh',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    record.unmount();
    queryClient.clear();
  });

  it('subscribes calibration detail and lists to the authorized Quality refresh signal', () => {
    const queryClient = new QueryClient();
    const wrapper = ({ children }: { children: ReactNode }) =>
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const list = renderHook(() => useRealtimeList('calibration', 'visible'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'entity.calibration.visible',
      '.quality.record.refresh',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    list.unmount();

    const record = renderHook(() => useRealtimeRecord('calibration', 'cal-hash'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'calibration.cal-hash',
      '.quality.record.refresh',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    record.unmount();
    queryClient.clear();
  });

  it('subscribes the spec editor and list to the authorized revision signal', () => {
    const queryClient = new QueryClient();
    const wrapper = ({ children }: { children: ReactNode }) =>
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const list = renderHook(() => useRealtimeList('inspection_spec', 'visible'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'entity.inspection_spec.visible',
      '.quality.record.refresh',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    list.unmount();

    const record = renderHook(() => useRealtimeRecord('inspection_spec', 'spec-hash'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'inspection_spec.spec-hash',
      '.quality.record.refresh',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    record.unmount();
    queryClient.clear();
  });

  it('subscribes template catalog readers to the authorized Quality refresh signal', () => {
    const queryClient = new QueryClient();
    const { unmount } = renderHook(() => useRealtimeList('ncr_template', 'visible'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'entity.ncr_template.visible',
      '.quality.record.refresh',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    unmount();
    queryClient.clear();
  });

  it('subscribes item quality-plan readers to their item-scoped Quality refresh signal', () => {
    const queryClient = new QueryClient();
    const { unmount } = renderHook(() => useRealtimeRecord('quality_plan', 'item-hash'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'quality_plan.item-hash',
      '.quality.record.refresh',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    unmount();
    queryClient.clear();
  });

  it('subscribes Return Management list and detail readers to scope-matched private channels', () => {
    const queryClient = new QueryClient();
    const wrapper = ({ children }: { children: ReactNode }) =>
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const list = renderHook(() => useRealtimeList('return_request', 'customer'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'entity.return_request.customer',
      '.return.request.refresh',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    list.unmount();

    const record = renderHook(() => useRealtimeRecord('return_request', 'rma-hash'), { wrapper });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'return_request.rma-hash',
      '.return.request.refresh',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    record.unmount();
    queryClient.clear();
  });

  it('subscribes traceability searches to the shipment-lot refresh signal', () => {
    const queryClient = new QueryClient();
    const { unmount } = renderHook(() => useRealtimeList('shipment_lot', 'visible'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });
    expect(realtime.subscribe).toHaveBeenLastCalledWith(
      'entity.shipment_lot.visible',
      '.shipment.lot.refresh',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );
    unmount();
    queryClient.clear();
  });

  it('subscribes to the vendor-private supplier listing signal and catches up the scoped portal list', async () => {
    const queryClient = new QueryClient();
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries');
    const { unmount } = renderHook(() => useSupplierListingRealtime('vendor-hash'), {
      wrapper: ({ children }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
    });

    expect(realtime.subscribe).toHaveBeenCalledWith(
      'supplier.vendor.vendor-hash',
      '.supplier.catalog.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );

    const onSubscribed = realtime.subscribe.mock.calls.at(-1)?.[3] as (() => void) | undefined;
    act(() => onSubscribed?.());
    await waitFor(() => expect(invalidate).toHaveBeenCalled());
    expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['portal', 'supplier', 'item-listings', 'vendor-hash', { page: 1 }],
    } as never))).toBe(true);

    unmount();
    queryClient.clear();
  });
});
