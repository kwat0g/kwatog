import { useCallback, useMemo } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import {
  invalidateRealtimeChange,
  realtimeInvalidationMap,
  scheduleRealtimeChangeInvalidation,
  scheduleRealtimeCountInvalidation,
  scheduleRealtimeListInvalidation,
  scheduleRealtimeQueryInvalidation,
  realtimeEventNameForEntity,
  type RealtimeEntityType,
} from '@/lib/realtime/invalidationMap';
import { useAuthStore } from '@/stores/authStore';
import { useFeature } from '@/hooks/useFeature';
import { useRealtimeEvent } from './useRealtimeEvent';

const REALTIME_LIST_CHANNEL_ENTITIES: Partial<Record<RealtimeEntityType, string>> = {
  import_batch: 'admin_import_batch',
  product_routing: 'production_routing',
};

function realtimeEntityListChannel(entityType: RealtimeEntityType, scopeId: string): string {
  return `entity.${REALTIME_LIST_CHANNEL_ENTITIES[entityType] ?? entityType}.${scopeId}`;
}

export function useRealtimeRecord(
  entityType: RealtimeEntityType,
  entityId: string | undefined,
  additionalQueryKeys: ReadonlyArray<ReadonlyArray<unknown>> = [],
  onEvent?: (payload?: unknown) => void,
): void {
  const queryClient = useQueryClient();
  const user = useAuthStore((state) => state.user);
  const permissions = useAuthStore((state) => state.permissions);
  const additionalSignature = JSON.stringify(additionalQueryKeys);
  const keys = useMemo(() => JSON.parse(additionalSignature) as unknown[][], [additionalSignature]);
  const onChanged = useCallback((payload?: unknown) => {
    if (!entityId) return;
    if (payload !== undefined) onEvent?.(payload);
    const relatedPayload = typeof payload === 'object' && payload !== null && 'related' in payload
      ? (payload as { related?: unknown }).related
      : null;
    const related = Array.isArray(relatedPayload)
      ? relatedPayload.flatMap((item) => {
        if (typeof item !== 'object' || item === null) return [];
        const value = item as { entity_type?: unknown; entityType?: unknown; hash_id?: unknown; entityId?: unknown };
        const relatedType = value.entity_type ?? value.entityType;
        const relatedId = value.hash_id ?? value.entityId;
        return typeof relatedType === 'string'
          && Object.hasOwn(realtimeInvalidationMap, relatedType)
          && typeof relatedId === 'string'
          ? [{ entityType: relatedType as RealtimeEntityType, entityId: relatedId }]
          : [];
      })
      : [];
    scheduleRealtimeChangeInvalidation(queryClient, { entityType, entityId, related }, keys);
  }, [entityId, entityType, keys, onEvent, queryClient]);

  const channel = !entityId
    ? ''
    : entityType === 'admin_user'
      ? `admin.user.${entityId}`
    : entityType === 'role'
      ? `admin.role.${entityId}`
    : entityType === 'product'
      ? `crm.product.${entityId}`
    : entityType === 'customer'
      ? `accounting.customer.${entityId}`
    : entityType === 'vendor'
      ? `accounting.vendor.${entityId}`
    : entityType === 'demand_forecast'
      ? `forecast.demand-forecast.${entityId}`
    : entityType === 'price_agreement'
      ? `crm.price-agreement.${entityId}`
    : entityType === 'price_request'
      ? `crm.price-request.${entityId}`
    : entityType === 'stock_count_session'
      ? `inventory.stock-count-session.${entityId}`
    : entityType === 'material_review_record'
      ? `inventory.mrb.${entityId}`
    : entityType === 'material_issue_slip'
      ? `inventory.material-issue.${entityId}`
    : entityType === 'transfer_order'
      ? `inventory.transfer-order.${entityId}`
    : entityType === 'item'
      ? `inventory.item.${entityId}`
    : entityType === 'asset'
      ? `asset.${entityId}`
    : entityType === 'shift'
      ? `shift.${entityId}`
    : entityType === 'position'
      ? `position.${entityId}`
    : entityType === 'employee_shift_assignment'
      ? `shift-assignment.${entityId}`
    : entityType === 'employee_onboarding'
      ? `employee.onboarding.${entityId}`
    : entityType === 'delivery_schedule'
      ? `delivery-schedule.${entityId}`
    : entityType === 'employee_loan'
      ? `employee-loan.${entityId}`
    : entityType === 'journal_entry'
      ? `accounting.journal-entry.${entityId}`
    : entityType === 'scheduled_export'
      ? `scheduled-export.${entityId}`
    : entityType === 'credit_note'
      ? `accounting.credit-note.${entityId}`
    : entityType === 'account'
      ? `accounting.account.${entityId}`
    : entityType === 'budget_transfer'
      ? `accounting.budget-transfer.${entityId}`
    : entityType === 'budget'
      ? `accounting.budget.${entityId}`
    : entityType === 'employee_training'
      ? `employee_training.${entityId}`
    : entityType === 'employee'
      ? `employee.${entityId}`
    : entityType === 'employee_document'
      ? `employee.documents.${entityId}`
    : entityType === 'employee_skill'
      ? `employee.skills.${entityId}`
    : entityType === 'profile_update_request'
      ? `profile-update-request.${entityId}`
    : entityType === 'job_posting' || entityType === 'job_application'
      ? `${entityType}.${entityId}`
    : entityType === 'skill'
      ? `skill.${entityId}`
    : entityType === 'salary_adjustment'
      ? `hr.salary-adjustment.${entityId}`
    : entityType === 'training'
      ? `training.${entityId}`
    : entityType === 'leave_request'
      ? `leave-request.${entityId}`
    : entityType === 'overtime_request'
      ? `overtime-request.${entityId}`
    : entityType === 'request_for_quote'
      ? `rfq.${entityId}`
      : entityType === 'inspection'
        ? `inspection.${entityId}`
      : entityType === 'ncr'
        ? `ncr.${entityId}`
      : entityType === 'calibration' || entityType === 'ppap' || entityType === 'inspection_spec' || entityType === 'ncr_template' || entityType === 'quality_plan'
        ? `${entityType}.${entityId}`
      : entityType === 'shipment_lot'
        ? `shipment_lot.${entityId}`
      : entityType === 'shipment'
        ? `shipment.${entityId}`
      : entityType === 'vehicle'
        ? `vehicle.${entityId}`
      : entityType === 'return_request'
        ? `return_request.${entityId}`
      : entityType === 'return_case'
        ? `return_case.${entityId}`
      : entityType === 'maintenance_work_order'
        ? `maintenance_work_order.${entityId}`
      : entityType === 'maintenance_schedule'
        ? `maintenance_schedule.${entityId}`
      : entityType === 'customer_complaint'
        ? `customer_complaint.${entityId}`
      : entityType === 'production_schedule'
        ? `production_schedule.${entityId}`
      : entityType === 'product_routing'
        ? `production.routing.${entityId}`
      : entityType === 'machine'
        ? `production.machine.${entityId}`
      : entityType === 'mold'
        ? `production.mold.${entityId}`
      : entityType === 'bom'
        ? `production.bom.${entityId}`
      : entityType === 'mrp_plan'
        ? `mrp.plan.${entityId}`
      : entityType === 'clearance'
        ? `clearance.${entityId}`
      : entityType === 'payroll_adjustment'
        ? `payroll.adjustment.${entityId}`
      : entityType === 'payroll_period'
        ? `payroll.period.${entityId}`
      : `chain.${entityType}.${entityId}`;
  useRealtimeEvent(channel, realtimeEventNameForEntity(entityType), onChanged, onChanged, onChanged);

  useRealtimeEvent(
    entityType === 'employee_onboarding' && entityId ? `employee.onboarding.${entityId}` : '',
    '.attendance.shift-assignment.changed',
    onChanged,
    onChanged,
    onChanged,
  );

  // Coalesced catalog, directory, and recompute batches emit aggregate list
  // signals without individual record IDs. Open detail viewers also listen
  // there so they catch up when their row is part of a batch.
  useRealtimeEvent(
    ['demand_forecast', 'customer', 'product_routing', 'item', 'admin_user'].includes(entityType) && entityId
      ? realtimeEntityListChannel(entityType, 'visible')
      : '',
    realtimeEventNameForEntity(entityType),
    onChanged,
    onChanged,
    onChanged,
  );

  // Output recordings use the existing permission-checked production.wo
  // channel. Route them through the same central map as lifecycle pings so
  // every active work-order detail/linked query stays aligned.
  useRealtimeEvent(
    entityType === 'work_order' && entityId ? `production.wo.${entityId}` : '',
    '.output.recorded',
    onChanged,
    onChanged,
    onChanged,
  );

  // Child status/output changes affect the parent's subassembly readiness.
  useRealtimeEvent(
    entityType === 'work_order' && entityId ? `chain.work_order.${entityId}` : '',
    '.work_order.status_changed',
    onChanged,
    onChanged,
    onChanged,
  );
  useRealtimeEvent(
    entityType === 'work_order' && entityId ? `chain.work_order.${entityId}` : '',
    '.output.recorded',
    onChanged,
    onChanged,
    onChanged,
  );

  // Attendance has its own API-scoped channels. Assigned work-order viewers
  // receive a content-free readiness refresh on the exact work-order channel.
  useRealtimeEvent(
    entityType === 'work_order' && entityId ? `chain.work_order.${entityId}` : '',
    '.attendance.changed',
    onChanged,
    onChanged,
    onChanged,
  );

  // Global calendar changes can alter crew availability for any open WO.
  useRealtimeEvent(
    entityType === 'work_order' && permissions.has('production.work_orders.lifecycle')
      ? 'production.readiness'
      : '',
    '.attendance.holiday.changed',
    onChanged,
    onChanged,
    onChanged,
  );

  // Finished-goods locations gate production execution readiness. This channel
  // is permission-scoped to the same lifecycle API and carries only a refresh ping.
  useRealtimeEvent(
    entityType === 'work_order' && permissions.has('production.work_orders.lifecycle')
      ? 'production.readiness'
      : '',
    '.inventory.warehouse-layout.changed',
    onChanged,
    onChanged,
    onChanged,
  );

  // Linked resource changes also refresh supervisors viewing readiness, even
  // when no active operator account exists for the assigned crew.
  useRealtimeEvent(
    entityType === 'work_order' && entityId ? `chain.work_order.${entityId}` : '',
    '.assigned.work_order.changed',
    onChanged,
    onChanged,
    onChanged,
  );

  // Stock reservations affect material readiness shown on these records.
  // Join the matching authorized entity-list channel only when this viewer
  // can read that list; the refresh payload itself contains no stock data.
  const canReadEntityList = user?.role?.slug === 'system_admin'
    || (entityType === 'work_order'
      ? permissions.has('production.view') && permissions.has('production.work_orders.view')
      : entityType === 'maintenance_work_order'
        ? permissions.has('maintenance.view')
        : entityType === 'ncr'
          ? permissions.has('quality.ncr.view')
          : false);
  useRealtimeEvent(
    entityId && canReadEntityList && ['work_order', 'maintenance_work_order', 'ncr'].includes(entityType)
      ? `entity.${entityType}.visible`
      : '',
    '.InventoryStockChanged',
    onChanged,
    onChanged,
    onChanged,
  );
  useRealtimeEvent(
    entityType === 'work_order' && entityId ? `chain.work_order.${entityId}` : '',
    '.production.late-report.changed',
    onChanged,
    onChanged,
    onChanged,
  );
}

