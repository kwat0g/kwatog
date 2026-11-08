import { describe, expect, it, vi } from 'vitest';
import { QueryClient, QueryObserver } from '@tanstack/react-query';
import {
  cacheGroupInvalidationMap,
  catchUpRealtimeQueries,
  invalidateCacheGroup,
  invalidateRealtimeChange,
  reconcileRealtimeMutationResponse,
  invalidateRealtimeList,
  invalidateRealtimeSignal,
  queryKeysForRealtimeChange,
  queryKeysForRealtimeList,
  queryKeysForRealtimeSignal,
  isRealtimeCacheGroup,
  realtimeEventNameForEntity,
  scheduleRealtimeChangeInvalidation,
  scheduleRealtimeEntityCatchUp,
  scheduleRealtimeListInvalidation,
  scheduleRealtimeQueryInvalidation,
  scheduleRealtimeSignalInvalidation,
} from './invalidationMap';
import { inspectionQueryKeys } from '@/lib/queryKeys/inspections';
import { isRealtimeManagedQueryKey } from './queryFreshness';
import { returnCaseQueryKeys } from '@/lib/queryKeys/returnCases';

describe('realtime invalidation map', () => {
  it('catches up mapped live data without refetching unmapped reference data', async () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false, staleTime: Infinity, gcTime: Infinity } },
    });
    const recordKey = inspectionQueryKeys.detail('qc-catch-up-scope');
    const relatedKey = ['sales-order-fulfillment', 'so-catch-up-scope'] as const;
    const referenceKey = ['production', 'downtime-categories'] as const;
    client.setQueryData(recordKey, { id: 'qc-catch-up-scope', status: 'draft' });
    client.setQueryData(relatedKey, { status: 'pending' });
    client.setQueryData(referenceKey, [{ id: 'planned', label: 'Planned' }]);
    const recordQuery = vi.fn().mockResolvedValue({ id: 'qc-catch-up-scope', status: 'released' });
    const relatedQuery = vi.fn().mockResolvedValue({ status: 'fulfilled' });
    const referenceQuery = vi.fn().mockResolvedValue([{ id: 'planned', label: 'Planned' }]);
    const recordObserver = new QueryObserver(client, { queryKey: recordKey, queryFn: recordQuery });
    const relatedObserver = new QueryObserver(client, { queryKey: relatedKey, queryFn: relatedQuery });
    const referenceObserver = new QueryObserver(client, { queryKey: referenceKey, queryFn: referenceQuery });
    const unsubscribeRecord = recordObserver.subscribe(() => undefined);
    const unsubscribeRelated = relatedObserver.subscribe(() => undefined);
    const unsubscribeReference = referenceObserver.subscribe(() => undefined);

    try {
      await catchUpRealtimeQueries(client);

      expect(recordQuery).toHaveBeenCalledTimes(1);
      expect(relatedQuery).toHaveBeenCalledTimes(1);
      expect(referenceQuery).not.toHaveBeenCalled();
    } finally {
      unsubscribeRecord();
      unsubscribeRelated();
      unsubscribeReference();
      client.clear();
    }
  });

  it('installs mutation data before refreshing mapped active families', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const detailKey = ['crm', 'sales-orders', 'detail', 'so-1'] as const;
    const listKey = ['crm', 'sales-orders', { status: 'draft' }] as const;
    const draft = { id: 'so-1', status: 'draft' };
    const confirmed = { id: 'so-1', status: 'confirmed' };
    let detailReads = 0;
    let listReads = 0;
    const detailObserver = new QueryObserver(client, {
      queryKey: detailKey,
      queryFn: async () => { detailReads += 1; return draft; },
    });
    const listObserver = new QueryObserver(client, {
      queryKey: listKey,
      queryFn: async () => { listReads += 1; return [draft]; },
    });
    const stopDetail = detailObserver.subscribe(() => undefined);
    const stopList = listObserver.subscribe(() => undefined);

    try {
      await Promise.all([detailObserver.refetch(), listObserver.refetch()]);
      reconcileRealtimeMutationResponse(
        client,
        { entityType: 'sales_order', entityId: 'so-1' },
        detailKey,
        confirmed,
      );

      await vi.waitFor(() => expect(listReads).toBe(2));
      expect(detailReads).toBe(1);
      expect(client.getQueryData(detailKey)).toEqual(confirmed);
    } finally {
      stopDetail();
      stopList();
      client.clear();
    }
  });

  it('does not let an older in-flight read overwrite the actor mutation response', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const detailKey = ['crm', 'sales-orders', 'detail', 'so-race'] as const;
    const pending = { id: 'so-race', status: 'draft' };
    const confirmed = { id: 'so-race', status: 'confirmed' };
    let resolveRead: ((record: typeof pending) => void) | undefined;
    const observer = new QueryObserver(client, {
      queryKey: detailKey,
      queryFn: () => new Promise<typeof pending>((resolve) => { resolveRead = resolve; }),
    });
    const unsubscribe = observer.subscribe(() => undefined);

    try {
      const staleRead = observer.refetch();
      await vi.waitFor(() => expect(resolveRead).toBeDefined());

      reconcileRealtimeMutationResponse(
        client,
        { entityType: 'sales_order', entityId: 'so-race' },
        detailKey,
        confirmed,
      );
      expect(client.getQueryData(detailKey)).toEqual(confirmed);

      resolveRead?.(pending);
      await staleRead;
      expect(client.getQueryData(detailKey)).toEqual(confirmed);
    } finally {
      resolveRead?.(pending);
      unsubscribe();
      client.clear();
    }
  });

  it('maps import-batch refreshes to the guarded batch-history query', () => {
    expect(realtimeEventNameForEntity('import_batch')).toBe('.admin.import-batch.changed');
    expect(queryKeysForRealtimeList('import_batch')).toEqual([['imports', 'batches']]);
  });

  it('maps KPI snapshot events to scorecard, trend, and dependent dashboard queries', () => {
    expect(realtimeEventNameForEntity('kpi_snapshot')).toBe('.kpi.snapshot.changed');
    expect(queryKeysForRealtimeList('kpi_snapshot')).toContainEqual(['kpi']);
    expect(queryKeysForRealtimeChange({ entityType: 'kpi_snapshot', entityId: 'snapshot-1' }))
      .toContainEqual(['kpi']);
    expect(queryKeysForRealtimeChange({ entityType: 'kpi_snapshot', entityId: 'snapshot-1' }))
      .toContainEqual(['dashboard', 'widget-data']);
    expect(queryKeysForRealtimeSignal('kpi_snapshot_changed')).toEqual([
      ['kpi'],
      ['dashboard', 'widget-data'],
    ]);
  });

  it('refreshes a user dashboard layout and its widget data after a sibling-tab save', () => {
    expect(queryKeysForRealtimeSignal('dashboard_layout_changed')).toEqual([
      ['dashboard', 'layout'],
      ['dashboard', 'widget-data'],
    ]);
  });

  it('refreshes configurable dashboard data after any widget source changes', () => {
    expect(queryKeysForRealtimeSignal('dashboard_widget_data_changed')).toEqual([
      ['dashboard', 'widget-data'],
      ['inventory', 'dashboard'],
    ]);
  });

  it('refreshes delegated-role bottlenecks when the viewer’s delegation changes', () => {
    expect(queryKeysForRealtimeSignal('approval_delegations_changed')).toEqual([
      ['approval-delegations'],
      ['chain-bottlenecks'],
      ['dashboard', 'widget-data'],
    ]);
  });

  it('refreshes the PPC dashboard after a PPC aggregate source changes', () => {
    expect(queryKeysForRealtimeSignal('ppc_dashboard_changed')).toEqual([
      ['dashboard', 'ppc'],
      ['dashboard', 'widget-data'],
      ['chain-bottlenecks'],
    ]);
  });

  it('refreshes finance aggregate and chain queries after a Finance source changes', () => {
    expect(queryKeysForRealtimeSignal('finance_dashboard_changed')).toEqual([
      ['dashboard', 'finance'],
      ['dashboard', 'widget-data'],
      ['chain-bottlenecks'],
    ]);
  });

  it('refreshes the employee self-service, dashboard, and configurable widget query families', () => {
    expect(queryKeysForRealtimeSignal('employee_dashboard_changed')).toEqual([
      ['self-service'],
      ['dashboard', 'employee'],
      ['dashboard', 'widget-data'],
    ]);
  });

  it('invalidates inventory form options when their backing settings change', () => {
    const keys = queryKeysForRealtimeSignal('settings_changed');

    expect(keys).toContainEqual(['inventory', 'stock-adjustments', 'options']);
    expect(keys).toContainEqual(['warehouse', 'stock-count-options']);
  });

  it('maps stock adjustment writes to stock balances, movement history, and work queues', () => {
    const keys = queryKeysForRealtimeChange({ entityType: 'stock_adjustment', entityId: 'adjustment-1' });

    expect(keys).toContainEqual(['inventory', 'stock-adjustments', 'adjustment-1']);
    expect(keys).toContainEqual(['inventory', 'stock-adjustments']);
    expect(keys).toContainEqual(['inventory', 'stock-levels']);
    expect(keys).toContainEqual(['inventory', 'stock-movements']);
    expect(keys).toContainEqual(['sidebar', 'badges']);
    expect(keys).toContainEqual(['approvals']);
    expect(keys).toContainEqual(['dashboard']);
  });

  it('maps Delivery writes to assigned vehicles and Invoice writes to embedded Delivery details', () => {
    expect(queryKeysForRealtimeChange({ entityType: 'delivery', entityId: 'delivery-1' }))
      .toContainEqual(['supply-chain', 'vehicles']);
    expect(queryKeysForRealtimeChange({ entityType: 'invoice', entityId: 'invoice-1' }))
      .toContainEqual(['supply-chain', 'deliveries']);
  });

  it('invalidates all cached settings-backed option readers', () => {
    const keys = queryKeysForRealtimeSignal('settings_changed');
    const optionFamilies = [
      ['landing', 'contact'],
      ['landing', 'content'],
      ['auth', 'password-policy'],
      ['business-policies'],
      ['portal', 'supplier', 'business-policies'],
      ['inventory', 'items', 'options'],
      ['inventory', 'grn', 'options'],
      ['inventory', 'quality-plans', 'options'],
      ['purchasing', 'purchase-request-options'],
      ['purchasing', 'purchase-orders', 'options'],
      ['loan-options'],
      ['budgets', 'options'],
      ['maintenance', 'work-order-options'],
      ['maintenance', 'condition-metric-options'],
      ['maintenance', 'condition-readings', 'options'],
      ['return-management', 'options'],
      ['quality', 'ncr-options'],
      ['quality', 'ncrs', 'options'],
      ['quality', 'inspection-options'],
      ['quality', 'inspections', 'options'],
      ['forecasting', 'demand-options'],
      ['forecasting', 'options'],
      ['mrp', 'molds', 'options'],
      ['mrp', 'scheduler', 'options'],
      ['payroll-periods', 'options'],
      ['payroll-bank-file-options'],
    ];

    for (const family of optionFamilies) expect(keys).toContainEqual(family);
  });

  it('maps employee-document refreshes to both HR document stores and type options', () => {
    expect(realtimeEventNameForEntity('employee_document')).toBe('.hr.employee-documents.changed');
    expect(queryKeysForRealtimeChange({ entityType: 'employee_document', entityId: 'employee-hash' })).toContainEqual([
      'employee-documents',
      'employee-hash',
    ]);
    expect(queryKeysForRealtimeList('employee_document')).toContainEqual(['documents', 'employees']);
    expect(queryKeysForRealtimeList('employee_document')).toContainEqual(['employee-document-options']);
  });

  it('maps employee skill refreshes to the profile list and competence matrices', () => {
    expect(realtimeEventNameForEntity('employee_skill')).toBe('.hr.employee-skill.changed');
    expect(queryKeysForRealtimeChange({ entityType: 'employee_skill', entityId: 'employee-hash' })).toContainEqual([
      'employee-skills',
      'employee-hash',
    ]);
    expect(queryKeysForRealtimeList('employee_skill')).toContainEqual(['training-matrix']);
    expect(queryKeysForRealtimeList('employee_skill')).toContainEqual(['hr', 'skills', 'matrix']);
  });

  it('maps MRB lifecycle refreshes to the detail, list, quality, stock, and badge queries', () => {
    expect(realtimeEventNameForEntity('material_review_record')).toBe('.inventory.mrb.changed');
    expect(queryKeysForRealtimeList('material_review_record')).toContainEqual(['inventory', 'mrb']);
    const keys = queryKeysForRealtimeChange({
      entityType: 'material_review_record',
      entityId: 'mrb-hash',
    });
    expect(keys).toContainEqual(['inventory', 'mrb', 'mrb-hash']);
    expect(keys).toContainEqual(['quality', 'ncrs']);
    expect(keys).toContainEqual(['inventory', 'stock-levels']);
    expect(keys).toContainEqual(['sidebar', 'badges']);
  });

  it('maps material-issue refreshes to the slip, stock, dashboard, and production queries', () => {
    expect(realtimeEventNameForEntity('material_issue_slip')).toBe('.inventory.material-issue.changed');
    expect(queryKeysForRealtimeList('material_issue_slip')).toContainEqual(['inventory', 'material-issues']);
    const keys = queryKeysForRealtimeChange({ entityType: 'material_issue_slip', entityId: 'slip-hash' });
    expect(keys).toContainEqual(['inventory', 'material-issues', 'slip-hash']);
    expect(keys).toContainEqual(['inventory', 'stock-levels']);
    expect(keys).toContainEqual(['inventory', 'reservations']);
    expect(keys).toContainEqual(['production', 'work-orders']);
  });

  it('maps routing refreshes to detail, list, product, MRP, and production dependents', () => {
    expect(realtimeEventNameForEntity('product_routing')).toBe('.production.routing.changed');
    expect(queryKeysForRealtimeList('product_routing')).toContainEqual(['production', 'routings']);
    const keys = queryKeysForRealtimeChange({
      entityType: 'product_routing',
      entityId: 'routing-hash',
      related: [{ entityType: 'product', entityId: 'product-hash' }],
    });
    expect(keys).toContainEqual(['production', 'routings', 'detail', 'routing-hash']);
    expect(keys).toContainEqual(['crm', 'products', 'detail', 'product-hash']);
    expect(keys).toContainEqual(['mrp']);
    expect(keys).toContainEqual(['production', 'work-orders']);
    expect(keys).toContainEqual(['production', 'dashboard']);
  });

  it('maps skill catalog refreshes to the catalog and dependent competence views', () => {
    expect(realtimeEventNameForEntity('skill')).toBe('.hr.skill.changed');
    expect(queryKeysForRealtimeList('skill')).toContainEqual(['hr', 'skills']);
    expect(queryKeysForRealtimeList('skill')).toContainEqual(['employee-skills']);
    expect(queryKeysForRealtimeList('skill')).toContainEqual(['training-matrix']);
    expect(queryKeysForRealtimeChange({ entityType: 'skill', entityId: 'skill-hash' })).toContainEqual([
      'hr',
      'skills',
    ]);
  });

  it('maps profile-request changes to scoped review queues, requesters, and related employees', () => {
    expect(realtimeEventNameForEntity('profile_update_request')).toBe('.hr.profile-update-request.changed');
    expect(queryKeysForRealtimeList('profile_update_request')).toContainEqual(['hr', 'profile-update-requests']);
    expect(queryKeysForRealtimeList('profile_update_request')).toContainEqual(['self-service', 'profile-requests']);
    expect(queryKeysForRealtimeChange({
      entityType: 'profile_update_request',
      entityId: 'request-hash',
      related: [{ entityType: 'employee', entityId: 'employee-hash' }],
    })).toContainEqual(['hr', 'employee', 'employee-hash']);
  });

  it('maps registered cache groups to dependent active query families', async () => {
    expect(cacheGroupInvalidationMap.dashboards).toContainEqual(['dashboard']);
    expect(cacheGroupInvalidationMap['financial-statements']).toContainEqual(['accounting', 'statements']);
    expect(cacheGroupInvalidationMap['procurement-chain']).toContainEqual(['purchasing', 'chain']);
    expect(isRealtimeCacheGroup('dashboards')).toBe(true);
    expect(isRealtimeCacheGroup('arbitrary-key')).toBe(false);

    const invalidateQueries = vi.fn().mockResolvedValue(undefined);
    await invalidateCacheGroup({ invalidateQueries } as never, 'procurement-chain');

    expect(invalidateQueries).toHaveBeenCalledTimes(1);
    const matches = invalidateQueries.mock.calls[0][0].predicate;
    expect(matches({ queryKey: ['purchasing', 'chain', 'summary'] })).toBe(true);
    expect(matches({ queryKey: ['purchasing', 'purchase-orders', 'list'] })).toBe(true);
    expect(matches({ queryKey: ['hr', 'employees'] })).toBe(false);
  });

  it('maps shared badge signals to badges, approvals, and action-center queries', () => {
    expect(queryKeysForRealtimeSignal('badges_changed')).toEqual([
      ['sidebar', 'badges'],
      ['alerts'],
      ['approvals'],
      ['action-center'],
      ['dashboard'],
      ['accounting', 'dashboard'],
      ['dashboards'],
    ]);
  });

  it('refreshes the employee DTR after an attendance change', () => {
    expect(queryKeysForRealtimeSignal('attendance_changed')).toContainEqual(['self-service', 'dtr']);
  });

  it('maps Quality dashboard source signals to the dashboard and KPI queries', () => {
    expect(queryKeysForRealtimeSignal('quality_dashboard_changed')).toEqual([
      ['dashboard', 'quality'],
      ['dashboard', 'widget-data'],
      ['kpi'],
    ]);
  });

  it('maps Plant Manager dashboard source signals to its dashboard and KPI queries', () => {
    expect(queryKeysForRealtimeSignal('plant_manager_dashboard_changed')).toEqual([
      ['dashboard', 'plant-manager'],
      ['dashboard', 'widget-data'],
      ['kpi'],
    ]);
  });

  it('refreshes configurable widget snapshots for every aggregate dashboard signal', () => {
    const dashboardSignals = [
      'admin_dashboard_changed',
      'hr_dashboard_changed',
      'employee_dashboard_changed',
      'plant_manager_dashboard_changed',
      'purchasing_dashboard_changed',
      'quality_dashboard_changed',
    ] as const;

    for (const signal of dashboardSignals) {
      expect(queryKeysForRealtimeSignal(signal), signal).toContainEqual(['dashboard', 'widget-data']);
    }
  });

  it('maps supplier PO signals to vendor-scoped detail, list, and dashboard query families', () => {
    expect(queryKeysForRealtimeSignal('supplier_purchase_orders_changed')).toEqual([
      ['portal', 'supplier', 'pos'],
      ['portal', 'supplier', 'po'],
      ['portal', 'supplier', 'dashboard'],
      ['return-cases', 'sources'],
      ['return-cases', 'source-options'],
    ]);
  });

  it('maps customer RMA signals to the customer portal list and detail queries', () => {
    expect(queryKeysForRealtimeSignal('customer_returns_changed')).toEqual([
      ['portal', 'customer', 'returns'],
      ['portal', 'customer', 'return'],
      ['return-cases', 'sources'],
      ['return-cases', 'source-options'],
    ]);
  });

  it('maps PriceRequest refreshes to internal, portal, review, and dashboard queries', () => {
    expect(realtimeEventNameForEntity('price_request')).toBe('.crm.price-request.changed');
    expect(queryKeysForRealtimeList('price_request')).toEqual(expect.arrayContaining([
      ['pricing-requests'],
      ['portal', 'pricing'],
      ['pricing-request-review'],
      ['dashboard', 'widget-data'],
      ['dashboard'],
      ['dashboards'],
    ]));

    expect(queryKeysForRealtimeChange({ entityType: 'price_request', entityId: 'request-hash' }))
      .toEqual(expect.arrayContaining([
        ['pricing-requests', 'request-hash'],
        ['pricing-requests'],
        ['portal', 'pricing'],
        ['pricing-request-review'],
        ['dashboard', 'widget-data'],
        ['dashboard'],
        ['dashboards'],
      ]));
  });

  it('maps Demand Forecast signals to forecast and derived dashboard query families', () => {
    expect(realtimeEventNameForEntity('demand_forecast')).toBe('.forecast.demand-forecast.changed');

    const list = queryKeysForRealtimeList('demand_forecast');
    expect(list).toContainEqual(['forecasting/list']);
    expect(list).toContainEqual(['forecasting', 'demand']);
    expect(list).toContainEqual(['forecasting/accuracy/summary']);
    expect(list).toContainEqual(['forecasting/stock-out']);
    expect(list).toContainEqual(['forecasting', 'accuracy', 'summary']);
    expect(list).toContainEqual(['forecasting', 'stock-out']);
    expect(list).toContainEqual(['dashboard', 'purchasing']);
    expect(isRealtimeManagedQueryKey(['forecasting', 'accuracy', 'summary', 2026])).toBe(true);
    expect(isRealtimeManagedQueryKey(['forecasting', 'stock-out', 30])).toBe(true);
  });

  it('refreshes forecast labels when a Product changes', () => {
    const list = queryKeysForRealtimeList('product');

    expect(list).toContainEqual(['forecasting/list']);
    expect(list).toContainEqual(['forecasting', 'demand']);
    expect(list).toContainEqual(['forecasting/accuracy/products']);
    expect(list).toContainEqual(['forecasting', 'accuracy', 'products']);
    expect(isRealtimeManagedQueryKey(['forecasting', 'accuracy', 'products', 2026])).toBe(true);
  });

  it('invalidates both production and dashboard forecast query key shapes', async () => {
    const client = new QueryClient();
    const forecastKeys = [
      ['forecasting/accuracy/summary', 2026],
      ['forecasting', 'accuracy', 'summary', 2026],
      ['forecasting', 'stock-out', 30],
    ] as const;
    const productKeys = [
      ['forecasting/accuracy/products', 2026],
      ['forecasting', 'accuracy', 'products', 2026],
    ] as const;

    for (const key of [...forecastKeys, ...productKeys]) client.setQueryData(key, { cached: true });
    await invalidateRealtimeList(client, 'demand_forecast');
    await invalidateRealtimeList(client, 'product');

    for (const key of [...forecastKeys, ...productKeys]) {
      expect(client.getQueryCache().find({ queryKey: key })?.state.isInvalidated, JSON.stringify(key)).toBe(true);
      expect(isRealtimeManagedQueryKey(key)).toBe(true);
    }
    client.clear();
  });

  it('refreshes Customer details, cross-module lookups, and related customer views', () => {
    expect(realtimeEventNameForEntity('customer')).toBe('.accounting.customer.changed');

    const list = queryKeysForRealtimeList('customer');
    expect(list).toContainEqual(['accounting', 'customers']);
    expect(list).toContainEqual(['crm', 'customers']);
    expect(list).toContainEqual(['forecasting', 'customer-options']);
    expect(list).toContainEqual(['forecasting', 'demand']);
    expect(list).toContainEqual(['dashboard', 'finance']);
    expect(list).toContainEqual(['dashboard', 'warehouse']);
  });

  it('maps Admin Backups refresh signals to the backup operation list', () => {
    expect(queryKeysForRealtimeSignal('admin_backups_changed')).toEqual([
      ['admin', 'backups'],
    ]);
  });

  it('invalidates invite lists and nested employee and role selectors for User Invite signals', async () => {
    vi.useFakeTimers();
    const invalidateQueries = vi.fn().mockResolvedValue(undefined);
    const queryClient = { invalidateQueries } as never;

    scheduleRealtimeSignalInvalidation(queryClient, 'user_invites_changed');
    await vi.advanceTimersByTimeAsync(300);

    expect(invalidateQueries).toHaveBeenCalledTimes(1);
    const predicate = invalidateQueries.mock.calls[0][0].predicate;
    expect(predicate({ queryKey: ['admin', 'invites', { status: 'pending' }] })).toBe(true);
    expect(predicate({ queryKey: ['admin', 'invites', 'employees-without-user'] })).toBe(true);
    expect(predicate({ queryKey: ['admin', 'invites', 'roles'] })).toBe(true);
    expect(predicate({ queryKey: ['admin', 'users'] })).toBe(false);

    vi.useRealTimers();
  });

  it('maps chain listener run changes to the recovery list query family', () => {
    expect(queryKeysForRealtimeSignal('chain_listener_runs_changed')).toEqual([
      ['chain', 'listener-runs'],
      ['chain-bottlenecks'],
      ['dashboard', 'widget-data'],
    ]);
  });

  it('maps finance bottleneck refreshes to the audience-scoped widget query', () => {
    expect(queryKeysForRealtimeSignal('chain_bottlenecks_changed')).toEqual([
      ['chain-bottlenecks'],
    ]);
  });

  it('maps production dashboard refreshes to configurable dashboard widgets', () => {
    expect(queryKeysForRealtimeSignal('production_dashboard_changed')).toContainEqual([
      'dashboard', 'widget-data',
    ]);
  });

  it('maps Purchasing dashboard refreshes to the scoped dashboard query', () => {
    expect(queryKeysForRealtimeSignal('purchasing_dashboard_changed')).toEqual([
      ['dashboard', 'purchasing'],
      ['dashboard', 'widget-data'],
    ]);
  });

  it('maps supplier receipt and bill changes to only their dependent portal query families', () => {
    expect(queryKeysForRealtimeSignal('supplier_receipts_changed')).toEqual([
      ['portal', 'supplier', 'po'],
      ['portal', 'supplier', 'dashboard'],
      ['return-cases', 'sources'],
      ['return-cases', 'source-options'],
    ]);
    expect(queryKeysForRealtimeSignal('supplier_bills_changed')).toEqual([
      ['portal', 'supplier', 'po'],
      ['portal', 'supplier', 'invoices'],
      ['portal', 'supplier', 'invoice'],
      ['portal', 'supplier', 'statement-of-account'],
      ['portal', 'supplier', 'dashboard'],
    ]);
  });

  it('keeps an API-response-seeded supplier PO detail fresh while invalidating related views', async () => {
    const invalidateQueries = vi.fn().mockResolvedValue(undefined);
    const queryClient = { invalidateQueries } as never;

    await invalidateRealtimeSignal(queryClient, 'supplier_purchase_orders_changed', [
      ['portal', 'supplier', 'po'],
    ]);

    expect(invalidateQueries).toHaveBeenCalledTimes(1);
    const matches = invalidateQueries.mock.calls[0][0].predicate;
    expect(matches({ queryKey: ['portal', 'supplier', 'po', 'vendor-1', 'po-1'] })).toBe(false);
    expect(matches({ queryKey: ['portal', 'supplier', 'pos', 'vendor-1', { page: 1 }] })).toBe(true);
    expect(matches({ queryKey: ['portal', 'supplier', 'dashboard', 'vendor-1'] })).toBe(true);
  });

  it('maps government contribution table pings to the active admin reference list', () => {
    expect(realtimeEventNameForEntity('government_table')).toBe('.government.table.changed');
    expect(queryKeysForRealtimeList('government_table')).toContainEqual(['gov-tables']);
  });

  it('maps department changes to the shared reference and related HR query families', () => {
    expect(realtimeEventNameForEntity('department')).toBe('.hr.department.changed');
    const keys = queryKeysForRealtimeList('department');
    expect(keys).toContainEqual(['hr', 'departments']);
    expect(keys).toContainEqual(['departments-tree']);
    expect(keys).toContainEqual(['positions']);
    expect(keys).toContainEqual(['attendance']);
    expect(keys).toContainEqual(['budgets']);
  });

  it('maps accounting period changes to period lists and the finance dashboard', () => {
    expect(realtimeEventNameForEntity('accounting_period')).toBe('.accounting.period.changed');
    const keys = queryKeysForRealtimeList('accounting_period');
    expect(keys).toContainEqual(['accounting', 'periods']);
    expect(keys).toContainEqual(['dashboard', 'finance']);
  });

  it('maps Finance source events to the chain-bottleneck widget', () => {
    for (const entity of ['bill', 'invoice', 'journal_entry', 'payroll_period', 'grn'] as const) {
      expect(queryKeysForRealtimeList(entity)).toContainEqual(['chain-bottlenecks']);
    }
  });

  it('maps Admin session changes to the active sessions list', () => {
    expect(queryKeysForRealtimeSignal('admin_sessions_changed')).toContainEqual(['admin', 'sessions']);
  });

  it('maps position changes to HR, employee, and recruitment queries', () => {
    expect(realtimeEventNameForEntity('position')).toBe('.hr.position.changed');
    const keys = queryKeysForRealtimeList('position');
    expect(keys).toContainEqual(['hr', 'positions']);
    expect(keys).toContainEqual(['positions']);
    expect(keys).toContainEqual(['hr', 'employees']);
    expect(keys).toContainEqual(['recruitment-postings']);
  });

  it('maps HR training changes to record lists, details, and dependent employee views', () => {
    expect(realtimeEventNameForEntity('employee_training')).toBe('.hr.employee-training.changed');
    const records = queryKeysForRealtimeChange({ entityType: 'employee_training', entityId: 'record-hash' });
    expect(records).toContainEqual(['employee-training', 'record-hash']);
    expect(records).toContainEqual(['hr', 'employee-training-records']);
    expect(records).toContainEqual(['employee-trainings']);
    expect(records).toContainEqual(['sidebar', 'badges']);

    expect(realtimeEventNameForEntity('training')).toBe('.hr.training.changed');
    const catalog = queryKeysForRealtimeChange({ entityType: 'training', entityId: 'training-hash' });
    expect(catalog).toContainEqual(['hr', 'training', 'training-hash']);
    expect(catalog).toContainEqual(['hr', 'trainings']);
    expect(catalog).toContainEqual(['employee-training']);
  });

  it('maps de minimis ledger changes to payroll records and list queries', () => {
    expect(realtimeEventNameForEntity('payroll_de_minimis')).toBe('.payroll.de-minimis.changed');
    expect(queryKeysForRealtimeList('payroll_de_minimis')).toContainEqual(['de-minimis']);
  });

  it('maps payroll anomaly signals to active payroll and period query families', () => {
    expect(realtimeEventNameForEntity('payroll_anomaly')).toBe('.payroll.anomaly.changed');
    expect(queryKeysForRealtimeChange({ entityType: 'payroll_anomaly', entityId: 'period-1' }))
      .toContainEqual(['payroll', 'period-1']);
    expect(queryKeysForRealtimeList('payroll_anomaly')).toEqual([
      ['payroll'], ['payroll-period'],
    ]);
  });

  it('coalesces a shared signal into one active-query invalidation', async () => {
    vi.useFakeTimers();
    const invalidateQueries = vi.fn().mockResolvedValue(undefined);
    const queryClient = { invalidateQueries } as never;

    scheduleRealtimeSignalInvalidation(queryClient, 'badges_changed');
    await vi.advanceTimersByTimeAsync(300);

    expect(invalidateQueries).toHaveBeenCalledTimes(1);
    const predicate = invalidateQueries.mock.calls[0][0].predicate;
    expect(predicate({ queryKey: ['action-center'] })).toBe(true);
    expect(predicate({ queryKey: ['sidebar', 'badges'] })).toBe(true);
    expect(predicate({ queryKey: ['unrelated'] })).toBe(false);

    vi.useRealTimers();
  });

  it('coalesces newer record events behind a request whose API call cannot be aborted', async () => {
    vi.useFakeTimers();
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, staleTime: Infinity, gcTime: Infinity } },
    });
    const queryKey = ['purchasing', 'purchase-requests', 'pr-1'] as const;
    queryClient.setQueryData(queryKey, { id: 'pr-1', status: 'pending' });
    const pendingFetches: Array<(value: { id: string; status: string }) => void> = [];
    const queryFn = vi.fn(() => new Promise<{ id: string; status: string }>((resolve) => {
      pendingFetches.push(resolve);
    }));
    const observer = new QueryObserver(queryClient, { queryKey, queryFn });
    const unsubscribe = observer.subscribe(() => undefined);

    try {
      scheduleRealtimeChangeInvalidation(queryClient, { entityType: 'purchase_request', entityId: 'pr-1' });
      await vi.advanceTimersByTimeAsync(100);
      expect(queryFn).toHaveBeenCalledTimes(1);

      scheduleRealtimeChangeInvalidation(queryClient, { entityType: 'purchase_request', entityId: 'pr-1' });
      scheduleRealtimeChangeInvalidation(queryClient, { entityType: 'purchase_request', entityId: 'pr-1' });
      await vi.advanceTimersByTimeAsync(100);
      expect(queryFn).toHaveBeenCalledTimes(1);

      pendingFetches[0]({ id: 'pr-1', status: 'pending' });
      await Promise.resolve();
      await vi.advanceTimersByTimeAsync(500);
      expect(queryFn).toHaveBeenCalledTimes(2);

      pendingFetches[1]({ id: 'pr-1', status: 'approved' });
      await Promise.resolve();
      await Promise.resolve();
      expect(queryClient.getQueryData(queryKey)).toEqual({ id: 'pr-1', status: 'approved' });
    } finally {
      pendingFetches.forEach((resolve) => resolve({ id: 'pr-1', status: 'approved' }));
      unsubscribe();
      queryClient.clear();
      vi.useRealTimers();
    }
  });

  it('invalidates the viewer-scoped transfer-order detail query for a record event', async () => {
    vi.useFakeTimers();
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, staleTime: Infinity, gcTime: Infinity } },
    });
    const detailKey = ['inventory', 'transfer-order', 'to-1', 'user-1', 'warehouse_staff'] as const;
    let detailReads = 0;
    const detailObserver = new QueryObserver(queryClient, {
      queryKey: detailKey,
      queryFn: async () => ({ id: 'to-1', status: ++detailReads === 1 ? 'pending' : 'transferred' }),
    });
    const stopDetail = detailObserver.subscribe(() => undefined);

    try {
      await detailObserver.refetch();
      scheduleRealtimeChangeInvalidation(queryClient, { entityType: 'transfer_order', entityId: 'to-1' });
      await vi.advanceTimersByTimeAsync(100);
      await vi.waitFor(() => expect(detailReads).toBe(2));
      expect(queryClient.getQueryData(detailKey)).toEqual({ id: 'to-1', status: 'transferred' });
    } finally {
      stopDetail();
      queryClient.clear();
      vi.useRealTimers();
    }
  });

  it('cancels a superseded in-flight read before fetching the current record state', async () => {
    vi.useFakeTimers();
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, staleTime: Infinity, gcTime: Infinity } },
    });
    const queryKey = ['purchasing', 'purchase-requests', 'pr-2'] as const;
    queryClient.setQueryData(queryKey, { id: 'pr-2', status: 'pending' });
    let resolveStaleRead: ((value: { id: string; status: string }) => void) | undefined;
    let staleReadSignal: AbortSignal | undefined;
    const queryFn = vi.fn(({ signal }: { signal: AbortSignal }) => {
      if (queryFn.mock.calls.length === 1) {
        staleReadSignal = signal;
        return new Promise<{ id: string; status: string }>((resolve) => {
          resolveStaleRead = resolve;
        });
      }

      return Promise.resolve({ id: 'pr-2', status: 'approved' });
    });
    const observer = new QueryObserver(queryClient, { queryKey, queryFn });
    const unsubscribe = observer.subscribe(() => undefined);

    try {
      void observer.refetch();
      await Promise.resolve();
      expect(queryFn).toHaveBeenCalledTimes(1);

      scheduleRealtimeChangeInvalidation(queryClient, { entityType: 'purchase_request', entityId: 'pr-2' });
      await vi.advanceTimersByTimeAsync(100);
      await Promise.resolve();
      await Promise.resolve();

      expect(staleReadSignal?.aborted).toBe(true);
      expect(queryFn).toHaveBeenCalledTimes(2);
      expect(queryClient.getQueryData(queryKey)).toEqual({ id: 'pr-2', status: 'approved' });

      resolveStaleRead?.({ id: 'pr-2', status: 'pending' });
      await Promise.resolve();
      await Promise.resolve();
      expect(queryClient.getQueryData(queryKey)).toEqual({ id: 'pr-2', status: 'approved' });
    } finally {
      resolveStaleRead?.({ id: 'pr-2', status: 'pending' });
      unsubscribe();
      queryClient.clear();
      vi.useRealTimers();
    }
  });

  it('retries an event-invalidated detail read if a concurrent catch-up cancels its fetch', async () => {
    vi.useFakeTimers();
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, staleTime: Infinity, gcTime: Infinity } },
    });
    const queryKey = inspectionQueryKeys.detail('qc-race');
    const draft = { id: 'qc-race', status: 'draft' };
    const cancelled = { id: 'qc-race', status: 'cancelled' };
    queryClient.setQueryData(queryKey, draft);
    const queryFn = vi.fn(({ signal }: { signal: AbortSignal }) => {
      if (queryFn.mock.calls.length === 1) return Promise.resolve(draft);
      if (queryFn.mock.calls.length === 2) {
        return new Promise<typeof draft>((resolve) => {
          signal.addEventListener('abort', () => resolve(draft), { once: true });
        });
      }
      return Promise.resolve(cancelled);
    });
    const observer = new QueryObserver(queryClient, { queryKey, queryFn });
    const unsubscribe = observer.subscribe(() => undefined);

    try {
      await observer.refetch();
      scheduleRealtimeChangeInvalidation(queryClient, { entityType: 'inspection', entityId: 'qc-race' });
      await vi.advanceTimersByTimeAsync(100);
      expect(queryFn).toHaveBeenCalledTimes(2);

      await queryClient.cancelQueries({ queryKey }, { revert: true });
      await vi.advanceTimersByTimeAsync(0);
      await vi.waitFor(() => expect(queryFn).toHaveBeenCalledTimes(3));
      await vi.waitFor(() => expect(queryClient.getQueryData(queryKey)).toEqual(cancelled));
    } finally {
      unsubscribe();
      queryClient.clear();
      vi.useRealTimers();
    }
  });

  it('keeps focus catch-up from canceling an active record event refetch', async () => {
    vi.useFakeTimers();
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, staleTime: Infinity, gcTime: Infinity } },
    });
    const queryKey = inspectionQueryKeys.detail('qc-coalesce');
    const draft = { id: 'qc-coalesce', status: 'draft' };
    const cancelled = { id: 'qc-coalesce', status: 'cancelled' };
    queryClient.setQueryData(queryKey, draft);
    let resolveEventRead: ((value: typeof draft) => void) | undefined;
    let eventReadAborted = false;
    const queryFn = vi.fn(({ signal }: { signal: AbortSignal }) => {
      if (queryFn.mock.calls.length === 1) return Promise.resolve(draft);
      if (queryFn.mock.calls.length === 2) {
        return new Promise<typeof draft>((resolve) => {
          resolveEventRead = resolve;
          signal.addEventListener('abort', () => {
            eventReadAborted = true;
            resolve(draft);
          }, { once: true });
        });
      }

      return Promise.resolve(cancelled);
    });
    const observer = new QueryObserver(queryClient, { queryKey, queryFn });
    const unsubscribe = observer.subscribe(() => undefined);

    try {
      await observer.refetch();
      scheduleRealtimeChangeInvalidation(queryClient, { entityType: 'inspection', entityId: 'qc-coalesce' });
      await vi.advanceTimersByTimeAsync(100);
      expect(queryFn).toHaveBeenCalledTimes(2);

      const catchUp = catchUpRealtimeQueries(queryClient);
      expect(eventReadAborted).toBe(false);
      expect(queryFn).toHaveBeenCalledTimes(2);

      resolveEventRead?.(cancelled);
      await catchUp;

      expect(eventReadAborted).toBe(false);
      expect(queryFn).toHaveBeenCalledTimes(2);
      expect(queryClient.getQueryData(queryKey)).toEqual(cancelled);
    } finally {
      resolveEventRead?.(draft);
      unsubscribe();
      queryClient.clear();
      vi.useRealTimers();
    }
  });

  it('lets an initial active detail read finish, then refetches after catch-up', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, staleTime: Infinity, gcTime: Infinity } },
    });
    const queryKey = inspectionQueryKeys.detail('qc-initial-catch-up');
    const draft = { id: 'qc-initial-catch-up', status: 'draft' };
    const cancelled = { id: 'qc-initial-catch-up', status: 'cancelled' };
    queryClient.setQueryData(queryKey, draft);
    let resolveInitialRead: ((value: typeof draft) => void) | undefined;
    let initialReadSignal: AbortSignal | undefined;
    const queryFn = vi.fn(({ signal }: { signal: AbortSignal }) => {
      if (queryFn.mock.calls.length === 1) return Promise.resolve(draft);
      if (queryFn.mock.calls.length === 2) {
        initialReadSignal = signal;
        return new Promise<typeof draft>((resolve) => {
          resolveInitialRead = resolve;
        });
      }

      return Promise.resolve(cancelled);
    });
    const observer = new QueryObserver(queryClient, { queryKey, queryFn });
    const unsubscribe = observer.subscribe(() => undefined);

    try {
      await observer.refetch();
      const initialRead = observer.refetch();
      await Promise.resolve();
      expect(queryFn).toHaveBeenCalledTimes(2);

      const catchUp = catchUpRealtimeQueries(queryClient);
      await Promise.resolve();
      expect(initialReadSignal?.aborted).toBe(false);

      resolveInitialRead?.(draft);
      await initialRead;
      await catchUp;

      expect(queryFn).toHaveBeenCalledTimes(3);
      expect(queryClient.getQueryData(queryKey)).toEqual(cancelled);
    } finally {
      resolveInitialRead?.(draft);
      unsubscribe();
      queryClient.clear();
    }
  });

  it('lets a record detail refresh proceed while an unrelated badge request is still pending', async () => {
    vi.useFakeTimers();
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, staleTime: Infinity, gcTime: Infinity } },
    });
    const badgeKey = ['sidebar', 'badges'] as const;
    const detailKey = ['purchasing', 'purchase-requests', 'pr-1'] as const;
    queryClient.setQueryData(badgeKey, { count: 1 });
    queryClient.setQueryData(detailKey, { id: 'pr-1', status: 'pending' });
    const badgeQuery = vi.fn(() => new Promise<{ count: number }>(() => undefined));
    const detailQuery = vi.fn().mockResolvedValue({ id: 'pr-1', status: 'approved' });
    const badgeObserver = new QueryObserver(queryClient, { queryKey: badgeKey, queryFn: badgeQuery });
    const detailObserver = new QueryObserver(queryClient, { queryKey: detailKey, queryFn: detailQuery });
    const unsubscribeBadge = badgeObserver.subscribe(() => undefined);
    const unsubscribeDetail = detailObserver.subscribe(() => undefined);

    try {
      scheduleRealtimeSignalInvalidation(queryClient, 'badges_changed');
      await vi.advanceTimersByTimeAsync(300);
      expect(badgeQuery).toHaveBeenCalledTimes(1);

      scheduleRealtimeChangeInvalidation(queryClient, { entityType: 'purchase_request', entityId: 'pr-1' });
      await vi.advanceTimersByTimeAsync(100);
      expect(detailQuery).toHaveBeenCalledTimes(1);
      await vi.advanceTimersByTimeAsync(0);
      expect(queryClient.getQueryData(detailKey)).toEqual({ id: 'pr-1', status: 'approved' });
    } finally {
      unsubscribeBadge();
      unsubscribeDetail();
      queryClient.clear();
      vi.useRealTimers();
    }
  });

  it('starts live record detail refreshes before near-live badge refreshes', async () => {
    vi.useFakeTimers();
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, staleTime: Infinity, gcTime: Infinity } },
    });
    const badgeKey = ['sidebar', 'badges'] as const;
    const detailKey = ['purchasing', 'purchase-requests', 'pr-priority'] as const;
    queryClient.setQueryData(badgeKey, { purchase_requests: { count: 1 } });
    queryClient.setQueryData(detailKey, { id: 'pr-priority', status: 'pending' });
    const started: string[] = [];
    const badgeQuery = vi.fn(async () => {
      started.push('badges');
      return { purchase_requests: { count: 0 } };
    });
    const detailQuery = vi.fn(async () => {
      started.push('detail');
      return { id: 'pr-priority', status: 'approved' };
    });
    const badgeObserver = new QueryObserver(queryClient, { queryKey: badgeKey, queryFn: badgeQuery });
    const detailObserver = new QueryObserver(queryClient, { queryKey: detailKey, queryFn: detailQuery });
    const unsubscribeBadge = badgeObserver.subscribe(() => undefined);
    const unsubscribeDetail = detailObserver.subscribe(() => undefined);

    try {
      scheduleRealtimeSignalInvalidation(queryClient, 'badges_changed');
      scheduleRealtimeChangeInvalidation(queryClient, {
        entityType: 'purchase_request',
        entityId: 'pr-priority',
      });

      await vi.advanceTimersByTimeAsync(100);
      expect(started).toEqual(['detail']);
      expect(queryClient.getQueryData(detailKey)).toEqual({ id: 'pr-priority', status: 'approved' });

      await vi.advanceTimersByTimeAsync(200);
      expect(started).toEqual(['detail', 'badges']);
      expect(queryClient.getQueryData(badgeKey)).toEqual({ purchase_requests: { count: 0 } });
    } finally {
      unsubscribeBadge();
      unsubscribeDetail();
      queryClient.clear();
      vi.useRealTimers();
    }
  });

  it('includes customer portal invoice views in the canonical invoice invalidation family', () => {
    expect(queryKeysForRealtimeList('invoice')).toContainEqual(['portal', 'customer']);
  });

  it('maps customer sales-order and delivery changes to the active portal detail and list keys', () => {
    const orderKeys = queryKeysForRealtimeChange({ entityType: 'sales_order', entityId: 'so-hash' });
    expect(orderKeys).toContainEqual(['portal', 'customer', 'orders']);
    expect(orderKeys).toContainEqual(['portal', 'customer', 'order']);
    expect(orderKeys).toContainEqual(['portal', 'customer', 'order-chain']);
    expect(orderKeys).toContainEqual(['order-agreements', 'internal', 'so-hash']);
    expect(orderKeys).toContainEqual(['order-agreements', 'customer', 'so-hash']);
    expect(orderKeys).toContainEqual(['sales-order-fulfillment', 'so-hash']);
    expect(orderKeys).toContainEqual(['mrp', 'runs', 'latest']);
    expect(orderKeys).toContainEqual(['mrp', 'runs', 'history']);
    expect(orderKeys).toContainEqual(['mrp', 'plans']);
    expect(orderKeys).toContainEqual(['return-cases', 'detail']);
    expect(queryKeysForRealtimeList('sales_order')).not.toContainEqual(['order-agreements', 'internal', 'so-hash']);

    expect(queryKeysForRealtimeChange({ entityType: 'invoice', entityId: 'invoice-hash' }))
      .toContainEqual(['crm', 'sales-orders']);

    const deliveryKeys = queryKeysForRealtimeChange({ entityType: 'delivery', entityId: 'delivery-hash' });
    expect(deliveryKeys).toContainEqual(['portal', 'customer', 'deliveries']);
    expect(deliveryKeys).toContainEqual(['portal', 'customer', 'delivery']);
    expect(deliveryKeys).toContainEqual(['portal', 'customer', 'receipt-group']);
    expect(deliveryKeys).toContainEqual(['portal', 'customer', 'order-chain']);
  });

  it('includes open portal detail queries in channel reconnect catch-up', async () => {
    vi.useFakeTimers();
    const invalidateQueries = vi.fn().mockResolvedValue(undefined);
    const queryClient = { invalidateQueries } as never;

    scheduleRealtimeEntityCatchUp(queryClient, ['sales_order', 'delivery']);
    await vi.advanceTimersByTimeAsync(100);

    expect(invalidateQueries).toHaveBeenCalledTimes(1);
    const prefixes = invalidateQueries.mock.calls[0][0].predicate;
    const matched = (key: unknown[]) => prefixes({ queryKey: key });
    expect(matched(['portal', 'customer', 'order', 'so-hash'])).toBe(true);
    expect(matched(['portal', 'customer', 'delivery', 'delivery-hash'])).toBe(true);
    expect(matched(['portal', 'customer', 'receipt-group', 'delivery-hash'])).toBe(true);

    vi.useRealTimers();
  });

  it('invalidates a record, its list, linked records and shared counts', async () => {
    const prefixes = queryKeysForRealtimeChange({
      entityType: 'purchase_request',
      entityId: 'pr-hash',
      related: [{ entityType: 'purchase_order', entityId: 'po-hash' }],
    });

    expect(prefixes).toContainEqual(['purchasing', 'purchase-requests', 'pr-hash']);
    expect(prefixes).toContainEqual(['purchasing', 'purchase-requests']);
    expect(prefixes).toContainEqual(['purchasing', 'purchase-orders', 'detail', 'po-hash']);
    expect(prefixes).toContainEqual(['purchasing', 'purchase-orders', 'list']);
    expect(prefixes).toContainEqual(['purchasing', 'chain']);
    expect(prefixes).toContainEqual(['sidebar', 'badges']);
  });

  it('does not invalidate the same related entity twice', async () => {
    const prefixes = queryKeysForRealtimeChange({
      entityType: 'purchase_request',
      entityId: 'pr-hash',
      related: [
        { entityType: 'purchase_order', entityId: 'po-hash' },
        { entityType: 'purchase_order', entityId: 'po-hash' },
      ],
    });

    const poDetails = prefixes.filter(
      (prefix) => JSON.stringify(prefix) === JSON.stringify(['purchasing', 'purchase-orders', 'detail', 'po-hash']),
    );
    expect(poDetails).toHaveLength(1);
  });

  it('coalesces adjacent related record and list signals into one query invalidation', async () => {
    vi.useFakeTimers();
    const invalidateQueries = vi.fn().mockResolvedValue(undefined);
    const queryClient = { invalidateQueries } as never;

    scheduleRealtimeChangeInvalidation(queryClient, { entityType: 'bom', entityId: 'bom-old' });
    scheduleRealtimeChangeInvalidation(queryClient, { entityType: 'bom', entityId: 'bom-new' });
    scheduleRealtimeListInvalidation(queryClient, 'bom');
    expect(invalidateQueries).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(100);
    expect(invalidateQueries).toHaveBeenCalledTimes(1);

    vi.useRealTimers();
  });

  it('coalesces imperative private-channel query families through the shared invalidator', async () => {
    vi.useFakeTimers();
    const invalidateQueries = vi.fn().mockResolvedValue(undefined);
    const queryClient = { invalidateQueries } as never;

    scheduleRealtimeQueryInvalidation(queryClient, [['notifications']]);
    scheduleRealtimeQueryInvalidation(queryClient, [['notifications'], ['portal', 'pricing']]);
    expect(invalidateQueries).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(100);

    expect(invalidateQueries).toHaveBeenCalledTimes(1);
    const predicate = invalidateQueries.mock.calls[0][0].predicate;
    expect(predicate({ queryKey: ['notifications', { unread: true }] })).toBe(true);
    expect(predicate({ queryKey: ['portal', 'pricing', 'agreements'] })).toBe(true);
    expect(predicate({ queryKey: ['portal', 'customer', 'catalog'] })).toBe(false);

    vi.useRealTimers();
  });

  it('invalidates work-order crew queries when the parent work order changes', () => {
    const prefixes = queryKeysForRealtimeChange({ entityType: 'work_order', entityId: 'wo-1' });

    expect(prefixes).toContainEqual(['production', 'work-orders', 'detail', 'wo-1']);
    expect(prefixes).toContainEqual(['production', 'crew']);
    expect(prefixes).toContainEqual(['production', 'work-orders']);
    expect(prefixes).toContainEqual(['production', 'work-orders', 'outputs']);
    expect(prefixes).toContainEqual(['factory', 'active-orders']);
    expect(prefixes).toContainEqual(['sidebar', 'badges']);
  });

  it('invalidates BOM detail, lists, product summaries, planning and production views', () => {
    const prefixes = queryKeysForRealtimeChange({ entityType: 'bom', entityId: 'bom-1' });

    expect(prefixes).toContainEqual(['mrp', 'boms', 'detail', 'bom-1']);
    expect(prefixes).toContainEqual(['mrp', 'boms']);
    expect(prefixes).toContainEqual(['crm', 'products']);
    expect(prefixes).toContainEqual(['mrp', 'scheduler', 'snapshot']);
    expect(prefixes).toContainEqual(['production', 'work-orders']);
    expect(realtimeEventNameForEntity('bom')).toBe('.chain.refresh');
  });

  it('refreshes active stock, warehouse, linked production and dashboard queries after stock changes', async () => {
    const prefixes = queryKeysForRealtimeList('inventory_stock');
    expect(prefixes).toContainEqual(['inventory', 'stock-levels']);
    expect(prefixes).toContainEqual(['inventory', 'stock-movements']);
    expect(prefixes).toContainEqual(['inventory', 'reservations']);
    expect(prefixes).toContainEqual(['inventory', 'dashboard']);
    expect(prefixes).toContainEqual(['warehouse', 'transfers']);
    expect(prefixes).toContainEqual(['production', 'work-orders']);
    expect(prefixes).toContainEqual(['chain-bottlenecks']);
    expect(prefixes).toContainEqual(['sidebar', 'badges']);
  });

  it('refreshes RFQ detail, lists, linked procurement records and counts', () => {
    const prefixes = queryKeysForRealtimeChange({ entityType: 'request_for_quote', entityId: 'rfq-1' });
    expect(prefixes).toContainEqual(['purchasing', 'rfqs', 'rfq-1']);
    expect(prefixes).toContainEqual(['purchasing', 'rfqs']);
    expect(prefixes).toContainEqual(['purchasing', 'purchase-requests']);
    expect(prefixes).toContainEqual(['purchasing', 'purchase-orders', 'list']);
    expect(prefixes).toContainEqual(['sidebar', 'badges']);
  });

  it('refreshes supplier listings and approved-supplier options after catalog changes', () => {
    const listingPrefixes = queryKeysForRealtimeList('supplier_listing');
    expect(listingPrefixes).toContainEqual(['purchasing', 'supplier-listings']);
    expect(listingPrefixes).toContainEqual(['purchasing', 'approved-suppliers']);
    expect(listingPrefixes).toContainEqual(['sidebar', 'badges']);
    expect(listingPrefixes).toContainEqual(['approvals']);

    const approvedSupplierPrefixes = queryKeysForRealtimeList('approved_supplier');
    expect(approvedSupplierPrefixes).toContainEqual(['purchasing', 'approved-suppliers']);
  });

  it('refreshes inspection details, filtered queues, NCRs, badges and dashboards', () => {
    const recordPrefixes = queryKeysForRealtimeChange({ entityType: 'inspection', entityId: 'qc-1' });
    const listPrefixes = queryKeysForRealtimeList('inspection');

    expect(recordPrefixes).toContainEqual(['quality', 'inspections', 'detail', 'qc-1']);
    expect(recordPrefixes).toContainEqual(['quality', 'inspections', 'list']);
    expect(recordPrefixes).not.toContainEqual(['quality', 'inspections']);
    expect(recordPrefixes).toContainEqual(['quality', 'ncrs']);
    expect(recordPrefixes).toContainEqual(['sidebar', 'badges']);
    expect(recordPrefixes).toContainEqual(['approvals']);
    expect(listPrefixes).toContainEqual(['quality', 'inspections', 'list']);
    expect(realtimeEventNameForEntity('inspection')).toBe('.inspection.refresh');
  });

  it('refreshes NCR details, lists, CAPA effectiveness, stock holds and counts', () => {
    const recordPrefixes = queryKeysForRealtimeChange({ entityType: 'ncr', entityId: 'ncr-1' });
    const listPrefixes = queryKeysForRealtimeList('ncr');

    expect(recordPrefixes).toContainEqual(['quality', 'ncrs', 'ncr-1']);
    expect(recordPrefixes).toContainEqual(['quality', 'ncrs']);
    expect(recordPrefixes).toContainEqual(['quality', 'ncr-effectiveness']);
    expect(recordPrefixes).toContainEqual(['inventory', 'mrb']);
    expect(recordPrefixes).toContainEqual(['inventory', 'stock-levels']);
    expect(recordPrefixes).toContainEqual(['sidebar', 'badges']);
    expect(recordPrefixes).toContainEqual(['approvals']);
    expect(listPrefixes).toContainEqual(['quality', 'ncrs']);
    expect(realtimeEventNameForEntity('ncr')).toBe('.ncr.refresh');
  });

  it('refreshes return-case detail, portal/internal lists, linked records, badges and inbox', () => {
    const recordPrefixes = queryKeysForRealtimeChange({ entityType: 'return_case', entityId: 'case-1' });
    const listPrefixes = queryKeysForRealtimeList('return_case');

    expect(recordPrefixes).toContainEqual(['return-cases', 'detail', 'case-1']);
    expect(recordPrefixes).toContainEqual(['return-cases', 'list']);
    expect(recordPrefixes).toContainEqual(['return-requests']);
    expect(recordPrefixes).toContainEqual(['inventory', 'grn']);
    expect(recordPrefixes).toContainEqual(['supply-chain', 'deliveries']);
    expect(recordPrefixes).toContainEqual(['sidebar', 'badges']);
    expect(recordPrefixes).toContainEqual(['approvals']);
    expect(listPrefixes).toContainEqual(['return-cases', 'list']);
    expect(listPrefixes).toContainEqual(['return-cases', 'sources']);
    expect(listPrefixes).toContainEqual(['return-cases', 'source-options']);
    expect(realtimeEventNameForEntity('return_case')).toBe('.return.case.refresh');
  });

  it('refreshes return-case source searches and quantity previews when their source data changes', () => {
    const sourceKey = returnCaseQueryKeys.sources('customer', 'customer:acct-1', 'customer', 'delivery');
    const sourceOptionsKey = returnCaseQueryKeys.sourceOptions('customer', 'customer:acct-1', 'delivery', 'delivery-1');

    expect(queryKeysForRealtimeList('return_case')).toContainEqual(['return-cases', 'source-options']);
    expect(queryKeysForRealtimeList('return_request')).toContainEqual(['return-cases', 'source-options']);
    expect(queryKeysForRealtimeList('delivery')).toContainEqual(['return-cases', 'sources']);
    expect(queryKeysForRealtimeList('grn')).toContainEqual(['return-cases', 'sources']);
    expect(queryKeysForRealtimeList('purchase_order')).toContainEqual(['return-cases', 'sources']);
    expect(queryKeysForRealtimeList('inventory_stock')).toContainEqual(['return-cases', 'source-options']);
    expect(isRealtimeManagedQueryKey(sourceKey)).toBe(true);
    expect(isRealtimeManagedQueryKey(sourceOptionsKey)).toBe(true);
  });

  it('refreshes clearance detail, scoped lists, employee/payroll views and counts', () => {
    const recordPrefixes = queryKeysForRealtimeChange({ entityType: 'clearance', entityId: 'clearance-1' });
    const listPrefixes = queryKeysForRealtimeList('clearance');

    expect(recordPrefixes).toContainEqual(['clearance', 'clearance-1']);
    expect(recordPrefixes).toContainEqual(['hr', 'separations']);
    expect(recordPrefixes).toContainEqual(['hr', 'employees']);
    expect(recordPrefixes).toContainEqual(['payrolls']);
    expect(recordPrefixes).toContainEqual(['sidebar', 'badges']);
    expect(listPrefixes).toContainEqual(['hr', 'separations']);
    expect(realtimeEventNameForEntity('clearance')).toBe('.hr.clearance.changed');
  });

  it('refreshes payroll period detail, filtered lists, run rows and finance summaries', () => {
    const recordPrefixes = queryKeysForRealtimeChange({ entityType: 'payroll_period', entityId: 'period-1' });
    const listPrefixes = queryKeysForRealtimeList('payroll_period');

    expect(recordPrefixes).toContainEqual(['payroll-period', 'period-1']);
    expect(recordPrefixes).toContainEqual(['payroll-periods']);
    expect(recordPrefixes).toContainEqual(['payrolls']);
    expect(recordPrefixes).toContainEqual(['payroll-period-variance']);
    expect(recordPrefixes).toContainEqual(['sidebar', 'badges']);
    expect(recordPrefixes).toContainEqual(['dashboard']);
    expect(listPrefixes).toContainEqual(['payroll-periods']);
    expect(realtimeEventNameForEntity('payroll_period')).toBe('.payroll.period.changed');
  });

  it('refreshes payroll adjustment detail, filtered queues, parent period and approval summaries', () => {
    const recordPrefixes = queryKeysForRealtimeChange({
      entityType: 'payroll_adjustment',
      entityId: 'adjustment-1',
      related: [{ entityType: 'payroll_period', entityId: 'period-1' }],
    });
    const listPrefixes = queryKeysForRealtimeList('payroll_adjustment');

    expect(recordPrefixes).toContainEqual(['payroll-adjustment', 'adjustment-1']);
    expect(recordPrefixes).toContainEqual(['payroll-adjustments']);
    expect(recordPrefixes).toContainEqual(['payroll-period', 'period-1']);
    expect(recordPrefixes).toContainEqual(['approvals']);
    expect(listPrefixes).toContainEqual(['payroll-adjustments']);
    expect(realtimeEventNameForEntity('payroll_adjustment')).toBe('.payroll.adjustment.changed');
  });

  it('refreshes maintenance work-order details, lists, assets, downtime and dashboard queries', () => {
    const recordPrefixes = queryKeysForRealtimeChange({ entityType: 'maintenance_work_order', entityId: 'mwo-1' });
    const listPrefixes = queryKeysForRealtimeList('maintenance_work_order');

    expect(recordPrefixes).toContainEqual(['maintenance', 'work-order', 'mwo-1']);
    expect(recordPrefixes).toContainEqual(['maintenance', 'work-orders']);
    expect(recordPrefixes).toContainEqual(['maintenance', 'downtime-analytics']);
    expect(recordPrefixes).toContainEqual(['mrp', 'machines']);
    expect(recordPrefixes).toContainEqual(['sidebar', 'badges']);
    expect(listPrefixes).toContainEqual(['maintenance', 'work-orders']);
    expect(realtimeEventNameForEntity('maintenance_work_order')).toBe('.maintenance.work-order.changed');
  });

  it('refreshes maintenance schedule detail, lists, and dashboard queries', () => {
    const recordPrefixes = queryKeysForRealtimeChange({ entityType: 'maintenance_schedule', entityId: 'schedule-1' });
    const listPrefixes = queryKeysForRealtimeList('maintenance_schedule');

    expect(recordPrefixes).toContainEqual(['maintenance', 'schedules', 'detail', 'schedule-1']);
    expect(recordPrefixes).toContainEqual(['maintenance', 'schedules', 'list']);
    expect(recordPrefixes).toContainEqual(['dashboard', 'widget-data']);
    expect(recordPrefixes).toContainEqual(['kpi']);
    expect(listPrefixes).toContainEqual(['maintenance', 'schedules', 'list']);
    expect(realtimeEventNameForEntity('maintenance_schedule')).toBe('.maintenance.schedule.changed');
  });

  it('refreshes production schedule snapshots, work orders, and dashboards', () => {
    const recordPrefixes = queryKeysForRealtimeChange({ entityType: 'production_schedule', entityId: 'schedule-1' });
    const listPrefixes = queryKeysForRealtimeList('production_schedule');

    expect(recordPrefixes).toContainEqual(['mrp', 'scheduler', 'snapshot', 'schedule-1']);
    expect(recordPrefixes).toContainEqual(['mrp', 'scheduler', 'snapshot']);
    expect(recordPrefixes).toContainEqual(['production', 'work-orders']);
    expect(recordPrefixes).toContainEqual(['production', 'dashboard']);
    expect(listPrefixes).toContainEqual(['mrp', 'scheduler', 'snapshot']);
  });

  it('refreshes machine detail, machine lists, scheduler dependencies and dashboards', () => {
    const recordPrefixes = queryKeysForRealtimeChange({ entityType: 'machine', entityId: 'machine-1' });
    const listPrefixes = queryKeysForRealtimeList('machine');

    expect(recordPrefixes).toContainEqual(['mrp', 'machines', 'detail', 'machine-1']);
    expect(recordPrefixes).toContainEqual(['mrp', 'machines', 'list']);
    expect(recordPrefixes).toContainEqual(['mrp', 'scheduler', 'snapshot']);
    expect(recordPrefixes).toContainEqual(['production', 'dashboard']);
    expect(listPrefixes).toContainEqual(['mrp', 'machines', 'list']);
    expect(realtimeEventNameForEntity('machine')).toBe('.chain.refresh');
  });

  it('refreshes mold detail, filtered lists, history, compatible machines and production dependencies', () => {
    const recordPrefixes = queryKeysForRealtimeChange({ entityType: 'mold', entityId: 'mold-1' });
    const listPrefixes = queryKeysForRealtimeList('mold');

    expect(recordPrefixes).toContainEqual(['mrp', 'molds', 'detail', 'mold-1']);
    expect(recordPrefixes).toContainEqual(['mrp', 'molds', 'list']);
    expect(recordPrefixes).toContainEqual(['mrp', 'molds', 'history']);
    expect(recordPrefixes).toContainEqual(['mrp', 'machines']);
    expect(recordPrefixes).toContainEqual(['production', 'work-orders']);
    expect(recordPrefixes).toContainEqual(['sidebar', 'badges']);
    expect(listPrefixes).toContainEqual(['mrp', 'molds', 'list']);
    expect(realtimeEventNameForEntity('mold')).toBe('.chain.refresh');
  });

  it('invalidates each PO data query once and leaves reference options fresh', async () => {
    const queries = [
      { queryKey: ['purchasing', 'purchase-orders', 'detail', 'po-1'] },
      { queryKey: ['purchasing', 'purchase-orders', 'list', { page: 1 }] },
      { queryKey: ['purchasing', 'purchase-orders', 'options'] },
      { queryKey: ['purchasing', 'purchase-orders', 'billable'] },
      { queryKey: ['sidebar', 'badges'] },
      { queryKey: ['mrp', 'plans'] },
      { queryKey: ['custom', 'linked-record', 'po-1'] },
    ];
    const invalidated: unknown[][] = [];
    const invalidateQueries = vi.fn(async (filters: { predicate?: (query: { queryKey: unknown[] }) => boolean }) => {
      if (filters.predicate) {
        for (const query of queries) {
          if (filters.predicate(query)) invalidated.push(query.queryKey);
        }
      }
    });

    await invalidateRealtimeChange({ invalidateQueries } as never, {
      entityType: 'purchase_order',
      entityId: 'po-1',
    }, [['custom', 'linked-record', 'po-1']]);

    expect(invalidateQueries).toHaveBeenCalledTimes(1);
    expect(invalidated).toContainEqual(['purchasing', 'purchase-orders', 'detail', 'po-1']);
    expect(invalidated).toContainEqual(['purchasing', 'purchase-orders', 'list', { page: 1 }]);
    expect(invalidated).toContainEqual(['mrp', 'plans']);
    expect(invalidated).not.toContainEqual(['purchasing', 'purchase-orders', 'options']);
    expect(invalidated).not.toContainEqual(['purchasing', 'purchase-orders', 'billable']);
    expect(invalidated).toContainEqual(['custom', 'linked-record', 'po-1']);
    expect(queryKeysForRealtimeChange({ entityType: 'bill', entityId: 'bill-1' }))
      .toContainEqual(['purchasing', 'purchase-orders', 'detail']);
    expect(queryKeysForRealtimeChange({ entityType: 'bill', entityId: 'bill-1' }))
      .toContainEqual(['inventory', 'grn', 'detail']);
  });

  it('preserves an actor layout snapshot while refreshing widget data after save', async () => {
    const layoutKey = ['dashboard', 'layout', 'user:owner', { rich: true }];
    const queries = [
      { queryKey: layoutKey },
      { queryKey: ['dashboard', 'layout', 'user:owner', { rich: false }] },
      { queryKey: ['dashboard', 'widget-data', 'user:owner', ['orders']] },
    ];
    const invalidated: unknown[][] = [];
    const invalidateQueries = vi.fn(async (filters: { predicate?: (query: { queryKey: unknown[] }) => boolean }) => {
      if (filters.predicate) {
        for (const query of queries) {
          if (filters.predicate(query)) invalidated.push(query.queryKey);
        }
      }
    });

    await invalidateRealtimeSignal(
      { invalidateQueries } as never,
      'dashboard_layout_changed',
      [],
      [layoutKey],
    );

    expect(invalidated).not.toContainEqual(layoutKey);
    expect(invalidated).toContainEqual(['dashboard', 'layout', 'user:owner', { rich: false }]);
    expect(invalidated).toContainEqual(['dashboard', 'widget-data', 'user:owner', ['orders']]);
  });

  it('invalidates calibration and PPAP records, lists, and related counters', () => {
    const calibration = queryKeysForRealtimeChange({ entityType: 'calibration', entityId: 'cal-1' });
    expect(calibration).toContainEqual(['quality', 'calibration', 'detail', 'cal-1']);
    expect(calibration).toContainEqual(['quality', 'calibration']);
    expect(calibration).toContainEqual(['sidebar', 'badges']);
    expect(realtimeEventNameForEntity('calibration')).toBe('.quality.record.refresh');

    const ppap = queryKeysForRealtimeChange({ entityType: 'ppap', entityId: 'ppap-1' });
    expect(ppap).toContainEqual(['quality', 'ppap', 'ppap-1']);
    expect(ppap).toContainEqual(['quality', 'ppap']);
    expect(ppap).toContainEqual(['purchasing', 'purchase-orders']);
    expect(ppap).toContainEqual(['approvals']);
    expect(realtimeEventNameForEntity('ppap')).toBe('.quality.record.refresh');
  });

  it('invalidates the spec editor, product-backed spec queries, and spec list on revision changes', () => {
    const keys = queryKeysForRealtimeChange({ entityType: 'inspection_spec', entityId: 'spec-1' });
    expect(keys).toContainEqual(['quality', 'inspection-specs', 'show', 'spec-1']);
    expect(keys).toContainEqual(['quality', 'inspection-specs']);
    expect(keys).toContainEqual(['crm', 'products']);
    expect(realtimeEventNameForEntity('inspection_spec')).toBe('.quality.record.refresh');
  });

  it('refreshes grouped final-inspection pages when any inspection batch changes', () => {
    expect(queryKeysForRealtimeList('inspection')).toContainEqual(['quality', 'work-order-final']);
  });

  it('refreshes the NCR template catalog and the active-template picker', () => {
    const keys = queryKeysForRealtimeList('ncr_template');
    expect(keys).toContainEqual(['quality', 'ncr-templates', 'list']);
    expect(keys).toContainEqual(['quality', 'ncr-templates', 'active']);
    expect(keys).not.toContainEqual(['quality', 'ncr-templates', 'options']);
    expect(realtimeEventNameForEntity('ncr_template')).toBe('.quality.record.refresh');
  });

  it('refreshes the item quality-plan history and its item summary', () => {
    const keys = queryKeysForRealtimeChange({ entityType: 'quality_plan', entityId: 'item-1' });
    expect(keys).toContainEqual(['inventory', 'quality-plans', 'item-1']);
    expect(keys).toContainEqual(['inventory', 'quality-plans']);
    expect(keys).toContainEqual(['inventory', 'items']);
    expect(realtimeEventNameForEntity('quality_plan')).toBe('.quality.record.refresh');
  });

  it('refreshes MRP plan detail, lists, sales-order chains, and planning dashboards', () => {
    const keys = queryKeysForRealtimeChange({ entityType: 'mrp_plan', entityId: 'plan-1' });
    expect(keys).toContainEqual(['mrp', 'plans', 'detail', 'plan-1']);
    expect(keys).toContainEqual(['mrp', 'plans']);
    expect(keys).toContainEqual(['crm', 'sales-orders']);
    expect(keys).toContainEqual(['production', 'work-orders']);
    expect(keys).toContainEqual(['approvals']);
    expect(realtimeEventNameForEntity('mrp_plan')).toBe('.chain.refresh');
  });

  it('refreshes latest and historical MRP runs plus generated plans', () => {
    const keys = queryKeysForRealtimeList('mrp_run');
    expect(keys).toContainEqual(['mrp', 'runs']);
    expect(keys).toContainEqual(['mrp', 'plans']);
    expect(keys).toContainEqual(['dashboard', 'widget-data']);
    expect(realtimeEventNameForEntity('mrp_run')).toBe('.chain.refresh');
  });

  it('refreshes return request detail, lists, approval counts, and related chains', () => {
    const keys = queryKeysForRealtimeChange({ entityType: 'return_request', entityId: 'rma-1' });
    expect(keys).toContainEqual(['return-request', 'rma-1']);
    expect(keys).toContainEqual(['return-requests']);
    expect(keys).toContainEqual(['approvals']);
    expect(keys).toContainEqual(['quality', 'inspections']);
    expect(realtimeEventNameForEntity('return_request')).toBe('.return.request.refresh');
  });

  it('invalidates the GRN query keys used by the list and detail screens', () => {
    const recordKeys = queryKeysForRealtimeChange({ entityType: 'grn', entityId: 'grn-1' });
    const listKeys = queryKeysForRealtimeList('grn');
    const purchaseOrderKeys = queryKeysForRealtimeList('purchase_order');

    expect(recordKeys).toContainEqual(['inventory', 'grn', 'detail', 'grn-1']);
    expect(listKeys).toContainEqual(['inventory', 'grn', 'list']);
    expect(listKeys).toContainEqual(['inventory', 'grn', 'receivable-pos']);
    expect(listKeys).not.toContainEqual(['inventory']);
    expect(listKeys).not.toContainEqual(['inventory', 'grn']);
    expect(purchaseOrderKeys).toContainEqual(['inventory', 'grn', 'detail']);
    expect(purchaseOrderKeys).toContainEqual(['inventory', 'grn', 'list']);
  });

  it('invalidates active traceability searches when a shipment lot changes', () => {
    const keys = queryKeysForRealtimeList('shipment_lot');
    expect(keys).toContainEqual(['quality', 'traceability']);
    expect(keys).toContainEqual(['supply-chain', 'deliveries']);
    expect(realtimeEventNameForEntity('shipment_lot')).toBe('.shipment.lot.refresh');
  });

  it('routes inbound shipment changes to shipment, container, and dependent query families', () => {
    const recordKeys = queryKeysForRealtimeChange({ entityType: 'shipment', entityId: 'shipment-1' });
    const listKeys = queryKeysForRealtimeList('shipment');

    expect(realtimeEventNameForEntity('shipment')).toBe('.supply-chain.shipment.changed');
    expect(recordKeys).toContainEqual(['supply-chain', 'shipments', 'shipment-1']);
    expect(recordKeys).toContainEqual(['supply-chain', 'shipments', 'shipment-1', 'containers']);
    expect(listKeys).toContainEqual(['supply-chain', 'shipments']);
    expect(listKeys).toContainEqual(['sidebar', 'badges']);
    expect(listKeys).toContainEqual(['dashboard', 'widget-data']);
  });

  it('routes vehicle changes to fleet, delivery assignment, badge, and logistics queries', () => {
    const recordKeys = queryKeysForRealtimeChange({ entityType: 'vehicle', entityId: 'vehicle-1' });
    const listKeys = queryKeysForRealtimeList('vehicle');

    expect(realtimeEventNameForEntity('vehicle')).toBe('.supply-chain.vehicle.changed');
    expect(recordKeys).toContainEqual(['supply-chain', 'vehicles', 'vehicle-1']);
    expect(listKeys).toContainEqual(['supply-chain', 'vehicles']);
    expect(listKeys).toContainEqual(['supply-chain', 'deliveries']);
    expect(listKeys).toContainEqual(['sidebar', 'badges']);
    expect(listKeys).toContainEqual(['dashboard', 'logistics']);
  });

  it('uses one query-cache traversal for all active list invalidations', async () => {
    const queries = [
      { queryKey: ['inventory', 'stock-levels', { page: 1 }] },
      { queryKey: ['inventory', 'stock-movements'] },
      { queryKey: ['warehouse', 'transfers'] },
      { queryKey: ['inventory', 'uoms'] },
    ];
    const invalidated: unknown[][] = [];
    const invalidateQueries = vi.fn(async (filters: { predicate?: (query: { queryKey: unknown[] }) => boolean }) => {
      if (filters.predicate) {
        for (const query of queries) {
          if (filters.predicate(query)) invalidated.push(query.queryKey);
        }
      }
    });

    await invalidateRealtimeList({ invalidateQueries } as never, 'inventory_stock');

    expect(invalidateQueries).toHaveBeenCalledTimes(1);
    expect(invalidated).toContainEqual(['inventory', 'stock-levels', { page: 1 }]);
    expect(invalidated).toContainEqual(['inventory', 'stock-movements']);
    expect(invalidated).toContainEqual(['warehouse', 'transfers']);
    expect(invalidated).not.toContainEqual(['inventory', 'uoms']);
  });
});
