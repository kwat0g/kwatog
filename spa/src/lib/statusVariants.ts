import { type ChipVariant } from '@/components/ui/Chip';
import type { SalesOrderStatus, ComplaintStatus } from '@/types/crm';
import type { BillStatus, InvoiceStatus } from '@/types/accounting';
import type { Budget, BudgetTransfer } from '@/types/budgeting';
import type { AttendanceStatus } from '@/types/attendance';
import type { RecommendationStatus } from '@/types/dss';
import type { ApplicationStage } from '@/types/recruitment';
import type { PayrollAdjustmentStatusValue } from '@/types/payroll';
import type { PurchaseOrderStatus, PurchaseRequestStatus } from '@/types/purchasing';
import type { GrnStatus } from '@/types/inventory';
import type { LeaveStatus } from '@/types/leave';
import type { EmployeeStatus } from '@/types/hr';
import type { InspectionStatus } from '@/types/quality';
import type { ClearanceStatus } from '@/types/separations';
import type { MachineStatus, MoldStatus } from '@/types/mrp';
import type { ReturnCaseStatus } from '@/types/returnCases';
import type { WorkOrderStatus } from '@/types/production';
import type { DeliveryStatus, ShipmentStatus } from '@/types/supplyChain';
import type { MaintenancePriority, MaintenanceWorkOrderStatus } from '@/types/maintenance';

type StatusVariants<Status extends string> = Partial<Record<string, ChipVariant>> & Record<Status, ChipVariant>;

/**
 * Shared status colors keep each workflow distinct across tables and details.
 * Typed maps make a new status a compile-time decision instead of a gray fallback.
 */

export const salesOrderStatusVariant: StatusVariants<SalesOrderStatus> = {
 draft: 'category-1',
 awaiting_confirmation: 'category-2',
 delivery_negotiation: 'category-3',
 confirmed: 'category-4',
 in_production: 'category-5',
 partially_delivered: 'category-6',
 delivered: 'category-7',
 invoiced: 'category-8',
 paid: 'success',
 closed: 'neutral',
 cancelled: 'danger',
};

/** Production work orders — floor tablet (`/factory`) + planner table. */
export const workOrderStatusVariant: StatusVariants<WorkOrderStatus> = {
 planned: 'category-1',
 confirmed: 'category-2',
 in_progress: 'category-3',
 paused: 'warning',
 completed: 'success',
 closed: 'neutral',
 cancelled: 'danger',
};

/**
 * Deliveries — driver PWA + supply-chain table. `DriverDeliveryStatus` is a
 * structurally identical union, so the driver pages index this with theirs.
 */
export const deliveryStatusVariant: StatusVariants<DeliveryStatus> = {
 scheduled: 'neutral',
 loading: 'category-1',
 in_transit: 'category-2',
 return_pending: 'category-3',
 returned: 'category-4',
 delivered: 'category-5',
 confirmed: 'success',
 cancelled: 'danger',
};

/** Maintenance work orders — tech PWA + maintenance table. */
export const maintenanceStatusVariant: Record<MaintenanceWorkOrderStatus, ChipVariant> = {
 open: 'category-1',
 assigned: 'category-2',
 in_progress: 'category-3',
 completed: 'success',
 cancelled: 'danger',
};

export const complaintStatusVariant: StatusVariants<ComplaintStatus> = {
 open: 'category-1',
 investigating: 'category-2',
 resolved: 'category-3',
 closed: 'success',
 cancelled: 'danger',
};

export const recommendationStatusVariant: Record<RecommendationStatus, ChipVariant> = {
 open: 'category-1',
 in_progress: 'category-2',
 accepted: 'category-3',
 dismissed: 'category-4',
 snoozed: 'category-5',
 expired: 'category-6',
 executed: 'category-7',
 resolved: 'success',
};

export const attendanceStatusVariant: StatusVariants<AttendanceStatus> = {
 present: 'success',
 late: 'category-1',
 halfday: 'category-2',
 absent: 'danger',
 on_leave: 'category-3',
 holiday: 'category-4',
 rest_day: 'neutral',
};

export const budgetStatusVariant: Record<Budget['status'], ChipVariant> = {
 draft: 'category-1',
 submitted: 'category-2',
 approved: 'category-3',
 active: 'success',
 closed: 'neutral',
};

export const budgetTransferStatusVariant: Record<BudgetTransfer['status'], ChipVariant> = {
 pending: 'category-1',
 approved: 'success',
 rejected: 'danger',
};

export const payrollAdjustmentStatusVariant: Record<PayrollAdjustmentStatusValue, ChipVariant> = {
 pending: 'category-1',
 approved: 'category-2',
 applied: 'success',
 rejected: 'danger',
};