/** Maintainers receive the module list; assigned employees receive their own scoped list signal. */
export function useMaintenanceWorkOrderListRealtime(canViewAll: boolean): void {
  const queryClient = useQueryClient();
  const userId = useAuthStore((state) => state.user?.id);
  const refresh = useCallback(() => {
    scheduleRealtimeQueryInvalidation(queryClient, [['maintenance', 'work-orders']]);
  }, [queryClient]);

  useRealtimeList('maintenance_work_order', canViewAll ? 'visible' : undefined);
  useRealtimeEvent(
    !canViewAll && userId ? `maintenance.user.${userId}` : '',
    '.maintenance.work-order.changed',
    refresh,
    refresh,
    refresh,
  );
}

/** Maintenance role dashboards refetch their permission-scoped widget data on work-order changes. */
export function useMaintenanceDashboardRealtime(): void {
  const queryClient = useQueryClient();
  const refresh = useCallback(() => {
    scheduleRealtimeQueryInvalidation(queryClient, [['dashboard', 'widget-data'], ['kpi']]);
  }, [queryClient]);

  useRealtimeEvent('maintenance.dashboard', '.maintenance.work-order.changed', refresh, refresh, refresh);
  useRealtimeEvent('maintenance.dashboard', '.maintenance.schedule.changed', refresh, refresh, refresh);
}

