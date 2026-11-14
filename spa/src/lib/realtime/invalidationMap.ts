import { partialMatchKey, type QueryClient, type QueryKey } from '@tanstack/react-query';
import type { ChainEntityType } from '@/types/chain';
import { purchaseOrderQueryKeys } from '@/lib/queryKeys/purchasing';
import { setRealtimeLastUpdatedAt } from './status';
import { isClientHttpError } from '@/lib/httpErrorPolicy';

export type RealtimeEntityType = ChainEntityType
  | 'kpi_snapshot'
  | 'admin_user'
  | 'import_batch'
  | 'role'
  | 'purchase_request'
  | 'request_for_quote'
  | 'inventory_stock'
  | 'stock_adjustment'
  | 'item_cost_review'
  | 'material_review_record'
  | 'material_issue_slip'
  | 'transfer_order'
  | 'item'
  | 'stock_count_session'
  | 'supplier_listing'
  | 'approved_supplier'
  | 'inspection'
  | 'ncr'
  | 'calibration'
  | 'ppap'
  | 'inspection_spec'
  | 'ncr_template'
  | 'quality_plan'
  | 'return_request'
  | 'return_case'
  | 'shipment'
  | 'vehicle'
  | 'shipment_lot'
  | 'attendance'
  | 'shift'
  | 'employee_shift_assignment'
  | 'employee_document'
  | 'employee_skill'
  | 'employee_onboarding'
  | 'skill'
  | 'profile_update_request'
  | 'job_posting'
  | 'job_application'
  | 'overtime_request'
  | 'maintenance_work_order'
  | 'maintenance_schedule'
  | 'production_schedule'
  | 'product_routing'
  | 'machine'
  | 'mold'
  | 'bom'
  | 'mrp_plan'
  | 'mrp_run'
  | 'product'
  | 'price_agreement'
  | 'price_request'
  | 'payroll_period'
  | 'payroll_adjustment'
  | 'payroll_anomaly'
  | 'clearance'
  | 'department'
  | 'position'
  | 'employee_training'
  | 'employee'
  | 'employee_loan'
  | 'salary_adjustment'
  | 'training'
  | 'asset'
  | 'leave_request'
  | 'leave_type'
  | 'leave_balance'
  | 'holiday'
  | 'government_table'
  | 'payroll_de_minimis'
  | 'account'
  | 'accounting_period'
  | 'budget_transfer'
  | 'budget'
  | 'credit_note'
  | 'vendor'
  | 'supplier_performance'
  | 'journal_entry'
  | 'scheduled_export'
  | 'late_production_report'
  | 'customer_complaint'
  | 'delivery_schedule'
  | 'demand_forecast'
  | 'customer';

export interface RelatedRealtimeEntity {
  entityType: RealtimeEntityType;
  entityId: string;
}

export interface RealtimeChangeRef extends RelatedRealtimeEntity {
  related?: RelatedRealtimeEntity[];
}

export type RealtimeSignal = 'admin_activity_changed' | 'admin_audit_logs_changed' | 'admin_backups_changed' | 'admin_dashboard_changed' | 'admin_sessions_changed' | 'approval_delegations_changed' | 'chain_bottlenecks_changed' | 'chain_listener_runs_changed' | 'dashboard_layout_changed' | 'dashboard_widget_data_changed' | 'hr_dashboard_changed' | 'employee_dashboard_changed' | 'attendance_changed' | 'badges_changed' | 'customer_catalog_changed' | 'customer_returns_changed' | 'customer_delivery_schedules_changed' | 'portal_access_changed' | 'kpi_snapshot_changed' | 'user_invites_changed' | 'contact_inquiries_changed' | 'notification_preferences_changed' | 'production_dashboard_changed' | 'plant_manager_dashboard_changed' | 'ppc_dashboard_changed' | 'finance_dashboard_changed' | 'purchasing_dashboard_changed' | 'quality_dashboard_changed' | 'settings_changed' | 'dss_changed' | 'supplier_purchase_orders_changed' | 'supplier_receipts_changed' | 'supplier_bills_changed' | 'supplier_delivery_schedules_changed' | 'supplier_rfqs_changed' | 'warehouse_layout_changed' | 'warehouse_stock_changed' | 'warehouse_dashboard_changed';

export type RealtimeCacheGroup = 'dashboards' | 'financial-statements' | 'procurement-chain';

/** Search groups whose mutable record summaries can appear in the command palette. */
const GLOBAL_SEARCH_ENTITY_TYPES = new Set<RealtimeEntityType>([
  'employee',
  'sales_order',
  'purchase_order',
  'request_for_quote',
  'work_order',
  'invoice',
  'bill',
  'product',
  'item',
  'customer',
  'vendor',
  'ncr',
  'budget',
  'budget_transfer',
]);
const COMMAND_PALETTE_SEARCH_PREFIX: QueryKey = ['command-palette', 'search'];
const RETURN_CASE_SOURCE_LIST_PREFIXES: QueryKey[] = [['return-cases', 'sources']];
const RETURN_CASE_SOURCE_OPTION_PREFIXES: QueryKey[] = [['return-cases', 'source-options']];
const RETURN_CASE_SOURCE_REFRESH_PREFIXES: QueryKey[] = [
  ...RETURN_CASE_SOURCE_LIST_PREFIXES,
  ...RETURN_CASE_SOURCE_OPTION_PREFIXES,
];

/** Shared non-record signals and every active query family they refresh. */
export const realtimeSignalInvalidationMap: Record<RealtimeSignal, QueryKey[]> = {
  admin_activity_changed: [['admin', 'activity']],
  admin_audit_logs_changed: [['admin', 'audit-logs']],
  admin_backups_changed: [['admin', 'backups']],
  admin_dashboard_changed: [['dashboard', 'admin'], ['dashboard', 'widget-data']],
  admin_sessions_changed: [['admin', 'sessions']],
  approval_delegations_changed: [['approval-delegations'], ['chain-bottlenecks'], ['dashboard', 'widget-data']],
  chain_bottlenecks_changed: [['chain-bottlenecks']],
  chain_listener_runs_changed: [['chain', 'listener-runs'], ['chain-bottlenecks'], ['dashboard', 'widget-data']],
  dashboard_layout_changed: [['dashboard', 'layout'], ['dashboard', 'widget-data']],
  dashboard_widget_data_changed: [
    ['dashboard', 'widget-data'],
    ['inventory', 'dashboard'],
  ],
  hr_dashboard_changed: [['dashboard', 'hr'], ['dashboard', 'widget-data']],
  employee_dashboard_changed: [['self-service'], ['dashboard', 'employee'], ['dashboard', 'widget-data']],
  attendance_changed: [
    ['attendance'],
    ['dashboard', 'employee'],
    ['self-service', 'home'],
    ['self-service', 'dashboard'],
    ['self-service', 'dtr'],
    ['dashboard', 'widget-data'],
    ['dashboard', 'metric'],
  ],
  badges_changed: [['sidebar', 'badges'], ['alerts'], ['approvals'], ['action-center'], ['dashboard'], ['accounting', 'dashboard'], ['dashboards']],
  customer_catalog_changed: [
    ['portal', 'customer', 'catalog'],
    ['portal', 'customer', 'order-quote'],
    ['portal', 'catalog', 'pricing'],
  ],
  customer_returns_changed: [
    ['portal', 'customer', 'returns'],
    ['portal', 'customer', 'return'],
    ...RETURN_CASE_SOURCE_REFRESH_PREFIXES,
  ],
  customer_delivery_schedules_changed: [['portal', 'customer', 'delivery-schedules']],
  portal_access_changed: [['b2b', 'portal-access']],
  kpi_snapshot_changed: [['kpi'], ['dashboard', 'widget-data']],
  user_invites_changed: [['admin', 'invites']],
  contact_inquiries_changed: [['crm', 'inquiries']],
  notification_preferences_changed: [['notification-preferences']],
  supplier_delivery_schedules_changed: [['portal', 'supplier', 'delivery-schedules']],
  supplier_rfqs_changed: [['portal', 'supplier', 'rfqs']],
  production_dashboard_changed: [
    ['production', 'dashboard'],
    ['dashboard', 'plant-manager'],
    ['dashboard', 'ppc'],
    ['dashboard', 'widget-data'],
    ['kpi'],
    ['chain-bottlenecks'],
  ],
  plant_manager_dashboard_changed: [['dashboard', 'plant-manager'], ['dashboard', 'widget-data'], ['kpi']],
  ppc_dashboard_changed: [['dashboard', 'ppc'], ['dashboard', 'widget-data'], ['chain-bottlenecks']],
  finance_dashboard_changed: [['dashboard', 'finance'], ['dashboard', 'widget-data'], ['chain-bottlenecks']],
  purchasing_dashboard_changed: [['dashboard', 'purchasing'], ['dashboard', 'widget-data']],
  quality_dashboard_changed: [['dashboard', 'quality'], ['dashboard', 'widget-data'], ['kpi']],
  settings_changed: [
    ['admin', 'settings'],
    ['forecasting', 'settings'],
    ['landing', 'contact'],
    ['landing', 'content'],
    ['auth', 'password-policy'],
    ['business-policies'],
    ['portal', 'supplier', 'business-policies'],
    ['attendance', 'overtime', 'options'],
    ['inventory', 'stock-adjustments', 'options'],
    ['warehouse', 'stock-count-options'],
    // SettingsChanged intentionally carries no setting names. Refresh every
    // active API options query whose response includes a SettingsService value.
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
    ['sidebar', 'badges'],
    ['approvals'],
    ['action-center'],
    ['dashboard'],
    ['accounting', 'dashboard'],
    ['dashboards'],
  ],
  dss_changed: [['dss']],
  supplier_purchase_orders_changed: [
    ['portal', 'supplier', 'pos'],
    ['portal', 'supplier', 'po'],
    ['portal', 'supplier', 'dashboard'],
    ...RETURN_CASE_SOURCE_REFRESH_PREFIXES,
  ],
  supplier_receipts_changed: [
    ['portal', 'supplier', 'po'],
    ['portal', 'supplier', 'dashboard'],
    ...RETURN_CASE_SOURCE_REFRESH_PREFIXES,
  ],
  supplier_bills_changed: [
    ['portal', 'supplier', 'po'],
    ['portal', 'supplier', 'invoices'],
    ['portal', 'supplier', 'invoice'],
    ['portal', 'supplier', 'statement-of-account'],
    ['portal', 'supplier', 'dashboard'],
  ],
  warehouse_layout_changed: [
    ['inventory', 'warehouse', 'tree'],
    ['warehouse-tree'],
    ['warehouse', 'map'],
    ['warehouse', 'bin'],
    ['warehouse', 'stock-counts'],
    ['warehouse', 'stock-count'],
    ['inventory', 'dashboard'],
    ['inventory', 'stock-levels'],
    ['inventory', 'items'],
    ['inventory', 'transfer-orders'],
    ['inventory', 'transfer-order'],
    ['inventory', 'material-handovers'],
    ['inventory', 'picking'],
    ['warehouse', 'picking'],
    ['warehouse', 'transfers'],
    ['return-management'],
    ['dashboard', 'warehouse'],
    ['dashboard', 'widget-data'],
    ['dashboard', 'metric'],
    ['sidebar', 'badges'],
    ['dashboard'],
    ['dashboards'],
    ['production', 'work-orders'],
    ['maintenance', 'work-orders'],
    ['quality', 'ncrs'],
  ],
  warehouse_stock_changed: [['warehouse', 'map'], ['warehouse', 'bin']],
  warehouse_dashboard_changed: [
    ['dashboard', 'warehouse'],
    ['dashboard', 'widget-data'],
    ['dashboard', 'metric'],
    ['dashboard'],
    ['dashboards'],
  ],
};