export const applicationStageVariant: Record<ApplicationStage, ChipVariant> = {
 new: 'category-1',
 screening: 'category-2',
 interview: 'category-3',
 offer: 'category-4',
 hired: 'success',
 rejected: 'danger',
};

export const clearanceStatusVariant: Record<ClearanceStatus, ChipVariant> = {
 pending: 'category-1',
 in_progress: 'category-2',
 completed: 'category-3',
 finalized: 'success',
 cancelled: 'danger',
};

export const inspectionStatusVariant: Record<InspectionStatus, ChipVariant> = {
 draft: 'category-1',
 in_progress: 'category-2',
 awaiting_review: 'category-3',
 passed: 'success',
 failed: 'danger',
 cancelled: 'neutral',
};

export const shipmentStatusVariant: Record<ShipmentStatus, ChipVariant> = {
 ordered: 'category-1',
 shipped: 'category-2',
 in_transit: 'category-3',
 customs: 'category-4',
 cleared: 'category-5',
 received: 'success',
 cancelled: 'danger',
};

export const machineStatusVariant: Record<MachineStatus, ChipVariant> = {
 running: 'success',
 idle: 'category-1',
 maintenance: 'category-2',
 breakdown: 'danger',
 offline: 'neutral',
};

export const moldStatusVariant: Record<MoldStatus, ChipVariant> = {
 available: 'success',
 in_use: 'category-1',
 maintenance: 'category-2',
 retired: 'neutral',
};

export const leaveStatusVariant: Record<LeaveStatus, ChipVariant> = {
 pending_dept: 'category-1',
 pending_hr: 'category-2',
 approved: 'success',
 rejected: 'danger',
 cancelled: 'neutral',
};

export const employeeStatusVariant: Record<EmployeeStatus, ChipVariant> = {
 active: 'success',
 on_leave: 'category-1',
 suspended: 'category-2',
 resigned: 'category-3',
 terminated: 'danger',
 retired: 'neutral',
};

export const invoiceStatusVariant: Record<string, ChipVariant> &
  Record<InvoiceStatus | 'unpaid' | 'overdue' | 'awaiting prebill approval' | 'awaiting payment verification', ChipVariant> = {
 draft: 'category-1',
 finalized: 'category-2',
 unpaid: 'category-3',
 partial: 'category-4',
 overdue: 'category-5',
 paid: 'success',
 cancelled: 'danger',
 'awaiting prebill approval': 'category-6',
 'awaiting payment verification': 'category-7',
};

export const supplierInvoiceStatusVariant: Record<string, ChipVariant> = {
 submitted: 'category-1',
 unpaid: 'category-2',
 partial: 'category-3',
 requires_correction: 'category-4',
 paid: 'success',
 voided: 'danger',
};

export const billStatusVariant: StatusVariants<BillStatus> = {
 draft: 'category-1',
 unpaid: 'category-2',
 partial: 'category-3',
 paid: 'success',
 cancelled: 'danger',
};

export const purchaseOrderStatusVariant: StatusVariants<PurchaseOrderStatus> = {
 draft: 'category-1',
 pending_approval: 'category-2',
 approved: 'category-3',
 sent: 'category-4',
 acknowledged: 'category-5',
 supplier_proposed: 'category-6',
 supplier_declined: 'danger',
 partially_received: 'category-7',
 received: 'success',
 closed: 'neutral',
 cancelled: 'category-8',
};

export const purchaseRequestStatusVariant: Record<PurchaseRequestStatus, ChipVariant> = {
 draft: 'category-1',
 pending: 'category-2',
 approved: 'success',
 rejected: 'danger',
 converted: 'category-3',
 cancelled: 'neutral',
};

export const grnStatusVariant: StatusVariants<GrnStatus> = {
 draft: 'category-1',
 pending_qc: 'category-2',
 accepted: 'success',
 partial_accepted: 'category-3',
 rejected: 'danger',
};

export const returnRequestStatusVariant: Record<string, ChipVariant> = {
 draft: 'category-1',
 pending_approval: 'category-2',
 approved: 'category-3',
 received: 'category-4',
 inspected: 'category-5',
 completed: 'success',
 rejected: 'danger',
 cancelled: 'neutral',
};

export const returnCaseStatusVariant: StatusVariants<ReturnCaseStatus> = {
 submitted: 'category-1',
 under_review: 'category-2',
 information_needed: 'category-3',
 action_agreed: 'category-4',
 in_progress: 'category-5',
 resolved: 'success',
 rejected: 'danger',
 withdrawn: 'neutral',
};

/** Maintenance priority — tech PWA + maintenance table. */
export const maintenancePriorityVariant: Record<MaintenancePriority, ChipVariant> = {
 critical: 'danger',
 high: 'warning',
 medium: 'info',
 low: 'neutral',
};