/** Clearance lists are invalidated only for users whose API scope includes the changed clearance. */
export function useClearanceListRealtime(): void {
  const queryClient = useQueryClient();
  const userId = useAuthStore((state) => state.user?.id);
  const refresh = useCallback(() => {
    scheduleRealtimeListInvalidation(queryClient, 'clearance');
  }, [queryClient]);

  useRealtimeEvent(
    userId ? `hr.clearance.user.${userId}` : '',
    '.hr.clearance.changed',
    refresh,
    refresh,
    refresh,
  );
}

/** Portal cases use their separate customer/vendor guards and company channels. */
export function useReturnCasePortalRealtime(
  realm: 'customer' | 'supplier' | undefined,
  scopeId: string | undefined,
): void {
  const queryClient = useQueryClient();
  const refresh = useCallback((signal?: { hash_id?: string }) => {
    if (!realm) return;
    if (signal?.hash_id) {
      void invalidateRealtimeChange(queryClient, { entityType: 'return_case', entityId: signal.hash_id });
      return;
    }
    scheduleRealtimeListInvalidation(queryClient, 'return_case');
  }, [queryClient, realm]);

  const channel = !realm || !scopeId
    ? ''
    : realm === 'customer'
      ? `customer.account.${scopeId}`
      : `supplier.vendor.${scopeId}`;
  useRealtimeEvent(channel, '.return.case.refresh', refresh, refresh, () => refresh());
}