/** Query families backed by each administratively rotatable cache generation. */
export const cacheGroupInvalidationMap: Record<RealtimeCacheGroup, QueryKey[]> = {
  dashboards: [
    ['dashboard'],
    ['dashboards'],
    ['accounting', 'dashboard'],
    ['sidebar', 'badges'],
    ['approvals'],
    ['action-center'],
    ['kpi'],
  ],
  'financial-statements': [
    ['accounting', 'statements'],
    ['accounting', 'dashboard'],
    ['dashboard', 'finance'],
    ['dashboard', 'widget-data'],
    ['dashboard', 'metric'],
  ],
  'procurement-chain': [
    ['purchasing', 'chain'],
    ['purchasing', 'purchase-requests'],
    ['purchasing', 'purchase-orders'],
    ['inventory', 'grn'],
    ['accounting', 'bills'],
    ['chain-bottlenecks'],
    ['sidebar', 'badges'],
    ['approvals'],
    ['dashboard'],
    ['dashboards'],
    ['accounting', 'dashboard'],
  ],
};

export function isRealtimeCacheGroup(group: string): group is RealtimeCacheGroup {
  return Object.hasOwn(cacheGroupInvalidationMap, group);
}

interface EntityInvalidationDefinition {
  detailPrefix: QueryKey;
  listPrefixes: QueryKey[];
  relatedPrefixes: QueryKey[];
  relatedChangePrefixes?: QueryKey[];
  relatedDetailKeys?: (entityId: string) => QueryKey[];
  countPrefixes: QueryKey[];
}

/**
 * One source of truth for the query families affected by each chain record.
 * Prefixes intentionally match hierarchical TanStack keys so filters/page
 * variants refresh together while unrelated reference-data queries stay warm.
 */
