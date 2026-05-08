// Series X / Task X3 — context-specific empty-state copy.
//
// Single source of truth for "no data yet" messages on every list page.
// List pages do:
//
//   import { emptyStateCopyFor } from '@/lib/emptyStateCopy';
//   const copy = emptyStateCopyFor('/hr/employees');
//   <EmptyState icon={copy.icon} title={copy.title} description={copy.description}
//     action={can(copy.permission) ? <Button>{copy.actionLabel}</Button> : undefined} />
//
// For the search-empty variant (filters active), use <EmptyState searchTerm={q} ...>
// without consulting this map — the search messaging is generic.

import type { EmptyStateIcon } from '@/components/ui/EmptyState';

export interface EmptyStateCopy {
  icon: EmptyStateIcon;
  /** Human-readable plural noun (used by search-empty variant). */
  itemNoun: string;
  /** Title shown when no records exist yet. */
  title: string;
  /** Description shown when no records exist yet. */
  description: string;
  /** Permission to gate the primary action button (kwatog `module.resource.action` form). */
  permission: string;
  /** Label for the primary action button. */
  actionLabel: string;
  /** Route the primary action navigates to. */
  actionRoute: string;
}

export const EMPTY_STATE_COPY: Record<string, EmptyStateCopy> = {
  '/hr/employees': {
    icon: 'users',
    itemNoun: 'employees',
    title: 'No employees yet',
    description:
      'Add your first employee to start managing your workforce. Once added, they can be assigned shifts, tracked for attendance, and included in payroll.',
    permission: 'hr.employees.create',
    actionLabel: 'Add First Employee',
    actionRoute: '/hr/employees/create',
  },
  '/hr/departments': {
    icon: 'shield',
    itemNoun: 'departments',
    title: 'No departments yet',
    description:
      'Departments organize your employees into reporting structures used for approvals, payroll cost centers, and access control.',
    permission: 'hr.departments.manage',
    actionLabel: 'Add Department',
    actionRoute: '/hr/departments',
  },
  '/hr/positions': {
    icon: 'clipboard-list',
    itemNoun: 'positions',
    title: 'No positions defined',
    description: 'Positions describe the roles employees can hold within each department.',
    permission: 'hr.positions.manage',
    actionLabel: 'Add Position',
    actionRoute: '/hr/positions',
  },
  '/hr/attendance': {
    icon: 'calendar',
    itemNoun: 'attendance records',
    title: 'No attendance records yet',
    description:
      'Attendance is captured by importing the biometric DTR CSV or via manual entry. Once imported, payroll will pick it up automatically.',
    permission: 'attendance.dtr.import',
    actionLabel: 'Import DTR',
    actionRoute: '/hr/attendance/import',
  },
  '/leaves': {
    icon: 'calendar',
    itemNoun: 'leave requests',
    title: 'No leave requests yet',
    description: 'Approved leave requests appear here. Employees apply for leave from their self-service portal.',
    permission: 'leaves.requests.create',
    actionLabel: 'File a Leave',
    actionRoute: '/leaves/create',
  },
  '/loans': {
    icon: 'dollar-sign',
    itemNoun: 'loans',
    title: 'No loans on record',
    description: 'Active company loans and cash advances appear here once approved by management.',
    permission: 'loans.requests.create',
    actionLabel: 'Apply for Loan',
    actionRoute: '/loans/create',
  },
  '/payroll/periods': {
    icon: 'calendar',
    itemNoun: 'payroll periods',
    title: 'No payroll periods yet',
    description:
      'Payroll periods drive semi-monthly computation. Create your first period to begin processing salaries, deductions, and government remittances.',
    permission: 'payroll.periods.create',
    actionLabel: 'Create First Period',
    actionRoute: '/payroll/periods/create',
  },
  '/inventory/items': {
    icon: 'box',
    itemNoun: 'items',
    title: 'No items in inventory',
    description:
      'Items are raw materials, components, finished goods, or consumables tracked through purchasing, production, and warehousing.',
    permission: 'inventory.items.create',
    actionLabel: 'Add First Item',
    actionRoute: '/inventory/items/create',
  },
  '/inventory/grn': {
    icon: 'truck',
    itemNoun: 'goods receipts',
    title: 'No goods received yet',
    description:
      'Goods Receipt Notes are created when warehouse confirms physical receipt against an open Purchase Order, then auto-trigger incoming QC.',
    permission: 'inventory.grn.create',
    actionLabel: 'Record First GRN',
    actionRoute: '/inventory/grn/create',
  },
  '/inventory/material-issues': {
    icon: 'box',
    itemNoun: 'material issues',
    title: 'No material issues yet',
    description: 'Materials are issued from warehouse to production work orders. Each issue updates stock levels.',
    permission: 'inventory.material_issues.create',
    actionLabel: 'Issue Materials',
    actionRoute: '/inventory/material-issues/create',
  },
  '/purchasing/purchase-requests': {
    icon: 'shopping-cart',
    itemNoun: 'purchase requests',
    title: 'No purchase requests yet',
    description:
      'Purchase Requests can be raised manually or auto-generated by MRP when material shortages are detected.',
    permission: 'purchasing.pr.create',
    actionLabel: 'Create First PR',
    actionRoute: '/purchasing/purchase-requests/create',
  },
  '/purchasing/purchase-orders': {
    icon: 'package',
    itemNoun: 'purchase orders',
    title: 'No purchase orders yet',
    description:
      'POs are generated from approved Purchase Requests, consolidated by vendor, then auto-emailed to the supplier.',
    permission: 'purchasing.po.create',
    actionLabel: 'Create First PO',
    actionRoute: '/purchasing/purchase-orders/create',
  },
  '/accounting/vendors': {
    icon: 'truck',
    itemNoun: 'vendors',
    title: 'No vendors yet',
    description:
      'Vendors are suppliers of materials, services, and overhead. Add them to start placing purchase orders and recording bills.',
    permission: 'accounting.vendors.create',
    actionLabel: 'Add First Vendor',
    actionRoute: '/accounting/vendors/create',
  },
  '/accounting/customers': {
    icon: 'users',
    itemNoun: 'customers',
    title: 'No customers yet',
    description:
      'Customers are the buyers of your products. Add them to start receiving sales orders and recording invoices.',
    permission: 'accounting.customers.create',
    actionLabel: 'Add First Customer',
    actionRoute: '/accounting/customers/create',
  },
  '/accounting/invoices': {
    icon: 'receipt',
    itemNoun: 'invoices',
    title: 'No invoices yet',
    description:
      'Invoices are auto-drafted when a delivery is confirmed. Finance reviews and posts them to record receivables.',
    permission: 'accounting.invoices.create',
    actionLabel: 'Create Invoice',
    actionRoute: '/accounting/invoices/create',
  },
  '/accounting/bills': {
    icon: 'receipt',
    itemNoun: 'bills',
    title: 'No bills yet',
    description:
      'Bills are auto-drafted when GRN passes incoming QC, with line items pre-filled from the matching Purchase Order.',
    permission: 'accounting.bills.create',
    actionLabel: 'Create Bill',
    actionRoute: '/accounting/bills/create',
  },
  '/accounting/journal-entries': {
    icon: 'clipboard',
    itemNoun: 'journal entries',
    title: 'No journal entries yet',
    description:
      'Journal entries are posted automatically by the system on every financial event. Manual entries are reserved for adjustments.',
    permission: 'accounting.je.create',
    actionLabel: 'Add Manual Entry',
    actionRoute: '/accounting/journal-entries/create',
  },
  '/crm/products': {
    icon: 'box',
    itemNoun: 'products',
    title: 'No products yet',
    description:
      'Products are the finished goods you sell to customers — wiper bushings, pivot caps, etc. Each links to a Bill of Materials, inspection specs, and price agreements.',
    permission: 'crm.products.create',
    actionLabel: 'Add First Product',
    actionRoute: '/crm/products/create',
  },
  '/crm/sales-orders': {
    icon: 'shopping-cart',
    itemNoun: 'sales orders',
    title: 'No sales orders yet',
    description:
      'Sales Orders kick off the Order-to-Cash chain: confirming an SO triggers MRP planning, work orders, and the rest of production.',
    permission: 'crm.so.create',
    actionLabel: 'Create First SO',
    actionRoute: '/crm/sales-orders/create',
  },
  '/crm/complaints': {
    icon: 'alert-circle',
    itemNoun: 'complaints',
    title: 'No customer complaints',
    description:
      'Customer complaints kick off the 8D problem-solving workflow. Track root cause, corrective and preventive actions to closure.',
    permission: 'crm.complaints.create',
    actionLabel: 'Log a Complaint',
    actionRoute: '/crm/complaints/create',
  },
  '/mrp/plans': {
    icon: 'bar-chart',
    itemNoun: 'MRP plans',
    title: 'No MRP plans yet',
    description:
      'MRP plans are generated automatically when Sales Orders are confirmed. Each plan exposes material shortages and capacity issues.',
    permission: 'mrp.plans.view',
    actionLabel: 'View Sales Orders',
    actionRoute: '/crm/sales-orders',
  },
  '/mrp/boms': {
    icon: 'clipboard-list',
    itemNoun: 'BOMs',
    title: 'No bills of materials yet',
    description:
      'A Bill of Materials lists the components and quantities required to produce one unit of a product. Required before MRP can plan the product.',
    permission: 'mrp.boms.create',
    actionLabel: 'Create First BOM',
    actionRoute: '/mrp/boms/create',
  },
  '/mrp/machines': {
    icon: 'factory',
    itemNoun: 'machines',
    title: 'No machines registered',
    description:
      'Machines (injection molders) drive capacity planning, OEE, and preventive maintenance schedules.',
    permission: 'mrp.machines.create',
    actionLabel: 'Add Machine',
    actionRoute: '/mrp/machines',
  },
  '/mrp/molds': {
    icon: 'package',
    itemNoun: 'molds',
    title: 'No molds registered',
    description:
      'Molds are tooling assets used by injection machines. Each mold tracks shot count and triggers maintenance alerts when nearing its limit.',
    permission: 'mrp.molds.create',
    actionLabel: 'Add Mold',
    actionRoute: '/mrp/molds',
  },
  '/quality/inspection-specs': {
    icon: 'beaker',
    itemNoun: 'inspection specs',
    title: 'No inspection specs yet',
    description:
      'Inspection specs define the dimensions and tolerances measured during incoming, in-process, and outgoing QC.',
    permission: 'quality.specs.create',
    actionLabel: 'Define First Spec',
    actionRoute: '/quality/inspection-specs',
  },
  '/quality/inspections': {
    icon: 'clipboard',
    itemNoun: 'inspections',
    title: 'No inspections recorded',
    description:
      'Inspections are auto-created at three chain touchpoints: incoming (after GRN), in-process (during production), and outgoing (before delivery).',
    permission: 'quality.inspections.view',
    actionLabel: 'View Pending GRNs',
    actionRoute: '/inventory/grn',
  },
  '/quality/ncrs': {
    icon: 'alert-circle',
    itemNoun: 'NCRs',
    title: 'No non-conformances yet',
    description:
      'NCRs are created automatically when an inspection fails. Track corrective actions, replacements, and customer notifications to closure.',
    permission: 'quality.ncrs.view',
    actionLabel: 'View Recent Inspections',
    actionRoute: '/quality/inspections',
  },
  '/maintenance/work-orders': {
    icon: 'wrench',
    itemNoun: 'maintenance work orders',
    title: 'No maintenance work orders',
    description:
      'Maintenance WOs cover both breakdowns (reactive) and preventive schedules. Each captures downtime that flows into OEE.',
    permission: 'maintenance.wo.create',
    actionLabel: 'Log a Breakdown',
    actionRoute: '/maintenance/work-orders',
  },
  '/supply-chain/deliveries': {
    icon: 'truck',
    itemNoun: 'deliveries',
    title: 'No deliveries scheduled',
    description:
      'Deliveries are auto-drafted when outgoing QC passes. Drivers update status and upload signed receipts on the road.',
    permission: 'supply_chain.deliveries.view',
    actionLabel: 'View Sales Orders',
    actionRoute: '/crm/sales-orders',
  },
  '/supply-chain/shipments': {
    icon: 'truck',
    itemNoun: 'shipments',
    title: 'No shipments yet',
    description: 'Shipments track imported materials from foreign suppliers, including ImpEx documents and customs.',
    permission: 'supply_chain.shipments.view',
    actionLabel: 'View Open POs',
    actionRoute: '/purchasing/purchase-orders',
  },
  '/admin/users': {
    icon: 'users',
    itemNoun: 'users',
    title: 'No users yet',
    description:
      'Users are system accounts with permission to log in. Most users are auto-provisioned from employee records.',
    permission: 'admin.users.create',
    actionLabel: 'Add First User',
    actionRoute: '/admin/users/create',
  },
  '/admin/audit-logs': {
    icon: 'clipboard',
    itemNoun: 'audit log entries',
    title: 'No activity recorded yet',
    description:
      'The audit log records every authentication event, financial operation, and sensitive data access. New entries appear automatically.',
    permission: 'admin.audit_logs.view',
    actionLabel: '',
    actionRoute: '',
  },
  '/alerts': {
    icon: 'alert-circle',
    itemNoun: 'alerts',
    title: 'No active alerts',
    description: 'Alerts surface anomalies the system detects: stock-outs, payroll variances, late deliveries, mold shot-count thresholds.',
    permission: 'alerts.view',
    actionLabel: '',
    actionRoute: '',
  },
};

/**
 * Look up the empty-state copy for a given route. Falls back to a generic
 * "no data" copy when the route isn't registered, so callers never crash.
 */
export function emptyStateCopyFor(routePrefix: string): EmptyStateCopy {
  return (
    EMPTY_STATE_COPY[routePrefix] ?? {
      icon: 'inbox',
      itemNoun: 'records',
      title: 'No records yet',
      description: 'Records will appear here once they exist.',
      permission: '',
      actionLabel: '',
      actionRoute: '',
    }
  );
}