/**
 * Keep active Return Case source searches and quantity previews current.
 * Internal source pings reuse the same return-management list authorization;
 * portal records continue through their customer/vendor-scoped channels.
 */
export function useReturnCaseSourceRealtime(
  realm: 'internal' | 'customer' | 'supplier' | undefined,
  portalScopeId: string | undefined,
  internalScope: 'customer' | 'all' | undefined,
): void {
  const queryClient = useQueryClient();
  const listScope = realm === 'internal' ? internalScope : undefined;
  useRealtimeList('return_case', listScope);
  useRealtimeList('return_request', listScope);

  const refreshSourceQueries = useCallback(() => {
    scheduleRealtimeListInvalidation(queryClient, 'return_case');
  }, [queryClient]);
  const sourceChannel = listScope ? `entity.return_case.${listScope}` : '';
  useRealtimeEvent(
    sourceChannel,
    '.chain.refresh',
    refreshSourceQueries,
    refreshSourceQueries,
    refreshSourceQueries,
  );

  useReturnCasePortalRealtime(
    realm === 'customer' || realm === 'supplier' ? realm : undefined,
    portalScopeId,
  );
}

/** List channel hook for an entity and server-derived authorization scope. */
export function useRealtimeList(entityType: RealtimeEntityType, scopeId: string | undefined): void {
  const queryClient = useQueryClient();
  const onChanged = useCallback(() => {
    if (!scopeId) return;
    scheduleRealtimeListInvalidation(queryClient, entityType);
  }, [entityType, queryClient, scopeId]);

  const channel = scopeId ? realtimeEntityListChannel(entityType, scopeId) : '';

  useRealtimeEvent(
    channel,
    realtimeEventNameForEntity(entityType),
    onChanged,
    onChanged,
    onChanged,
  );

  // Production output changes progress values without changing the work-order
  // status. Refresh authorized list viewers from the same private entity channel.
  useRealtimeEvent(
    entityType === 'work_order' && scopeId ? `entity.${entityType}.${scopeId}` : '',
    '.output.recorded',
    onChanged,
    onChanged,
    onChanged,
  );
  // AttendanceChanged also targets this permission-checked list channel when
  // an active work order's crew is affected. Production viewers need not have
  // Attendance permission to receive the minimal readiness refresh signal.
  useRealtimeEvent(
    entityType === 'work_order' && scopeId ? `entity.${entityType}.${scopeId}` : '',
    '.attendance.changed',
    onChanged,
    onChanged,
    onChanged,
  );
  useRealtimeEvent(
    entityType === 'work_order' && scopeId ? `entity.${entityType}.${scopeId}` : '',
    '.production.late-report.changed',
    onChanged,
    onChanged,
    onChanged,
  );
  useRealtimeEvent(
    scopeId && ['work_order', 'maintenance_work_order', 'ncr'].includes(entityType)
      ? `entity.${entityType}.${scopeId}`
      : '',
    '.InventoryStockChanged',
    onChanged,
    onChanged,
    onChanged,
  );
}