export const realtimeInvalidationMap: Record<RealtimeEntityType, EntityInvalidationDefinition> = {
  kpi_snapshot: {
    detailPrefix: ['kpi'],
    listPrefixes: [['kpi']],
    relatedPrefixes: [['dashboard', 'widget-data']],
    countPrefixes: [],
  },
  import_batch: {
    detailPrefix: ['imports', 'batches'],
    listPrefixes: [['imports', 'batches']],
    relatedPrefixes: [],
    countPrefixes: [],
  },
  admin_user: {
    detailPrefix: ['admin-user'],
    listPrefixes: [['admin-users']],
    relatedPrefixes: [['admin', 'users'], ['admin-user'], ['admin-employee-candidates']],
    countPrefixes: [['sidebar', 'badges'], ['dashboard', 'admin'], ['dashboard'], ['dashboards']],
  },
  role: {
    detailPrefix: ['admin', 'role'],
    listPrefixes: [['admin', 'roles']],
    relatedPrefixes: [['admin-user-options']],
    countPrefixes: [],
  },
  purchase_request: {
    detailPrefix: ['purchasing', 'purchase-requests'],
    listPrefixes: [['purchasing', 'purchase-requests'], ['purchasing', 'chain']],
    relatedPrefixes: [purchaseOrderQueryKeys.lists, ['accounting', 'bills']],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards'], ['approvals']],
  },
  request_for_quote: {
    detailPrefix: ['purchasing', 'rfqs'],
    listPrefixes: [['purchasing', 'rfqs']],
    relatedPrefixes: [['purchasing', 'purchase-requests'], purchaseOrderQueryKeys.lists],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards'], ['approvals']],
  },
  stock_adjustment: {
    detailPrefix: ['inventory', 'stock-adjustments'],
    listPrefixes: [['inventory', 'stock-adjustments']],
    relatedPrefixes: [['inventory', 'stock-levels'], ['inventory', 'stock-movements'], ['dashboard', 'widget-data']],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards'], ['approvals']],
  },
  item_cost_review: {
    detailPrefix: ['cost-reviews'],
    listPrefixes: [['cost-reviews']],
    relatedPrefixes: [],
    countPrefixes: [['sidebar', 'badges'], ['approvals']],
  },
  material_review_record: {
    detailPrefix: ['inventory', 'mrb'],
    listPrefixes: [['inventory', 'mrb']],
    relatedPrefixes: [
      ['inventory', 'stock-levels'],
      ['inventory', 'dashboard'],
      ['quality', 'ncrs'],
      ['quality', 'ncr-effectiveness'],
    ],
    countPrefixes: [['sidebar', 'badges']],
  },
  material_issue_slip: {
    detailPrefix: ['inventory', 'material-issues'],
    listPrefixes: [['inventory', 'material-issues']],
    relatedPrefixes: [
      ['inventory', 'stock-levels'],
      ['inventory', 'reservations'],
      ['inventory', 'dashboard'],
      ['production', 'work-orders'],
    ],
    countPrefixes: [],
  },
  sales_order: {
    detailPrefix: ['crm', 'sales-orders'],
    listPrefixes: [['crm', 'sales-orders'], ['chains']],
    relatedChangePrefixes: [['return-cases', 'detail']],
    relatedDetailKeys: (orderId) => [
      ['order-agreements', 'internal', orderId],
      ['order-agreements', 'customer', orderId],
      ['sales-order-fulfillment', orderId],
    ],
    relatedPrefixes: [
      ['mrp', 'runs', 'latest'],
      ['mrp', 'runs', 'history'],
      ['mrp', 'plans'],
      ['production', 'work-orders'],
      ['purchasing', 'purchase-requests'],
      ['supply-chain', 'deliveries'],
      ['portal', 'customer', 'orders'],
      ['portal', 'customer', 'order'],
      ['portal', 'customer', 'order-chain'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards']],
  },
  customer_complaint: {
    detailPrefix: ['crm', 'complaints'],
    listPrefixes: [['crm', 'complaints']],
    relatedPrefixes: [['chain-bottlenecks'], ['dashboard', 'quality'], ['dashboard', 'sales'], ['dashboards'], ['kpi']],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards']],
  },
  work_order: {
    detailPrefix: ['production', 'work-orders', 'detail'],
    listPrefixes: [['production', 'work-orders'], ['production', 'dashboard'], ['factory', 'active-orders']],
    relatedPrefixes: [
      ['inventory'], ['quality'], ['maintenance'], ['production', 'work-orders', 'outputs'],
      ['production', 'execution-readiness'], ['production', 'crew'], ['production', 'crew-options'],
      ['production', 'assignment-suggestion'], ['production', 'repair-suggestion'],
      ['production', 'reschedule-suggestion'], ['late-production-reports'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards']],
  },
  late_production_report: {
    detailPrefix: ['late-production-reports'],
    listPrefixes: [['late-production-reports']],
    relatedPrefixes: [['production', 'work-orders']],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards'], ['approvals']],
  },
  purchase_order: {
    detailPrefix: purchaseOrderQueryKeys.details,
    listPrefixes: [purchaseOrderQueryKeys.lists, ['purchasing', 'chain']],
    relatedPrefixes: [
      ['inventory', 'grn', 'detail'],
      ['inventory', 'grn', 'list'],
      ['accounting', 'bills'],
      ['supply-chain', 'deliveries'],
      ['mrp'],
      ...RETURN_CASE_SOURCE_REFRESH_PREFIXES,
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards'], ['approvals']],
  },
  delivery: {
    detailPrefix: ['supply-chain', 'deliveries'],
    listPrefixes: [['supply-chain', 'deliveries'], ['driver']],
    relatedPrefixes: [
      ['crm', 'sales-orders'],
      ['accounting', 'invoices'],
      ['portal', 'customer', 'orders'],
      ['portal', 'customer', 'order'],
      ['portal', 'customer', 'order-chain'],
      ['portal', 'customer', 'deliveries'],
      ['portal', 'customer', 'delivery'],
      ['portal', 'customer', 'receipt-group'],
      ['supply-chain', 'vehicles'],
      ...RETURN_CASE_SOURCE_REFRESH_PREFIXES,
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards']],
  },
  grn: {
    detailPrefix: ['inventory', 'grn', 'detail'],
    listPrefixes: [
      ['inventory', 'grn', 'list'],
      ['inventory', 'grn', 'receivable-pos'],
      ['purchasing', 'chain'],
    ],
    relatedPrefixes: [
      purchaseOrderQueryKeys.lists,
      ['accounting', 'bills'],
      ['inventory', 'items'],
      ['inventory', 'movements'],
      ['inventory', 'stock-card'],
      ['inventory', 'stock-levels'],
      ['inventory', 'stock-movements'],
      ['inventory', 'dashboard'],
      ['dashboard', 'widget-data'],
      ['chain-bottlenecks'],
      ...RETURN_CASE_SOURCE_REFRESH_PREFIXES,
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards'], ['approvals']],
  },
  bill: {
    detailPrefix: ['accounting', 'bills'],
    listPrefixes: [['accounting', 'bills'], ['purchasing', 'chain']],
    relatedPrefixes: [['accounting', 'journal-entries'], ['accounting', 'statements'], ['accounting', 'vendors'], ['purchasing', 'purchase-orders', 'detail'], ['inventory', 'grn', 'detail'], ['chain-bottlenecks']],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['accounting', 'dashboard'], ['dashboards'], ['approvals']],
  },
  vendor: {
    detailPrefix: ['accounting', 'vendors'],
    listPrefixes: [['accounting', 'vendors']],
    relatedPrefixes: [['accounting', 'bills'], ['purchasing', 'purchase-orders'], ['purchasing', 'chain'], ['dashboard', 'finance'], ['accounting', 'dashboard'], ...RETURN_CASE_SOURCE_REFRESH_PREFIXES],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards']],
  },
  scheduled_export: {
    detailPrefix: ['scheduled-exports', 'detail'],
    listPrefixes: [['scheduled-exports']],
    relatedPrefixes: [],
    countPrefixes: [],
  },
  supplier_performance: {
    detailPrefix: ['purchasing', 'supplier-performance'],
    listPrefixes: [['purchasing', 'supplier-performance']],
    relatedPrefixes: [['dashboard', 'purchasing'], ['dashboard', 'plant-manager']],
    countPrefixes: [['dashboard', 'purchasing'], ['dashboard'], ['dashboards']],
  },
  demand_forecast: {
    detailPrefix: ['forecasting', 'list'],
    listPrefixes: [['forecasting/list'], ['forecasting', 'demand']],
    relatedPrefixes: [
      ['forecasting/accuracy'],
      ['forecasting/accuracy/summary'],
      ['forecasting', 'accuracy', 'summary'],
      ['forecasting/accuracy/products'],
      ['forecasting', 'accuracy', 'products'],
      ['forecasting/stock-out'],
      ['forecasting', 'stock-out'],
      ['forecasting/mrp-projection'],
      ['forecasting', 'mrp-projection'],
      ['dashboard', 'purchasing'],
      ['dashboard', 'widget-data'],
    ],
    countPrefixes: [['dashboard'], ['dashboards']],
  },
  invoice: {
    detailPrefix: ['accounting', 'invoices'],
    listPrefixes: [['accounting', 'invoices']],
    relatedPrefixes: [['accounting', 'journal-entries'], ['accounting', 'customers'], ['crm', 'sales-orders'], ['portal', 'customer'], ['supply-chain', 'deliveries'], ['chain-bottlenecks']],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['accounting', 'dashboard'], ['dashboards'], ['approvals']],
  },
  inventory_stock: {
    detailPrefix: ['inventory', 'stock-levels'],
    listPrefixes: [
      ['inventory', 'stock-levels'],
      ['inventory', 'movements'],
      ['inventory', 'stock-movements'],
      ['inventory', 'dashboard'],
      ['inventory', 'items'],
      ['inventory', 'stock-adjustments'],
      ...RETURN_CASE_SOURCE_OPTION_PREFIXES,
      ['inventory', 'transfer-orders'],
      ['inventory', 'material-handovers'],
      ['inventory', 'picking'],
      ['warehouse', 'picking'],
      ['warehouse', 'transfers'],
      ['warehouse', 'map'],
      ['warehouse', 'bin'],
    ],
    relatedPrefixes: [
      ['inventory', 'reservations'],
      ['production', 'work-orders'],
      ['production', 'assignment-suggestion'],
      ['maintenance', 'work-orders'],
      ['quality', 'ncrs'],
      ...RETURN_CASE_SOURCE_OPTION_PREFIXES,
      ['dashboard', 'warehouse'],
      ['chain-bottlenecks'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards'], ['approvals']],
  },
  transfer_order: {
    detailPrefix: ['inventory', 'transfer-order'],
    listPrefixes: [['inventory', 'transfer-orders']],
    relatedPrefixes: [
      ['inventory', 'stock-levels'],
      ['inventory', 'dashboard'],
      ['warehouse', 'map'],
      ['warehouse', 'bin'],
      ['dashboard', 'warehouse'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards']],
  },
  item: {
    detailPrefix: ['inventory', 'items'],
    listPrefixes: [['inventory', 'items']],
    relatedPrefixes: [
      ['inventory', 'categories'],
      ['inventory', 'uoms'],
      ['purchasing'],
      ['mrp'],
      ['production', 'work-orders'],
      ['crm', 'products'],
      ['quality'],
      ['inventory', 'dashboard'],
      ['dashboard'],
      ['dashboards'],
    ],
    countPrefixes: [['sidebar', 'badges']],
  },
  stock_count_session: {
    detailPrefix: ['warehouse', 'stock-count'],
    listPrefixes: [['warehouse', 'stock-counts']],
    relatedPrefixes: [
      ['inventory', 'stock-levels'],
      ['inventory', 'movements'],
      ['inventory', 'stock-movements'],
      ['inventory', 'dashboard'],
      ['inventory', 'stock-adjustments'],
      ['dashboard', 'widget-data'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['approvals'], ['dashboard'], ['dashboards']],
  },
  supplier_listing: {
    detailPrefix: ['purchasing', 'supplier-listings'],
    listPrefixes: [['purchasing', 'supplier-listings']],
    relatedPrefixes: [['purchasing', 'approved-suppliers'], ['portal', 'supplier', 'item-listings']],
    countPrefixes: [['sidebar', 'badges'], ['approvals']],
  },
  approved_supplier: {
    detailPrefix: ['purchasing', 'approved-suppliers'],
    listPrefixes: [['purchasing', 'approved-suppliers']],
    relatedPrefixes: [],
    countPrefixes: [],
  },
  inspection: {
    detailPrefix: ['quality', 'inspections', 'detail'],
    listPrefixes: [['quality', 'inspections', 'list']],
    relatedPrefixes: [
      ['quality', 'ncrs'],
      ['quality', 'ncr-effectiveness'],
      ['quality', 'work-order-final'],
      ['quality', 'pareto'],
      ['quality', 'pass-rate'],
      ['quality', 'dashboard'],
      ['inventory', 'dashboard'],
      ['production', 'work-orders'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards'], ['approvals']],
  },
  ncr: {
    detailPrefix: ['quality', 'ncrs'],
    listPrefixes: [['quality', 'ncrs']],
    relatedPrefixes: [['quality', 'ncr-effectiveness'], ['inventory', 'mrb'], ['inventory', 'stock-levels'], ['inventory', 'dashboard'], ['crm', 'complaints'], ['production', 'repair-suggestion']],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards'], ['approvals']],
  },
  calibration: {
    detailPrefix: ['quality', 'calibration', 'detail'],
    listPrefixes: [['quality', 'calibration']],
    relatedPrefixes: [['quality', 'dashboard'], ['dashboards', 'quality']],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards']],
  },
  ppap: {
    detailPrefix: ['quality', 'ppap'],
    listPrefixes: [['quality', 'ppap']],
    relatedPrefixes: [['purchasing', 'purchase-orders'], ['purchasing', 'approved-suppliers'], ['quality', 'dashboard']],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards'], ['approvals']],
  },
  inspection_spec: {
    detailPrefix: ['quality', 'inspection-specs', 'show'],
    listPrefixes: [['quality', 'inspection-specs']],
    relatedPrefixes: [['crm', 'products'], ['production', 'assignment-suggestion']],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards']],
  },
  ncr_template: {
    detailPrefix: ['quality', 'ncr-templates'],
    listPrefixes: [
      ['quality', 'ncr-templates', 'list'],
      ['quality', 'ncr-templates', 'active'],
    ],
    relatedPrefixes: [],
    countPrefixes: [],
  },
  quality_plan: {
    detailPrefix: ['inventory', 'quality-plans'],
    listPrefixes: [['inventory', 'quality-plans']],
    relatedPrefixes: [['inventory', 'items']],
    countPrefixes: [],
  },
  return_request: {
    detailPrefix: ['return-request'],
    listPrefixes: [['return-requests']],
    relatedPrefixes: [
      ['accounting', 'credit-notes'],
      ['accounting', 'credit-memos'],
      ['accounting', 'invoices'],
      ['inventory', 'grn'],
      ['inventory', 'movements'],
      ['inventory', 'stock-levels'],
      ['inventory', 'dashboard'],
      ['purchasing', 'purchase-orders'],
      ['production', 'work-orders'],
      ['quality', 'inspections'],
      ['quality', 'ncrs'],
      ['supply-chain', 'deliveries'],
      ...RETURN_CASE_SOURCE_OPTION_PREFIXES,
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards'], ['approvals']],
  },
  return_case: {
    detailPrefix: ['return-cases', 'detail'],
    listPrefixes: [['return-cases', 'list']],
    relatedPrefixes: [
      ['return-requests'],
      ['accounting', 'credit-notes'],
      ['accounting', 'credit-memos'],
      ['accounting', 'invoices'],
      ['inventory', 'grn'],
      ['purchasing', 'purchase-orders'],
      ['supply-chain', 'deliveries'],
      ...RETURN_CASE_SOURCE_REFRESH_PREFIXES,
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards'], ['approvals']],
  },
  shipment: {
    detailPrefix: ['supply-chain', 'shipments'],
    listPrefixes: [['supply-chain', 'shipments']],
    relatedDetailKeys: (shipmentId) => [
      ['supply-chain', 'shipments', shipmentId, 'containers'],
    ],
    relatedPrefixes: [
      ['inventory', 'grn'],
      ['purchasing', 'purchase-orders'],
      ['accounting', 'bills'],
      ['dashboard', 'widget-data'],
      ['dashboard', 'plant-manager'],
      ['dashboard', 'logistics'],
      ['kpi'],
      ['dashboards'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards']],
  },
  vehicle: {
    detailPrefix: ['supply-chain', 'vehicles'],
    listPrefixes: [['supply-chain', 'vehicles']],
    relatedPrefixes: [
      ['supply-chain', 'deliveries'],
      ['dashboard', 'logistics'],
      ['dashboard', 'widget-data'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards']],
  },
  shipment_lot: {
    detailPrefix: ['quality', 'shipment-lots'],
    listPrefixes: [['quality', 'traceability']],
    relatedPrefixes: [['supply-chain', 'deliveries']],
    countPrefixes: [],
  },
  maintenance_work_order: {
    detailPrefix: ['maintenance', 'work-order'],
    listPrefixes: [['maintenance', 'work-orders']],
    relatedPrefixes: [
      ['maintenance', 'downtime-analytics'],
      ['maintenance', 'dashboard'],
      ['inventory'],
      ['mrp', 'machines'],
      ['mrp', 'molds'],
      ['production', 'dashboard'],
      ['production', 'assignment-suggestion'],
      ['production', 'repair-suggestion'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards']],
  },
  maintenance_schedule: {
    detailPrefix: ['maintenance', 'schedules', 'detail'],
    listPrefixes: [['maintenance', 'schedules', 'list']],
    relatedPrefixes: [
      ['maintenance', 'schedules', 'window-suggestions'],
      ['maintenance', 'work-orders'],
      ['mrp', 'scheduler', 'snapshot'],
      ['production', 'assignment-suggestion'],
      ['production', 'repair-suggestion'],
      ['dashboard', 'widget-data'],
      ['kpi'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards']],
  },
  production_schedule: {
    detailPrefix: ['mrp', 'scheduler', 'snapshot'],
    listPrefixes: [['mrp', 'scheduler', 'snapshot']],
    relatedPrefixes: [['production', 'work-orders'], ['production', 'assignment-suggestion'], ['production', 'repair-suggestion'], ['production', 'dashboard']],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards']],
  },
  product_routing: {
    detailPrefix: ['production', 'routings', 'detail'],
    listPrefixes: [['production', 'routings']],
    relatedPrefixes: [
      ['crm', 'products'],
      ['mrp'],
      ['production', 'work-orders'],
      ['production', 'assignment-suggestion'],
      ['production', 'repair-suggestion'],
      ['production', 'dashboard'],
      ['dashboard', 'plant-manager'],
      ['dashboard', 'widget-data'],
      ['dashboards'],
    ],
    countPrefixes: [],
  },
  machine: {
    detailPrefix: ['mrp', 'machines', 'detail'],
    listPrefixes: [['mrp', 'machines', 'list']],
    relatedPrefixes: [
      ['mrp', 'scheduler', 'snapshot'],
      ['mrp', 'molds'],
      ['production', 'work-orders'],
      ['production', 'assignment-suggestion'],
      ['production', 'repair-suggestion'],
      ['production', 'dashboard'],
      ['maintenance', 'work-orders'],
      ['dashboard', 'widget-data'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards']],
  },
  mold: {
    detailPrefix: ['mrp', 'molds', 'detail'],
    listPrefixes: [['mrp', 'molds', 'list']],
    relatedPrefixes: [
      ['mrp', 'molds', 'history'],
      ['mrp', 'machines'],
      ['mrp', 'scheduler', 'snapshot'],
      ['production', 'work-orders'],
      ['production', 'assignment-suggestion'],
      ['production', 'repair-suggestion'],
      ['production', 'dashboard'],
      ['maintenance', 'work-orders'],
      ['maintenance', 'schedules'],
      ['dashboard', 'widget-data'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards']],
  },
  bom: {
    detailPrefix: ['mrp', 'boms', 'detail'],
    listPrefixes: [['mrp', 'boms']],
    relatedPrefixes: [
      ['crm', 'products'],
      ['mrp', 'plans'],
      ['mrp', 'runs'],
      ['mrp', 'scheduler', 'snapshot'],
      ['production', 'work-orders'],
      ['production', 'dashboard'],
      ['dashboard', 'widget-data'],
      ['dashboards'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards']],
  },
  mrp_plan: {
    detailPrefix: ['mrp', 'plans', 'detail'],
    listPrefixes: [['mrp', 'plans']],
    relatedPrefixes: [
      ['crm', 'sales-orders'],
      ['production', 'work-orders'],
      ['purchasing', 'purchase-requests'],
      ['purchasing', 'purchase-orders'],
      ['mrp', 'scheduler', 'snapshot'],
      ['production', 'dashboard'],
      ['dashboard', 'widget-data'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards'], ['approvals']],
  },
  mrp_run: {
    detailPrefix: ['mrp', 'runs'],
    listPrefixes: [['mrp', 'runs']],
    relatedPrefixes: [['mrp', 'plans'], ['production', 'dashboard'], ['dashboard', 'widget-data']],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards'], ['approvals']],
  },
  product: {
    detailPrefix: ['crm', 'products', 'detail'],
    listPrefixes: [['crm', 'products']],
    // Forecast resources include Product names/part numbers. ProductChanged is
    // authorized for forecasting viewers too, so refresh those read models
    // through their existing API scope when a label changes.
    relatedPrefixes: [
      ['products'],
      ['forecasting/list'],
      ['forecasting', 'demand'],
      ['forecasting/accuracy/products'],
      ['forecasting', 'accuracy', 'products'],
      ...RETURN_CASE_SOURCE_OPTION_PREFIXES,
    ],
    countPrefixes: [],
  },
  customer: {
    detailPrefix: ['accounting', 'customers'],
    listPrefixes: [['accounting', 'customers'], ['crm', 'customers']],
    relatedPrefixes: [
      ['accounting', 'customers', 'all'],
      ['accounting', 'customers', 'lookup'],
      ['accounting', 'customers', 'complaint-lookup'],
      ['crm', 'customers', 'price-agreement-lookup'],
      ['crm', 'sales-orders'],
      ...RETURN_CASE_SOURCE_REFRESH_PREFIXES,
      ['crm', 'complaints'],
      ['crm', 'price-agreements'],
      ['accounting', 'invoices'],
      ['accounting', 'credit-notes'],
      ['forecasting', 'customer-options'],
      ['forecasting/list'],
      ['forecasting', 'demand'],
      ['dashboard', 'finance'],
      ['dashboard', 'warehouse'],
      ['dashboard', 'accounting'],
      ['dashboard', 'widget-data'],
    ],
    countPrefixes: [['dashboard'], ['dashboards']],
  },
  price_agreement: {
    detailPrefix: ['crm', 'price-agreements', 'detail'],
    listPrefixes: [['crm', 'price-agreements']],
    relatedPrefixes: [],
    countPrefixes: [],
  },
  price_request: {
    detailPrefix: ['pricing-requests'],
    listPrefixes: [['pricing-requests'], ['portal', 'pricing']],
    relatedPrefixes: [
      ['pricing-request-review'],
      ['dashboard', 'widget-data'],
      ['dashboard'],
      ['dashboards'],
    ],
    countPrefixes: [],
  },
  payroll_period: {
    detailPrefix: ['payroll-period'],
    listPrefixes: [['payroll-periods'], ['payroll-periods-list-for-compare']],
    relatedPrefixes: [['payrolls'], ['payroll-period-variance'], ['payroll-bank-file-preview'], ['dashboard'], ['accounting', 'dashboard'], ['dashboards'], ['chain-bottlenecks']],
    countPrefixes: [['sidebar', 'badges'], ['approvals']],
  },
  payroll_adjustment: {
    detailPrefix: ['payroll-adjustment'],
    listPrefixes: [['payroll-adjustments']],
    relatedPrefixes: [['payroll-period'], ['payroll-periods'], ['payrolls'], ['dashboard'], ['dashboards']],
    countPrefixes: [['sidebar', 'badges'], ['approvals'], ['dashboard'], ['dashboards']],
  },
  payroll_anomaly: {
    detailPrefix: ['payroll'],
    listPrefixes: [['payroll']],
    relatedPrefixes: [['payroll-period']],
    countPrefixes: [],
  },
  clearance: {
    detailPrefix: ['clearance'],
    listPrefixes: [['hr', 'separations']],
    relatedPrefixes: [['hr', 'employees'], ['hr', 'employee'], ['payrolls'], ['accounting'], ['dashboard']],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards'], ['approvals']],
  },
  department: {
    detailPrefix: ['hr', 'departments', 'detail'],
    listPrefixes: [['hr', 'departments'], ['departments'], ['departments-tree']],
    relatedPrefixes: [
      ['hr', 'employees'], ['employees'], ['hr', 'positions'], ['positions'],
      ['attendance'], ['calendar'], ['leave-calendar'], ['training-matrix'],
      ['assets'], ['asset'], ['budgets'], ['approvals'],
      ['admin-users'], ['admin-user'], ['admin-user-options'],
      ['dashboard', 'finance'], ['accounting', 'dashboard'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards']],
  },
  position: {
    detailPrefix: ['hr', 'positions', 'detail'],
    listPrefixes: [['hr', 'positions'], ['positions']],
    relatedPrefixes: [
      ['hr', 'departments'], ['departments'], ['departments-tree'],
      ['hr', 'employees'], ['employees'], ['recruitment-options'],
      ['admin-users'], ['admin-user'],
      ['recruitment'], ['recruitment-posting'], ['recruitment-postings'],
    ],
    countPrefixes: [['dashboard', 'hr'], ['dashboard'], ['dashboards']],
  },
  employee_training: {
    detailPrefix: ['employee-training'],
    listPrefixes: [['hr', 'employee-training-records']],
    relatedPrefixes: [
      ['employee-trainings'], ['employee-training'],
      ['self-service', 'home'], ['self-service', 'dashboard'],
      ['dashboard', 'hr'], ['dashboard'], ['dashboards'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['approvals']],
  },
  employee: {
    detailPrefix: ['hr', 'employee'],
    listPrefixes: [['hr', 'employees'], ['employees']],
    relatedPrefixes: [
      ['hr', 'employee-options'], ['hr', 'salary-adjustments'], ['salary-adjustments'], ['employee-account'], ['employee-onboarding'],
      ['payroll'], ['payrolls'], ['attendance'], ['leaves'],
      ['admin-users'], ['admin-user'], ['admin-employee-candidates'],
      ['production', 'crew'], ['production', 'crew-options'], ['production', 'execution-readiness'], ['production', 'assignment-suggestion'], ['production', 'repair-suggestion'],
      ['dashboard', 'hr'], ['dashboard'], ['dashboards'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard', 'hr'], ['dashboard'], ['dashboards']],
  },
  employee_document: {
    detailPrefix: ['employee-documents'],
    listPrefixes: [['employee-documents'], ['documents', 'employees']],
    relatedPrefixes: [['employee-document-options']],
    countPrefixes: [],
  },
  employee_skill: {
    detailPrefix: ['employee-skills'],
    listPrefixes: [
      ['employee-skills'],
      ['training-matrix'],
      ['hr', 'skills', 'matrix'],
      ['hr', 'skills', 'gap-analysis'],
    ],
    relatedPrefixes: [],
    countPrefixes: [],
  },
  employee_onboarding: {
    detailPrefix: ['employee-onboarding'],
    listPrefixes: [],
    relatedPrefixes: [],
    countPrefixes: [],
  },
  delivery_schedule: {
    detailPrefix: ['b2b', 'portal-access', 'delivery-schedule'],
    listPrefixes: [['b2b', 'portal-access', 'delivery-schedules']],
    relatedPrefixes: [],
    countPrefixes: [],
  },
  skill: {
    detailPrefix: ['hr', 'skills'],
    listPrefixes: [['hr', 'skills']],
    relatedPrefixes: [
      ['employee-skills'],
      ['training-matrix'],
      ['hr', 'skills', 'matrix'],
      ['hr', 'skills', 'gap-analysis'],
    ],
    countPrefixes: [],
  },
  profile_update_request: {
    detailPrefix: ['hr', 'profile-update-requests'],
    listPrefixes: [['hr', 'profile-update-requests']],
    relatedPrefixes: [
      ['hr', 'profile-update-request-options'],
      ['self-service', 'profile-requests'],
      ['self-service', 'profile'],
      ['sidebar', 'badges'],
      ['dashboard', 'hr'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard', 'hr']],
  },
  job_posting: {
    detailPrefix: ['recruitment-posting'],
    listPrefixes: [['recruitment-postings']],
    relatedPrefixes: [['recruitment-applications']],
    countPrefixes: [['sidebar', 'badges'], ['dashboard', 'hr']],
  },
  job_application: {
    detailPrefix: ['recruitment-application'],
    listPrefixes: [['recruitment-applications']],
    relatedPrefixes: [
      ['recruitment-posting'],
      ['recruitment-postings'],
      ['recruitment-conversion'],
      ['sidebar', 'badges'],
      ['dashboard', 'hr'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard', 'hr']],
  },
  employee_loan: {
    detailPrefix: ['loans', 'show'],
    listPrefixes: [['loans']],
    relatedPrefixes: [
      ['self-service', 'loans'],
      ['self-service', 'home'],
      ['self-service', 'dashboard'],
      ['hr', 'employees'],
      ['hr', 'employee'],
      ['approvals'],
      ['action-center'],
      ['dashboard', 'hr'],
      ['dashboard', 'finance'],
      ['accounting', 'dashboard'],
      ['accounting', 'journal-entries'],
      ['accounting', 'statements'],
      ['dashboard', 'widget-data'],
      ['dashboard', 'metric'],
      ['dashboard'],
      ['dashboards'],
      ['kpi'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['approvals'], ['dashboard'], ['dashboards']],
  },
  salary_adjustment: {
    detailPrefix: ['hr', 'salary-adjustment'],
    listPrefixes: [['hr', 'salary-adjustments']],
    relatedPrefixes: [['hr', 'employees'], ['hr', 'employee'], ['payrolls']],
    countPrefixes: [['sidebar', 'badges'], ['approvals'], ['dashboard', 'hr'], ['dashboard'], ['dashboards']],
  },
  training: {
    detailPrefix: ['hr', 'training'],
    listPrefixes: [['hr', 'trainings']],
    relatedPrefixes: [['employee-training'], ['employee-trainings'], ['hr', 'employee-training-records']],
    countPrefixes: [['dashboard', 'hr'], ['dashboard'], ['dashboards']],
  },
  leave_request: {
    detailPrefix: ['leaves', 'request'],
    listPrefixes: [['leaves'], ['self-service', 'leave'], ['hr', 'employees']],
    relatedPrefixes: [
      ['leaves', 'balances'],
      ['self-service', 'leave-balances'],
      ['leave-calendar'],
      ['calendar', 'events'],
      ['attendance', 'attendances'],
      ['hr', 'employee'],
      ['production', 'execution-readiness'],
      ['production', 'crew'],
      ['production', 'crew-options'],
      ['production', 'assignment-suggestion'],
      ['production', 'repair-suggestion'],
      ['self-service', 'home'],
      ['self-service', 'dashboard'],
      ['dashboard'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards'], ['approvals']],
  },
  leave_type: {
    detailPrefix: ['leave-types'],
    listPrefixes: [
      ['leave-types'],
      ['leave-types-self'],
      ['leave-request-options'],
      ['leaves'],
      ['self-service', 'leave'],
    ],
    relatedPrefixes: [['self-service', 'home']],
    countPrefixes: [],
  },
  leave_balance: {
    detailPrefix: ['leaves', 'balances'],
    listPrefixes: [
      ['leaves', 'balances'],
      ['self-service', 'leave-balances'],
      ['self-service', 'home'],
      ['self-service', 'dashboard'],
      ['hr', 'employee'],
    ],
    relatedPrefixes: [['dashboard', 'hr'], ['dashboard', 'widget-data']],
    countPrefixes: [],
  },
  attendance: {
    detailPrefix: ['attendance', 'records'],
    listPrefixes: [['attendance', 'attendances']],
    relatedPrefixes: [
      ['attendance', 'records'],
      ['attendance', 'punch', 'status'],
      ['dashboard', 'hr'], ['dashboard', 'employee'], ['dashboard', 'widget-data'], ['dashboard', 'metric'],
      ['production', 'execution-readiness'], ['production', 'crew'],
      ['production', 'crew-options'], ['production', 'assignment-suggestion'], ['production', 'repair-suggestion'],
      ['payroll'], ['payrolls'], ['self-service', 'home'], ['self-service', 'dashboard'],
      ['dashboard'], ['dashboards'], ['kpi'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard', 'hr'], ['dashboard', 'employee'], ['dashboard'], ['dashboards']],
  },
  shift: {
    detailPrefix: ['attendance', 'shifts', 'detail'],
    listPrefixes: [['attendance', 'shifts']],
    relatedPrefixes: [
      ['attendance', 'shift-assignments'],
      ['attendance', 'employee-shift'],
      ['employee-onboarding'],
      ['attendance', 'records'],
      ['self-service', 'home'],
      ['self-service', 'dashboard'],
      ['dashboard', 'hr'],
      ['dashboard', 'employee'],
      ['production', 'work-orders'],
      ['production', 'execution-readiness'],
      ['production', 'crew'],
      ['production', 'crew-options'],
      ['production', 'assignment-suggestion'],
      ['production', 'repair-suggestion'],
    ],
    countPrefixes: [],
  },
  employee_shift_assignment: {
    detailPrefix: ['attendance', 'shift-assignment'],
    listPrefixes: [['attendance', 'shift-assignments']],
    relatedPrefixes: [
      ['attendance', 'employee-shift'],
      ['attendance', 'current-shift'],
      ['attendance', 'records'],
      ['self-service', 'home'],
      ['self-service', 'dashboard'],
      ['dashboard', 'hr'],
      ['dashboard', 'employee'],
      ['production', 'execution-readiness'],
      ['production', 'crew'],
      ['production', 'crew-options'],
      ['production', 'assignment-suggestion'],
      ['production', 'repair-suggestion'],
    ],
    countPrefixes: [],
  },
  overtime_request: {
    detailPrefix: ['attendance', 'overtime', 'request'],
    listPrefixes: [['attendance', 'overtime']],
    relatedPrefixes: [
      ['self-service', 'overtime'],
      ['attendance', 'records'],
      ['approvals'],
      ['self-service', 'home'],
      ['self-service', 'dashboard'],
      ['dashboard', 'hr'],
      ['dashboard', 'employee'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards']],
  },
  holiday: {
    detailPrefix: ['attendance', 'holidays'],
    listPrefixes: [['attendance', 'holidays']],
    relatedPrefixes: [
      ['calendar', 'events'],
      ['leave-calendar'],
      ['attendance', 'attendances'],
      ['production', 'crew-options'],
      ['production', 'assignment-suggestion'],
      ['production', 'repair-suggestion'],
      ['dashboard', 'hr'],
      ['self-service', 'home'],
      ['self-service', 'dashboard'],
    ],
    countPrefixes: [],
  },
  government_table: {
    detailPrefix: ['gov-tables'],
    listPrefixes: [['gov-tables']],
    relatedPrefixes: [],
    countPrefixes: [],
  },
  credit_note: {
    detailPrefix: ['accounting', 'credit-notes'],
    listPrefixes: [['accounting', 'credit-notes']],
    relatedPrefixes: [
      ['accounting', 'bills'],
      ['accounting', 'invoices'],
      ['accounting', 'journal-entries'],
      ['accounting', 'statements'],
      ['purchasing', 'chain'],
      ['return-cases', 'detail'],
      ['return-cases', 'list'],
      ['return-request'],
      ['return-requests'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['accounting', 'dashboard'], ['dashboards'], ['approvals']],
  },
  account: {
    detailPrefix: ['accounting', 'accounts'],
    listPrefixes: [
      ['accounting', 'accounts', 'tree'],
      ['accounting', 'accounts', 'list'],
      ['accounting', 'accounts', 'flat-active'],
      ['accounting', 'accounts', 'cash'],
      ['accounting', 'accounts', 'expense'],
      ['accounting', 'accounts', 'revenue'],
      ['accounts'],
    ],
    relatedPrefixes: [
      ['accounting', 'bills'],
      ['accounting', 'credit-notes'],
      ['accounting', 'invoices'],
      ['accounting', 'journal-entries'],
      ['accounting', 'statements'],
      ['budget'],
      ['budget-overview'],
      ['budgets'],
      ['crm', 'products'],
    ],
    countPrefixes: [['dashboard', 'finance'], ['accounting', 'dashboard'], ['dashboards']],
  },
  accounting_period: {
    detailPrefix: ['accounting', 'periods'],
    listPrefixes: [['accounting', 'periods']],
    relatedPrefixes: [['dashboard', 'finance'], ['accounting', 'dashboard']],
    countPrefixes: [['dashboards']],
  },
  budget_transfer: {
    detailPrefix: ['budget-transfer'],
    listPrefixes: [['budget-transfers']],
    relatedPrefixes: [
      ['budget'],
      ['budget-overview'],
      ['budgets'],
      ['dashboard', 'accounting'],
      ['dashboard', 'finance'],
      ['accounting', 'dashboard'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['approvals'], ['dashboard', 'finance'], ['accounting', 'dashboard'], ['dashboards']],
  },
  budget: {
    detailPrefix: ['budget'],
    listPrefixes: [['budgets']],
    relatedPrefixes: [
      ['budget-overview'],
      ['budget-vs-actual'],
      ['budget-fiscal-years'],
      ['budget-sync-status'],
      ['budget-transfer'],
      ['budget-transfers'],
      ['dashboard', 'accounting'],
      ['dashboard', 'finance'],
      ['accounting', 'dashboard'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['approvals'], ['dashboard', 'accounting'], ['dashboard', 'finance'], ['accounting', 'dashboard'], ['dashboards']],
  },
  payroll_de_minimis: {
    detailPrefix: ['de-minimis'],
    listPrefixes: [['de-minimis']],
    relatedPrefixes: [],
    countPrefixes: [],
  },
  asset: {
    detailPrefix: ['asset'],
    listPrefixes: [['assets']],
    relatedPrefixes: [
      ['mrp', 'machines'],
      ['mrp', 'molds'],
      ['supply-chain', 'vehicles'],
      ['accounting', 'journal-entries'],
      ['accounting', 'statements'],
      ['dashboard'],
      ['dashboards'],
      ['kpi'],
    ],
    countPrefixes: [['sidebar', 'badges'], ['dashboard'], ['dashboards'], ['approvals']],
  },
  journal_entry: {
    detailPrefix: ['accounting', 'journal-entries'],
    listPrefixes: [['accounting', 'journal-entries']],
    relatedPrefixes: [
      ['accounting', 'statements'],
      ['dashboard', 'finance'],
      ['accounting', 'dashboard'],
      ['dashboard', 'widget-data'],
      ['dashboard', 'metric'],
      ['kpi'],
      ['chain-bottlenecks'],
    ],
    countPrefixes: [],
  },
};

const realtimeManagedQueryPrefixes: QueryKey[] = [
  ...Object.values(realtimeInvalidationMap).flatMap((definition) => [
    definition.detailPrefix,
    ...definition.listPrefixes,
    ...definition.relatedPrefixes,
    ...(definition.relatedChangePrefixes ?? []),
    ...definition.countPrefixes,
  ]),
  ...Object.values(realtimeInvalidationMap).flatMap((definition) => {
    const queryId = '__realtime_query_family__';

    return (definition.relatedDetailKeys?.(queryId) ?? []).flatMap((key) => {
      const queryIdIndex = key.indexOf(queryId);

      // An empty prefix would classify every active query as live-managed.
      // Current factories place the record ID after a family prefix; skip a
      // future factory that puts the ID first until it declares an explicit
      // catch-up prefix.
      if (queryIdIndex === 0) return [];

      return [queryIdIndex > 0 ? key.slice(0, queryIdIndex) : key];
    });
  }),
  ...Object.values(realtimeSignalInvalidationMap).flat(),
  ...Object.values(cacheGroupInvalidationMap).flat(),
];

/** Query families that need catch-up after a missed live/near-live signal. */
export function isRealtimeManagedQueryKey(queryKey: QueryKey): boolean {
  return realtimeManagedQueryPrefixes.some((prefix) => keyStartsWith(queryKey, prefix));
}

/** Personal shop-floor query families invalidated only on that employee's private channel. */
export function assignedWorkOrderInvalidationKeys(userId: string): QueryKey[] {
  return [
    ['my-work-orders', userId],
    ['my-work-order-history', userId],
    ['my-output-options', userId],
    ['my-outputs', userId],
    ['my-late-production-reports', userId],
  ];
}

export function realtimeEventNameForEntity(entityType: RealtimeEntityType): string {
  if (entityType === 'kpi_snapshot') return '.kpi.snapshot.changed';
  if (entityType === 'import_batch') return '.admin.import-batch.changed';
  if (entityType === 'customer') return '.accounting.customer.changed';
  if (entityType === 'admin_user') return '.admin.user.changed';
  if (entityType === 'position') return '.hr.position.changed';
  if (entityType === 'role') return '.admin.role.changed';
  if (entityType === 'item') return '.inventory.item.changed';
  if (entityType === 'stock_adjustment') return '.inventory.stock-adjustment.changed';
  if (entityType === 'item_cost_review') return '.inventory.cost-review.changed';
  if (entityType === 'material_review_record') return '.inventory.mrb.changed';
  if (entityType === 'material_issue_slip') return '.inventory.material-issue.changed';
  if (entityType === 'asset') return '.asset.changed';
  if (entityType === 'stock_count_session') return '.inventory.stock-count.changed';
  if (entityType === 'product_routing') return '.production.routing.changed';
  if (entityType === 'transfer_order') return '.inventory.transfer-order.changed';
  if (entityType === 'leave_request') return '.leave.request.changed';
  if (entityType === 'leave_type') return '.leave.type.changed';
  if (entityType === 'leave_balance') return '.leave.balance.changed';
  if (entityType === 'holiday') return '.attendance.holiday.changed';
  if (entityType === 'attendance') return '.attendance.changed';
  if (entityType === 'shift') return '.attendance.shift.changed';
  if (entityType === 'employee_shift_assignment') return '.attendance.shift-assignment.changed';
  if (entityType === 'employee_loan') return '.loan.changed';
  if (entityType === 'overtime_request') return '.attendance.overtime.changed';
  if (entityType === 'government_table') return '.government.table.changed';
  if (entityType === 'payroll_de_minimis') return '.payroll.de-minimis.changed';
  if (entityType === 'credit_note') return '.accounting.credit-note.changed';
  if (entityType === 'vendor') return '.accounting.vendor.changed';
  if (entityType === 'supplier_performance') return '.purchasing.supplier-performance.changed';
  if (entityType === 'scheduled_export') return '.scheduled.export.changed';
  if (entityType === 'account') return '.accounting.account.changed';
  if (entityType === 'accounting_period') return '.accounting.period.changed';
  if (entityType === 'budget_transfer') return '.accounting.budget-transfer.changed';
  if (entityType === 'budget') return '.accounting.budget.changed';
  if (entityType === 'journal_entry') return '.accounting.journal-entry.changed';
  if (entityType === 'request_for_quote') return '.rfq.refresh';
  if (entityType === 'supplier_listing' || entityType === 'approved_supplier') return '.supplier.catalog.changed';
  if (entityType === 'inspection') return '.inspection.refresh';
  if (entityType === 'ncr') return '.ncr.refresh';
  if (entityType === 'calibration' || entityType === 'ppap' || entityType === 'inspection_spec' || entityType === 'ncr_template' || entityType === 'quality_plan') return '.quality.record.refresh';
  if (entityType === 'return_request') return '.return.request.refresh';
  if (entityType === 'return_case') return '.return.case.refresh';
  if (entityType === 'shipment') return '.supply-chain.shipment.changed';
  if (entityType === 'vehicle') return '.supply-chain.vehicle.changed';
  if (entityType === 'shipment_lot') return '.shipment.lot.refresh';
  if (entityType === 'maintenance_work_order') return '.maintenance.work-order.changed';
  if (entityType === 'maintenance_schedule') return '.maintenance.schedule.changed';
  if (entityType === 'late_production_report') return '.production.late-report.changed';
  if (entityType === 'customer_complaint') return '.crm.complaint.changed';
  if (entityType === 'demand_forecast') return '.forecast.demand-forecast.changed';
  if (entityType === 'product') return '.crm.product.changed';
  if (entityType === 'price_agreement') return '.crm.price-agreement.changed';
  if (entityType === 'price_request') return '.crm.price-request.changed';
  if (entityType === 'mold') return '.chain.refresh';
  if (entityType === 'bom') return '.chain.refresh';
  if (entityType === 'payroll_period') return '.payroll.period.changed';
  if (entityType === 'payroll_adjustment') return '.payroll.adjustment.changed';
  if (entityType === 'payroll_anomaly') return '.payroll.anomaly.changed';
  if (entityType === 'clearance') return '.hr.clearance.changed';
  if (entityType === 'department') return '.hr.department.changed';
  if (entityType === 'employee_training') return '.hr.employee-training.changed';
  if (entityType === 'employee') return '.hr.employee.changed';
  if (entityType === 'employee_document') return '.hr.employee-documents.changed';
  if (entityType === 'employee_skill') return '.hr.employee-skill.changed';
  if (entityType === 'skill') return '.hr.skill.changed';
  if (entityType === 'profile_update_request') return '.hr.profile-update-request.changed';
  if (entityType === 'employee_onboarding') return '.hr.employee-onboarding.changed';
  if (entityType === 'delivery_schedule') return '.b2b.delivery-schedule.changed';
  if (entityType === 'job_posting' || entityType === 'job_application') return '.hr.recruitment.changed';
  if (entityType === 'salary_adjustment') return '.hr.salary-adjustment.changed';
  if (entityType === 'training') return '.hr.training.changed';
  return '.chain.refresh';
}

function uniqueKeys(keys: QueryKey[]): QueryKey[] {
  const seen = new Set<string>();
  return keys.filter((key) => {
    const serialized = JSON.stringify(key);
    if (seen.has(serialized)) return false;
    seen.add(serialized);
    return true;
  });
}

function keysForChange(change: RealtimeChangeRef, seen: Set<string>): QueryKey[] {
  const identity = `${change.entityType}:${change.entityId}`;
  if (seen.has(identity)) return [];
  seen.add(identity);

  const definition = realtimeInvalidationMap[change.entityType];
  const keys = [
    [...definition.detailPrefix, change.entityId],
    ...definition.listPrefixes,
    ...definition.relatedPrefixes,
    ...(definition.relatedChangePrefixes ?? []),
    ...(definition.relatedDetailKeys?.(change.entityId) ?? []),
    ...definition.countPrefixes,
  ];
  if (GLOBAL_SEARCH_ENTITY_TYPES.has(change.entityType)) keys.push(COMMAND_PALETTE_SEARCH_PREFIX);

  for (const related of change.related ?? []) keys.push(...keysForChange(related, seen));
  return keys;
}

export function queryKeysForRealtimeChange(change: RealtimeChangeRef): QueryKey[] {
  return uniqueKeys(keysForChange(change, new Set()));
}

export function queryKeysForRealtimeList(entityType: RealtimeEntityType): QueryKey[] {
  const definition = realtimeInvalidationMap[entityType];
  return uniqueKeys([
    ...definition.listPrefixes,
    ...definition.relatedPrefixes,
    ...definition.countPrefixes,
    ...(GLOBAL_SEARCH_ENTITY_TYPES.has(entityType) ? [COMMAND_PALETTE_SEARCH_PREFIX] : []),
  ]);
}

export function queryKeysForRealtimeSignal(signal: RealtimeSignal): QueryKey[] {
  return uniqueKeys(realtimeSignalInvalidationMap[signal]);
}

function keyStartsWith(queryKey: QueryKey, prefix: QueryKey): boolean {
  return prefix.length <= queryKey.length && partialMatchKey(queryKey, prefix);
}

type RealtimeInvalidationClient = Pick<QueryClient, 'invalidateQueries'>
  & Partial<Pick<QueryClient, 'cancelQueries' | 'getQueryCache' | 'setQueryData'>>;

async function invalidatePrefixesOnce(
  queryClient: RealtimeInvalidationClient,
  prefixes: QueryKey[],
  excludedExactKeys: QueryKey[] = [],
): Promise<void> {
  if (prefixes.length === 0) return;

  const isExcluded = (query: { queryKey: QueryKey }) => excludedExactKeys.some((key) =>
    key.length === query.queryKey.length
      && keyStartsWith(query.queryKey, key)
      && keyStartsWith(key, query.queryKey),
  );
  const matches = (query: {
    queryKey: QueryKey;
    isActive(): boolean;
    state: { fetchStatus: string; error: unknown };
  }) => !isExcluded(query)
    && !isClientHttpError(query.state.error)
    && prefixes.some((prefix) => keyStartsWith(query.queryKey, prefix));

  // A fresh mutation response may already have been installed while an older
  // request for the same key is still pending. Snapshot that data, cancel with
  // TanStack's normal revert behavior so the query does not become an error,
  // then restore the authoritative value into its now-idle query state.
  const preservedSnapshots = queryClient.getQueryCache?.().findAll({
    predicate: (query) => isExcluded(query) && query.state.fetchStatus === 'fetching',
  }).flatMap((query) => query.state.data === undefined ? [] : [{
    queryKey: query.queryKey,
    data: query.state.data,
    updatedAt: query.state.dataUpdatedAt,
  }]) ?? [];
  await queryClient.cancelQueries?.({
    predicate: (query) => isExcluded(query) && query.state.fetchStatus === 'fetching',
  }, { revert: true });
  for (const snapshot of preservedSnapshots) {
    queryClient.setQueryData?.(snapshot.queryKey, snapshot.data, { updatedAt: snapshot.updatedAt });
  }

  // A record ping can arrive while an older read is still in flight. TanStack
  // deduplicates a refetch against that request, which can make the stale
  // response win the race and defer the fresh read until the next signal.
  // Cancel only matching active reads, then the same invalidation starts one
  // current authorized fetch. QueryFns that consume TanStack's signal abort the
  // transport too; non-abortable queryFns still cannot write their old result.
  await queryClient.cancelQueries?.({
    predicate: (query) => matches(query) && query.isActive() && query.state.fetchStatus === 'fetching',
  }, { revert: true });

  // One query-cache traversal matches all affected prefixes. Calling
  // invalidateQueries once per prefix refetched the same active query several
  // times when detail/list/count prefixes overlapped.
  await queryClient.invalidateQueries({
    predicate: matches,
    refetchType: 'active',
  }, { cancelRefetch: false });

  // A reconnect/focus catch-up can cancel the same active read while this
  // event-driven invalidation is waiting for it. TanStack then restores the
  // previous data and leaves the query invalidated; make one trailing read so
  // the authorized response for this committed event is not lost.
  const stillInvalidated = (query: {
    queryKey: QueryKey;
    isActive(): boolean;
    state: { fetchStatus: string; isInvalidated: boolean; error: unknown };
  }) => matches(query) && query.isActive()
    && query.state.fetchStatus === 'idle'
    && query.state.isInvalidated;
  if (queryClient.getQueryCache?.().findAll({ predicate: stillInvalidated }).length) {
    await queryClient.invalidateQueries({
      predicate: stillInvalidated,
      refetchType: 'active',
    }, { cancelRefetch: true });
  }
  setRealtimeLastUpdatedAt(Date.now());
}

/** Invalidate query families while preserving authoritative exact query keys. */
export async function invalidateQueryPrefixesExcept(
  queryClient: RealtimeInvalidationClient,
  prefixes: QueryKey[],
  excludedExactKeys: QueryKey[] = [],
): Promise<void> {
  await invalidatePrefixesOnce(queryClient, prefixes, excludedExactKeys);
}

const REALTIME_INVALIDATION_COALESCE_MS = 100;
const REALTIME_NEAR_LIVE_INVALIDATION_DELAY_MS = 300;
// /search is limited to 30 requests per minute. Keep record-event refreshes to
// at most one request per five seconds (12/minute) per QueryClient, leaving
// same-tab budget for debounced term changes and explicit retries. This is not
// a cross-tab quota; multiple active tabs can still aggregate against the API
// limit and need browser-level coordination or a revised server budget.
const COMMAND_PALETTE_SEARCH_MIN_INTERVAL_MS = 5_000;
type RealtimeQueryClient = Pick<QueryClient, 'invalidateQueries'>;
interface PendingInvalidation {
  prefixes: QueryKey[];
  excludedExactKeys: QueryKey[];
  activePrefixes: QueryKey[];
  timer?: ReturnType<typeof setTimeout>;
  running: boolean;
}
const pendingRealtimeInvalidations = new WeakMap<object, Map<string, PendingInvalidation>>();
interface PendingSearchInvalidation {
  lastRunAt: number;
  pending: boolean;
  timer?: ReturnType<typeof setTimeout>;
}
const pendingSearchInvalidations = new WeakMap<object, PendingSearchInvalidation>();

function isCommandPaletteSearchPrefix(prefix: QueryKey): boolean {
  return prefix[0] === COMMAND_PALETTE_SEARCH_PREFIX[0]
    && prefix[1] === COMMAND_PALETTE_SEARCH_PREFIX[1];
}

function splitSearchInvalidation(prefixes: QueryKey[]): { ordinary: QueryKey[]; search: boolean } {
  return {
    ordinary: prefixes.filter((prefix) => !isCommandPaletteSearchPrefix(prefix)),
    search: prefixes.some(isCommandPaletteSearchPrefix),
  };
}

function scheduleCommandPaletteSearchInvalidation(queryClient: RealtimeQueryClient): void {
  const identity = queryClient as object;
  let state = pendingSearchInvalidations.get(identity);
  if (!state) {
    state = { lastRunAt: 0, pending: false };
    pendingSearchInvalidations.set(identity, state);
  }

  state.pending = true;
  if (state.timer !== undefined) return;

  const now = Date.now();
  const untilRateLimitExpires = state.lastRunAt === 0
    ? 0
    : Math.max(0, COMMAND_PALETTE_SEARCH_MIN_INTERVAL_MS - (now - state.lastRunAt));
  const delay = Math.max(REALTIME_INVALIDATION_COALESCE_MS, untilRateLimitExpires);
  state.timer = setTimeout(() => {
    state!.timer = undefined;
    if (!state!.pending) return;

    state!.pending = false;
    state!.lastRunAt = Date.now();
    void invalidatePrefixesOnce(queryClient, [COMMAND_PALETTE_SEARCH_PREFIX]);
  }, delay);
}

function schedulePendingInvalidation(
  queryClient: RealtimeQueryClient,
  identity: object,
  lane: string,
  pending: PendingInvalidation,
  delayMs = REALTIME_INVALIDATION_COALESCE_MS,
): void {
  if (pending.running || pending.timer !== undefined) return;

  pending.timer = setTimeout(() => {
    pending.timer = undefined;
    if (pending.prefixes.length === 0) {
      releasePendingInvalidation(identity, lane, pending);
      return;
    }

    const prefixes = uniqueKeys(pending.prefixes);
    const excludedExactKeys = uniqueKeys(pending.excludedExactKeys);
    pending.prefixes = [];
    pending.excludedExactKeys = [];
    pending.activePrefixes = prefixes;
    pending.running = true;
    void invalidatePrefixesOnce(queryClient, prefixes, excludedExactKeys)
      .catch(() => undefined)
      .finally(() => {
        pending.running = false;
        pending.activePrefixes = [];
        if (pending.prefixes.length > 0) {
          schedulePendingInvalidation(queryClient, identity, lane, pending);
        } else {
          releasePendingInvalidation(identity, lane, pending);
        }
      });
  }, delayMs);
}

function releasePendingInvalidation(identity: object, lane: string, pending: PendingInvalidation): void {
  const lanes = pendingRealtimeInvalidations.get(identity);
  if (lanes?.get(lane) !== pending) return;
  lanes.delete(lane);
  if (lanes.size === 0) pendingRealtimeInvalidations.delete(identity);
}

function scheduleInvalidation(
  queryClient: RealtimeQueryClient,
  prefixes: QueryKey[],
  lane = 'related',
  excludedExactKeys: QueryKey[] = [],
  delayMs = REALTIME_INVALIDATION_COALESCE_MS,
): void {
  if (prefixes.length === 0) return;

  const split = splitSearchInvalidation(prefixes);
  if (split.search) scheduleCommandPaletteSearchInvalidation(queryClient);
  prefixes = split.ordinary;
  if (prefixes.length === 0) return;

  const identity = queryClient as object;
  let lanes = pendingRealtimeInvalidations.get(identity);
  if (!lanes) {
    lanes = new Map();
    pendingRealtimeInvalidations.set(identity, lanes);
  }
  let pending = lanes.get(lane);
  if (!pending) {
    pending = { prefixes: [], excludedExactKeys: [], activePrefixes: [], running: false };
    lanes.set(lane, pending);
  }
  pending.prefixes.push(...prefixes);
  pending.excludedExactKeys.push(...excludedExactKeys);
  schedulePendingInvalidation(queryClient, identity, lane, pending, delayMs);
}

export async function invalidateRealtimeList(
  queryClient: Pick<QueryClient, 'invalidateQueries'>,
  entityType: RealtimeEntityType,
): Promise<void> {
  const split = splitSearchInvalidation(queryKeysForRealtimeList(entityType));
  if (split.search) scheduleCommandPaletteSearchInvalidation(queryClient);
  await invalidatePrefixesOnce(queryClient, split.ordinary);
}

/** Coalesce adjacent list signals into one active-query traversal/refetch. */
export function scheduleRealtimeListInvalidation(
  queryClient: RealtimeQueryClient,
  entityType: RealtimeEntityType,
): void {
  scheduleInvalidation(queryClient, queryKeysForRealtimeList(entityType));
}

/** Coalesce scoped realtime refreshes that have no record entity-map entry. */
export function scheduleRealtimeQueryInvalidation(
  queryClient: RealtimeQueryClient,
  prefixes: QueryKey[],
): void {
  scheduleInvalidation(queryClient, prefixes);
}

/** Per-user count signals use the count families owned by the entity map. */
export function scheduleRealtimeCountInvalidation(
  queryClient: RealtimeQueryClient,
  entityType: RealtimeEntityType,
): void {
  scheduleInvalidation(queryClient, realtimeInvalidationMap[entityType].countPrefixes);
}

/** Coalesce a shared system signal into one invalidation traversal. */
export function scheduleRealtimeSignalInvalidation(
  queryClient: RealtimeQueryClient,
  signal: RealtimeSignal,
): void {
  scheduleInvalidation(
    queryClient,
    queryKeysForRealtimeSignal(signal),
    'related',
    [],
    REALTIME_NEAR_LIVE_INVALIDATION_DELAY_MS,
  );
}

/** Coalesce invalidation for a registered backend cache generation. */
export function scheduleCacheGroupInvalidation(
  queryClient: RealtimeQueryClient,
  group: RealtimeCacheGroup,
): void {
  scheduleInvalidation(queryClient, cacheGroupInvalidationMap[group]);
}

export async function invalidateCacheGroup(
  queryClient: Pick<QueryClient, 'invalidateQueries'>,
  group: RealtimeCacheGroup,
): Promise<void> {
  await invalidatePrefixesOnce(queryClient, cacheGroupInvalidationMap[group]);
}

/** Catch up active detail and list views after a scoped channel reconnects. */
export function scheduleRealtimeEntityCatchUp(
  queryClient: RealtimeQueryClient,
  entityTypes: RealtimeEntityType[],
): void {
  scheduleInvalidation(queryClient, uniqueKeys(entityTypes.flatMap((entityType) => {
    const definition = realtimeInvalidationMap[entityType];

    return [
      definition.detailPrefix,
      ...definition.listPrefixes,
      ...definition.relatedPrefixes,
      ...definition.countPrefixes,
    ];
  })));
}

/** Refresh active authorized queries without canceling a record event already in flight. */
export async function catchUpRealtimeQueries(queryClient: QueryClient): Promise<void> {
  const eventRefreshPrefixes = [...(pendingRealtimeInvalidations.get(queryClient)?.values() ?? [])]
    .flatMap((pending) => pending.activePrefixes);
  const isEventRefresh = (query: { queryKey: QueryKey }) =>
    eventRefreshPrefixes.some((prefix) => keyStartsWith(query.queryKey, prefix));
  const isRealtimeManagedQuery = (query: { queryKey: QueryKey }) =>
    isRealtimeManagedQueryKey(query.queryKey);
  // A page may still be doing its first authorized read when a focus or
  // degraded-mode catch-up fires. Canceling that request leaves detail pages
  // on their loading skeleton, and repeated offline catch-ups can keep
  // restarting it. Let the current read settle, then run one trailing read for
  // every active query that was already in flight (except a newer event read).
  const activeReadsToRepeat = new Set(queryClient.getQueryCache().findAll()
    .filter((query) => query.isActive()
      && isRealtimeManagedQuery(query)
      && !isClientHttpError(query.state.error)
      && query.state.fetchStatus === 'fetching'
      && !isEventRefresh(query))
    .map((query) => query.queryHash));

  // A catch-up started by focus/reconnect can overlap a just-received record
  // event. Let the event's newer authorized read finish instead of canceling it.
  await queryClient.invalidateQueries({
    predicate: (query) => query.isActive()
      && isRealtimeManagedQuery(query)
      && !isClientHttpError(query.state.error),
    refetchType: 'active',
  }, { cancelRefetch: false });

  if (activeReadsToRepeat.size > 0) {
    await queryClient.refetchQueries({
      predicate: (query) => query.isActive() && activeReadsToRepeat.has(query.queryHash),
    }, { cancelRefetch: false });
  }

  const stillInvalidated = (query: {
    isActive(): boolean;
    queryKey: QueryKey;
    state: { fetchStatus: string; isInvalidated: boolean; error: unknown };
  }) => query.isActive()
    && isRealtimeManagedQuery(query)
    && !isClientHttpError(query.state.error)
    && query.state.fetchStatus === 'idle'
    && query.state.isInvalidated;
  if (queryClient.getQueryCache().findAll({ predicate: stillInvalidated }).length) {
    await queryClient.invalidateQueries({
      predicate: stillInvalidated,
      refetchType: 'active',
    }, { cancelRefetch: false });
  }

  setRealtimeLastUpdatedAt(Date.now());
}

export async function invalidateRealtimeChange(
  queryClient: RealtimeInvalidationClient,
  change: RealtimeChangeRef,
  additionalPrefixes: QueryKey[] = [],
  excludedExactKeys: QueryKey[] = [],
): Promise<void> {
  await invalidateRealtimeChanges(queryClient, [change], additionalPrefixes, excludedExactKeys);
}

/** Invalidate multiple related record changes in one cache traversal. */
export async function invalidateRealtimeChanges(
  queryClient: RealtimeInvalidationClient,
  changes: readonly RealtimeChangeRef[],
  additionalPrefixes: QueryKey[] = [],
  excludedExactKeys: QueryKey[] = [],
): Promise<void> {
  const split = splitSearchInvalidation(uniqueKeys([
    ...changes.flatMap(queryKeysForRealtimeChange),
    ...additionalPrefixes,
  ]));
  if (split.search) scheduleCommandPaletteSearchInvalidation(queryClient);
  await invalidatePrefixesOnce(queryClient, split.ordinary, excludedExactKeys);
}

/**
 * Reconcile a successful full-record mutation response for the actor, then
 * refresh the same record's mapped active families for every other view.
 * The exact response key is excluded from invalidation so an older read cannot
 * replace the server response the actor just received.
 */
export function reconcileRealtimeMutationResponse<TRecord>(
  queryClient: QueryClient,
  change: RealtimeChangeRef,
  exactQueryKey: QueryKey,
  responseRecord: TRecord,
  additionalPrefixes: QueryKey[] = [],
): void {
  queryClient.setQueryData(exactQueryKey, responseRecord);
  void invalidateRealtimeChange(queryClient, change, additionalPrefixes, [exactQueryKey]);
}

/** Invalidate the query families registered for a non-record signal. */
export async function invalidateRealtimeSignal(
  queryClient: RealtimeInvalidationClient,
  signal: RealtimeSignal,
  excludedFamilies: QueryKey[] = [],
  excludedExactKeys: QueryKey[] = [],
): Promise<void> {
  const excluded = new Set(excludedFamilies.map((key) => JSON.stringify(key)));
  const split = splitSearchInvalidation(queryKeysForRealtimeSignal(signal)
    .filter((key) => !excluded.has(JSON.stringify(key))));
  if (split.search) scheduleCommandPaletteSearchInvalidation(queryClient);
  await invalidatePrefixesOnce(queryClient, split.ordinary, excludedExactKeys);
}

/** Coalesce record and related-entity pings without delaying actor API responses. */
export function scheduleRealtimeChangeInvalidation(
  queryClient: RealtimeQueryClient,
  change: RealtimeChangeRef,
  additionalPrefixes: QueryKey[] = [],
): void {
  const primaryDetailKey = [
    ...realtimeInvalidationMap[change.entityType].detailPrefix,
    change.entityId,
  ];
  const prefixes = uniqueKeys([
    ...queryKeysForRealtimeChange(change),
    ...additionalPrefixes,
  ]);
  // Detail and explicitly supplied record queries carry status and available
  // actions, so refresh them first. Lists, counts and dashboards stay
  // coalesced in the near-live lane; their heavier reads must not delay the
  // action the user is currently viewing.
  const priorityKeys = uniqueKeys([primaryDetailKey, ...additionalPrefixes]);
  const relatedPrefixes = prefixes.filter((key) =>
    !priorityKeys.some((priorityKey) =>
      priorityKey.length === key.length && keyStartsWith(key, priorityKey),
    ),
  );

  scheduleInvalidation(
    queryClient,
    priorityKeys,
    `detail:${change.entityType}`,
  );
  scheduleInvalidation(
    queryClient,
    relatedPrefixes,
    'related',
    priorityKeys,
    REALTIME_NEAR_LIVE_INVALIDATION_DELAY_MS,
  );
}