/** Employee list refreshes follow the current user's server-derived row scope. */
export function useEmployeeListRealtime(): void {
  const queryClient = useQueryClient();
  const user = useAuthStore((state) => state.user);
  const canViewAll = useAuthStore((state) => state.permissions.has('hr.employees.view_sensitive'));
  const canViewEmployees = useAuthStore((state) => state.permissions.has('hr.employees.view'));
  const hrEnabled = useFeature('hr');
  const refresh = useCallback(() => {
    if (!hrEnabled || !canViewEmployees) return;
    scheduleRealtimeListInvalidation(queryClient, 'employee');
  }, [canViewEmployees, hrEnabled, queryClient]);

  useRealtimeEvent(
    hrEnabled && canViewEmployees && canViewAll ? 'entity.employee.visible' : '',
    '.hr.employee.changed',
    refresh,
    refresh,
    refresh,
  );
  useRealtimeEvent(
    hrEnabled && canViewEmployees && !canViewAll && user?.employee?.department_id
      ? `entity.employee.department.${user.employee.department_id}`
      : '',
    '.hr.employee.changed',
    refresh,
    refresh,
    refresh,
  );
  useRealtimeEvent(
    hrEnabled && user?.id ? `employee.user.${user.id}` : '',
    '.hr.employee.changed',
    refresh,
    refresh,
    refresh,
  );
}

/** Catalog edits also refresh competency readers who cannot open the catalog. */
export function useSkillCatalogRealtime(): void {
  const queryClient = useQueryClient();
  const permissions = useAuthStore((state) => state.permissions);
  const hrEnabled = useFeature('hr');
  const canViewCatalog = permissions.has('hr.trainings.view');
  const canViewEmployeeSkills = permissions.has('hr.employees.trainings.view');
  const refresh = useCallback((payload?: unknown) => {
    const entityId = typeof payload === 'object' && payload !== null && 'entity_id' in payload
      ? (payload as { entity_id?: unknown }).entity_id
      : undefined;
    if (typeof entityId === 'string') {
      scheduleRealtimeChangeInvalidation(queryClient, { entityType: 'skill', entityId });
      return;
    }
    scheduleRealtimeListInvalidation(queryClient, 'skill');
  }, [queryClient]);

  useRealtimeEvent(
    hrEnabled && canViewCatalog ? 'entity.skill.visible' : '',
    '.hr.skill.changed',
    refresh,
    refresh,
    refresh,
  );
  useRealtimeEvent(
    hrEnabled && canViewEmployeeSkills ? 'entity.employee_skill.visible' : '',
    '.hr.skill.changed',
    refresh,
    refresh,
    refresh,
  );
}

/** HR profile queues use department scope; Finance and employees have separate private refresh audiences. */
export function useProfileUpdateRequestRealtime(): void {
  const queryClient = useQueryClient();
  const user = useAuthStore((state) => state.user);
  const permissions = useAuthStore((state) => state.permissions);
  const hrEnabled = useFeature('hr');
  const canViewEmployees = permissions.has('hr.employees.view');
  const canViewAll = canViewEmployees && permissions.has('hr.employees.view_sensitive');
  const canReviewFinance = permissions.has('hr.profile_updates.finance_review');
  const departmentId = user?.employee?.department_id;

  const refresh = useCallback((payload?: unknown) => {
    scheduleRealtimeListInvalidation(queryClient, 'profile_update_request');
    if (typeof payload !== 'object' || payload === null || !('entity_id' in payload)) return;

    const value = payload as { entity_id?: unknown; related?: unknown };
    if (typeof value.entity_id !== 'string') return;
    const related = Array.isArray(value.related)
      ? value.related.flatMap((item) => {
        if (typeof item !== 'object' || item === null) return [];
        const ref = item as { entity_type?: unknown; hash_id?: unknown };
        return typeof ref.entity_type === 'string'
          && Object.hasOwn(realtimeInvalidationMap, ref.entity_type)
          && typeof ref.hash_id === 'string'
          ? [{ entityType: ref.entity_type as RealtimeEntityType, entityId: ref.hash_id }]
          : [];
      })
      : [];
    scheduleRealtimeChangeInvalidation(queryClient, {
      entityType: 'profile_update_request',
      entityId: value.entity_id,
      related,
    });
  }, [queryClient]);

  const eventName = '.hr.profile-update-request.changed';
  useRealtimeEvent(
    hrEnabled && canViewAll ? 'entity.profile_update_request.hr.all' : '',
    eventName,
    refresh,
    refresh,
    refresh,
  );
  useRealtimeEvent(
    hrEnabled && canViewEmployees && !canViewAll && departmentId
      ? `entity.profile_update_request.hr.department.${departmentId}`
      : '',
    eventName,
    refresh,
    refresh,
    refresh,
  );
  useRealtimeEvent(
    hrEnabled && canReviewFinance ? 'entity.profile_update_request.finance' : '',
    eventName,
    refresh,
    refresh,
    refresh,
  );
  useRealtimeEvent(
    hrEnabled && user?.id ? `profile-update-request.user.${user.id}` : '',
    eventName,
    refresh,
    refresh,
    refresh,
  );
}

/** Catch up the HR current-shift and self-service shift snapshots. */
export function useEmployeeShiftRealtime(employeeHashId: string | undefined): void {
  const queryClient = useQueryClient();
  const user = useAuthStore((state) => state.user);
  const permissions = useAuthStore((state) => state.permissions);
  const attendanceEnabled = useFeature('attendance');
  const mayReadAnyEmployeeShift = permissions.has('attendance.edit')
    || permissions.has('attendance.shifts.manage');
  const isOwnEmployee = Boolean(employeeHashId && user?.employee?.id === employeeHashId);
  const refreshEmployee = useCallback(() => {
    if (!employeeHashId) return;
    scheduleRealtimeListInvalidation(queryClient, 'employee_shift_assignment');
  }, [employeeHashId, queryClient]);
  const refreshShift = useCallback(() => {
    scheduleRealtimeListInvalidation(queryClient, 'shift');
  }, [queryClient]);

  useRealtimeEvent(
    attendanceEnabled && employeeHashId && (isOwnEmployee || mayReadAnyEmployeeShift)
      ? `attendance.employee-shift.${employeeHashId}`
      : '',
    '.attendance.shift-assignment.changed',
    refreshEmployee,
    refreshEmployee,
    refreshEmployee,
  );
  useRealtimeEvent(
    attendanceEnabled && mayReadAnyEmployeeShift ? 'entity.shift.visible' : '',
    '.attendance.shift.changed',
    refreshShift,
    refreshShift,
    refreshShift,
  );
  useRealtimeEvent(
    attendanceEnabled && isOwnEmployee ? 'entity.shift.employee' : '',
    '.attendance.shift.changed',
    refreshShift,
    refreshShift,
    refreshShift,
  );
}

/** Loan list refreshes are permission-gated; every refetch retains API row scope. */
export function useEmployeeLoanListRealtime(): void {
  const queryClient = useQueryClient();
  const permissions = useAuthStore((state) => state.permissions);
  const loansEnabled = useFeature('loans');
  const refresh = useCallback(() => {
    if (!loansEnabled || !permissions.has('loans.view')) return;
    scheduleRealtimeListInvalidation(queryClient, 'employee_loan');
  }, [loansEnabled, permissions, queryClient]);

  useRealtimeEvent(
    loansEnabled && permissions.has('loans.view') ? 'entity.employee_loan.visible' : '',
    '.loan.changed',
    refresh,
    refresh,
    refresh,
  );
}

/** Exact-user channel refresh for the authenticated employee's self-service loan data. */
export function usePersonalLoanRealtime(userHashId: string | undefined): void {
  const queryClient = useQueryClient();
  const hrEnabled = useFeature('hr');
  const refresh = useCallback(() => {
    if (!userHashId || !hrEnabled) return;
    scheduleRealtimeListInvalidation(queryClient, 'employee_loan');
  }, [hrEnabled, queryClient, userHashId]);

  useRealtimeEvent(
    hrEnabled && userHashId ? `loan.user.${userHashId}` : '',
    '.loan.changed',
    refresh,
    refresh,
    refresh,
  );
}

/** Product detail BOM summaries share the API permission and product scope of the BOM endpoint. */
export function useRealtimeProductBom(productId: string | undefined, enabled: boolean): void {
  const queryClient = useQueryClient();
  const onChanged = useCallback(() => {
    if (!productId) return;
    scheduleRealtimeChangeInvalidation(queryClient, { entityType: 'product', entityId: productId });
  }, [productId, queryClient]);

  useRealtimeEvent(
    productId && enabled ? `mrp.product-bom.${productId}` : '',
    '.chain.refresh',
    onChanged,
    onChanged,
    onChanged,
  );
}

/** Vendor-private signal for the supplier portal's own submitted listings. */
export function useSupplierListingRealtime(vendorHashId: string | undefined): void {
  const queryClient = useQueryClient();
  const refresh = useCallback(() => {
    if (!vendorHashId) return;
    scheduleRealtimeListInvalidation(queryClient, 'supplier_listing');
  }, [queryClient, vendorHashId]);

  useRealtimeEvent(
    vendorHashId ? `supplier.vendor.${vendorHashId}` : '',
    '.supplier.catalog.changed',
    refresh,
    refresh,
    refresh,
  );
}

/** Refresh this user's permission-scoped counters from their private signal. */
export function useRealtimeBadgeInvalidation(entityType: RealtimeEntityType): void {
  const queryClient = useQueryClient();
  const userId = useAuthStore((state) => state.user?.id);
  const onChanged = useCallback(() => {
    scheduleRealtimeListInvalidation(queryClient, entityType);
  }, [entityType, queryClient]);

  useRealtimeEvent(userId ? `badge-user.${userId}` : '', '.BadgesChanged', onChanged, onChanged, onChanged);
}

/** Per-user count/inbox channel; no count data is carried over the socket. */
export function useRealtimeCounts(entityType: RealtimeEntityType, userId: string | undefined): void {
  const queryClient = useQueryClient();
  const onChanged = useCallback(() => {
    scheduleRealtimeCountInvalidation(queryClient, entityType);
  }, [entityType, queryClient]);

  useRealtimeEvent(userId ? `user.${userId}` : '', '.counts.changed', onChanged, onChanged, onChanged);
}
