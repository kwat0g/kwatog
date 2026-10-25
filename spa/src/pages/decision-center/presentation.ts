import { formatDate, formatDateTime } from '@/lib/formatDate';
import type { Recommendation } from '@/types/dss';

type Fact = { label: string; value: string };
type Table = { title: string; columns: string[]; rows: string[][] };
export type DecisionView = {
  title: string;
  area: string;
  summary: string;
  facts: Fact[];
  tables: Table[];
  evidenceLabel: 'Evidence supplied' | 'Limited evidence' | 'Evidence unavailable';
  actionLabel: string;
};

const unavailable = 'Not available';
const detectorLabels: Record<string, string> = {
  'dss.chain_bottlenecks': 'Workflow handoffs',
  'dss.approval_bottlenecks': 'Approval response',
  'dss.work_order_intervention': 'Production completion',
  'dss.receivables_priority': 'Overdue receivables',
  'dss.quality_ncr_sla': 'Quality response',
  'dss.preventive_maintenance_overdue': 'Preventive maintenance',
  'dss.payroll_anomaly_summary': 'Payroll anomaly review',
  'dss.training_expiry_summary': 'Training expiry',
  'dss.master_hygiene': 'Master data completeness',
  'dss.three_way_match_review': 'Bill match review',
  'dss.quality_suspect_material_lot': 'Suspect material lot trace',
};
const severityLabels: Record<string, string> = {
  critical: 'Critical',
  warning: 'Warning',
  info: 'Information',
};
const statusLabels: Record<string, string> = {
  open: 'New',
  in_progress: 'In progress',
  accepted: 'Acknowledged',
  executed: 'Action recorded',
  dismissed: 'Dismissed',
  snoozed: 'Snoozed',
  expired: 'Expired',
  resolved: 'Resolved',
};
const has = (record: object, key: string): boolean =>
  Object.prototype.hasOwnProperty.call(record, key);
const dictValue = (dict: Record<string, string>, key: unknown, fallback: string): string =>
  typeof key === 'string' && has(dict, key) ? dict[key]! : fallback;

export const detectorLabel = (id: string): string =>
  dictValue(detectorLabels, id, 'Decision check');
export const severityLabel = (severity: string): string =>
  dictValue(severityLabels, severity, 'Severity unavailable');
export const recommendationStatusLabel = (status: string): string =>
  dictValue(statusLabels, status, 'Status unavailable');

const recordOf = (value: unknown): Record<string, unknown> =>
  value !== null && typeof value === 'object' && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {};
const numeric = (value: unknown): string => {
  if (
    (typeof value !== 'number' && typeof value !== 'string') ||
    (typeof value === 'string' && !value.trim())
  )
    return unavailable;
  const number = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(number) && number >= 0
    ? new Intl.NumberFormat('en-PH', { maximumFractionDigits: 1 }).format(number)
    : unavailable;
};
const boundedCount = (value: unknown): string =>
  typeof value === 'string' && /^\d+\+$/.test(value)
    ? `At least ${value.slice(0, -1)}`
    : numeric(value);
const date = (value: unknown): string =>
  typeof value === 'string' && value ? formatDate(value, unavailable) : unavailable;
const dateTime = (value: unknown): string =>
  typeof value === 'string' && value ? formatDateTime(value, unavailable) : unavailable;
const status = (value: unknown): string => {
  if (value === undefined || value === null || value === '') return unavailable;
  const labels: Record<string, string> = {
    confirmed: 'Confirmed',
    in_progress: 'In progress',
    paused: 'Paused',
    planned: 'Planned',
    draft: 'Draft',
    scheduled: 'Scheduled',
    accepted: 'Accepted',
    pending: 'Pending',
    completed: 'Completed',
    overdue: 'Overdue',
    approved: 'Approved',
    rejected: 'Rejected',
    open: 'Open',
    closed: 'Closed',
    active: 'Active',
    inactive: 'Inactive',
    submitted: 'Submitted',
    processing: 'Processing',
    dispatched: 'Dispatched',
    delivered: 'Delivered',
    received: 'Received',
    issued: 'Issued',
    posted: 'Posted',
    cancelled: 'Cancelled',
    failed: 'Failed',
  };
  return dictValue(labels, value, 'Status unavailable');
};
const item = (value: unknown): string =>
  typeof value === 'string' && value.trim() ? value : unavailable;
const rowArray = (value: unknown): unknown[] => (Array.isArray(value) ? value : []);
const fact = (label: string, value: string): Fact => ({ label, value });
const field = (
  data: Record<string, unknown>,
  key: string,
  label: string,
  render: (value: unknown) => string = item,
): Fact => fact(label, render(data[key]));
const family = (
  area: string,
  title: string,
  actionLabel: string,
  summary: string,
  facts: Fact[],
  required: string[],
  data: Record<string, unknown>,
  tables: Table[] = [],
): DecisionView => {
  const present = (key: string) =>
    data[key] !== undefined && data[key] !== null && data[key] !== '' && data[key] !== false;
  const supplied =
    facts.some(({ value }) => value !== unavailable) || tables.some(({ rows }) => rows.length > 0);
  const complete =
    required.every(present) &&
    facts.every(({ value }) => value !== unavailable && value !== 'Status unavailable') &&
    tables.every(({ rows }) =>
      rows.every((row) =>
        row.every((value) => value !== unavailable && value !== 'Status unavailable'),
      ),
    );
  return {
    title,
    area,
    summary,
    facts,
    tables,
    evidenceLabel: !supplied
      ? 'Evidence unavailable'
      : complete && data.sufficiency === 'sufficient'
        ? 'Evidence supplied'
        : 'Limited evidence',
    actionLabel,
  };
};

const specs: Record<string, (data: Record<string, unknown>) => DecisionView> = {
  three_way_match_blocked: (d) => {
    const categories = recordOf(d.blocking_line_categories);
    const rows = Object.entries(categories)
      .filter(
        ([label, count]) =>
          [
            'Quantity variance',
            'Price variance',
            'Quantity and price variance',
            'Receipt quantity short',
            'Repeated bill line',
            'Bill line without PO match',
          ].includes(label) &&
          (typeof count === 'number' || typeof count === 'string'),
      )
      .map(([label, count]) => [label, numeric(count)]);
    return family(
      'Finance',
      `Review blocked bill match${typeof d.bill_number === 'string' && d.bill_number.trim() ? ` · ${d.bill_number}` : ''}`,
      'Review bill',
      'The saved bill review is blocked. Later purchase order or receipt changes may not be reflected.',
      [
        field(d, 'bill_number', 'Bill'),
        field(d, 'po_number', 'Purchase order'),
        field(d, 'bill_updated_at', 'Bill record updated', dateTime),
      ],
      ['bill_number', 'bill_updated_at'],
      d,
      rows.length
        ? [{ title: 'Blocking line categories', columns: ['Category', 'Lines'], rows }]
        : [],
    );
  },
  chain_bottleneck: (d) => {
    const rows = rowArray(d.sample_documents)
      .slice(0, 5)
      .map((value) => {
        const r = recordOf(value);
        return typeof r.document === 'string' && r.document.trim()
          ? [r.document, status(r.status), numeric(r.hours_stuck)]
          : null;
      })
      .filter((row): row is string[] => row !== null);
    const table: Table[] = rows.length
      ? [
          {
            title: 'Sample affected documents',
            columns: ['Document', 'Status', 'Reported age (hours)'],
            rows,
          },
        ]
      : [];
    const rule =
      typeof d.rule === 'string' &&
      d.rule.trim() &&
      !/[_\\{}:]|\bdss\.|\b[A-Z][A-Za-z0-9]*(?:Service|Detector|Repository|Controller)\b/.test(
        d.rule,
      )
        ? d.rule.trim()
        : 'Review delayed workflow handoffs';
    const reference = rows.find((row) => row[0] !== unavailable)?.[0];
    const title = `${rule}${reference ? ` · ${reference}` : ''}`;
    return family(
      'Connected workflows',
      title,
      'Review workflow queue',
      'A workflow handoff is beyond its configured target; verify the source records.',
      [
        field(d, 'stuck_count', 'Affected documents', numeric),
        field(d, 'threshold_hours', 'Target (hours)', numeric),
        field(d, 'oldest_hours_stuck', 'Oldest reported age (hours)', numeric),
      ],
      ['stuck_count', 'threshold_hours', 'oldest_hours_stuck'],
      d,
      table,
    );
  },
  approval_bottleneck: (d) => {
    const roles: Record<string, string> = {
      vice_president: 'Vice president',
      ppc_head: 'PPC head',
      sales_officer: 'Sales officer',
      purchasing_officer: 'Purchasing officer',
      warehouse_staff: 'Warehouse staff',
      production_manager: 'Production manager',
      qc_inspector: 'Quality inspector',
      maintenance_supervisor: 'Maintenance supervisor',
      maintenance_tech: 'Maintenance technician',
      employee: 'Employee',
      driver: 'Driver',
      finance_officer: 'Finance officer',
      hr_officer: 'HR officer',
      department_head: 'Department head',
      customer_service_officer: 'Customer service officer',
      impex_officer: 'Import/export officer',
      system_admin: 'System administrator',
      unassigned: 'No role assigned',
    };
    const queues: Record<string, string> = {
      Payroll: 'Payroll',
      'Bill payments': 'Bill payments',
      'Salary adjustments': 'Salary adjustments',
      'Asset disposals': 'Asset disposals',
      'Return requests': 'Return requests',
    };
    const queue = dictValue(queues, d.queue, '');
    const approvalLabel = queue ? `${queue} approvals` : 'approvals';
    return family(
      'Approvals',
      `Review overdue ${approvalLabel}`,
      `Review ${approvalLabel}`,
      'The current approval step is waiting beyond its reminder target. Some queues are excluded because their records require user-specific visibility checks.',
      [
        field(d, 'queue', 'Approval queue'),
        field(d, 'role', 'Approver role', (v) => dictValue(roles, v, unavailable)),
        field(d, 'waiting_count', 'Waiting approvals', numeric),
        field(d, 'oldest_wait_hours', 'Oldest wait (hours)', numeric),
        field(d, 'reminder_threshold_hours', 'Reminder target (hours)', numeric),
        field(
          d,
          'excluded_queues',
          'Excluded row-scoped queues',
          (value) =>
            rowArray(value)
              .filter((entry): entry is string => typeof entry === 'string' && !!entry.trim())
              .join(', ') || unavailable,
        ),
      ],
      [
        'queue',
        'role',
        'waiting_count',
        'oldest_wait_hours',
        'reminder_threshold_hours',
        'excluded_queues',
      ],
      d,
    );
  },
  work_order_overdue: (d) =>
    family(
      'Production',
      `Review delayed production${typeof d.work_order === 'string' && d.work_order.trim() ? ` · ${d.work_order}` : ''}`,
      'Review work order',
      'A production order is past its planned completion date.',
      [
        field(d, 'work_order', 'Work order'),
        field(d, 'status', 'Status', status),
        field(d, 'planned_end', 'Planned end', dateTime),
        field(d, 'days_overdue', 'Days overdue', numeric),
        field(d, 'quantity_target', 'Target quantity', numeric),
        field(d, 'quantity_good', 'Good quantity', numeric),
        field(d, 'remaining_quantity', 'Remaining quantity', numeric),
        field(d, 'unit_of_measure', 'Unit'),
      ],
      [
        'work_order',
        'status',
        'planned_end',
        'days_overdue',
        'quantity_target',
        'quantity_good',
        'remaining_quantity',
        'unit_of_measure',
      ],
      d,
    ),
  receivables_priority: (d) =>
    family(
      'Finance',
      `Review overdue receivables${typeof d.customer === 'string' && d.customer.trim() ? ` · ${d.customer}` : ''}`,
      'Review overdue invoices',
      'Customer invoices are overdue and need collection review.',
      [
        field(d, 'customer', 'Customer'),
        field(d, 'overdue_invoice_count', 'Overdue invoices', numeric),
        field(d, 'open_balance', 'Reported open balance (PHP)'),
        field(d, 'oldest_due_date', 'Oldest due date', date),
        field(d, 'days_overdue', 'Days overdue', numeric),
        field(d, 'first_dunning_tier_days', 'First reminder target (days)', numeric),
      ],
      [
        'customer',
        'overdue_invoice_count',
        'open_balance',
        'oldest_due_date',
        'days_overdue',
        'first_dunning_tier_days',
      ],
      d,
    ),
  ncr_sla_breach: (d) =>
    family(
      'Quality',
      `Review overdue quality response${typeof d.ncr_number === 'string' && d.ncr_number.trim() ? ` · ${d.ncr_number}` : ''}`,
      'Review quality report',
      'A quality response has exceeded its service target.',
      [
        field(d, 'ncr_number', 'Quality report'),
        field(d, 'ncr_severity', 'Severity', (v) =>
          dictValue(
            { critical: 'Critical', high: 'High', medium: 'Medium', low: 'Low' },
            v,
            unavailable,
          ),
        ),
        field(d, 'status', 'Status', status),
        field(d, 'hours_since_escalation', 'Hours since escalation', numeric),
        field(d, 'sla_hours', 'Response target (hours)', numeric),
        field(d, 'escalation_level', 'Escalation level', numeric),
      ],
      [
        'ncr_number',
        'ncr_severity',
        'status',
        'hours_since_escalation',
        'sla_hours',
        'escalation_level',
      ],
      d,
    ),
  suspect_material_lot_trace: (d) => {
    const samples = (key: string): string[][] =>
      rowArray(d[key])
        .filter((value): value is string => typeof value === 'string' && !!value.trim())
        .slice(0, 5)
        .map((value) => [value]);
    const workOrders = samples('work_order_samples');
    const shipmentLots = samples('shipment_lot_samples');
    const tables: Table[] = [];
    if (workOrders.length)
      tables.push({
        title: 'Linked production work orders',
        columns: ['Work order'],
        rows: workOrders,
      });
    if (shipmentLots.length)
      tables.push({
        title: 'Linked shipment lot references',
        columns: ['Shipment lot'],
        rows: shipmentLots,
      });
    return family(
      'Quality',
      `Review failed material lot trace${typeof d.material_lot_number === 'string' && d.material_lot_number.trim() ? ` · ${d.material_lot_number}` : ''}`,
      'Review material lot trace',
      'A failed incoming inspection has explicit links to production work orders and shipment lots. Genealogy is incomplete; these links do not establish full impact.',
      [
        field(d, 'material_lot_number', 'Material lot'),
        field(d, 'inspection_number', 'Failed inspection'),
        field(d, 'linked_work_order_count', 'Linked work orders', boundedCount),
        field(d, 'linked_shipment_lot_count', 'Linked shipment lots', boundedCount),
        fact('Traceability coverage', 'Linked records found; genealogy is not complete'),
      ],
      [
        'material_lot_number',
        'inspection_number',
        'linked_work_order_count',
        'linked_shipment_lot_count',
      ],
      d,
      tables,
    );
  },
  preventive_maintenance_overdue: (d) => {
    const types: Record<string, string> = {
      machine: 'Machines',
      mold: 'Molds',
      asset: 'Assets',
      Machine: 'Machines',
      Mold: 'Molds',
      Asset: 'Assets',
    };
    const type = typeof d.maintainable_type === 'string' ? d.maintainable_type : '';
    const classType = type.match(/\\(Machine|Mold|Asset)$/)?.[1];
    const knownType = has(types, type) || !!classType;
    const maintainable = knownType
      ? (types[type] ??
        `${classType === 'Machine' ? 'Machines' : classType === 'Mold' ? 'Molds' : 'Assets'}`)
      : 'Equipment';
    d.equipment_type_known = knownType;
    return family(
      'Maintenance',
      'Review overdue preventive maintenance',
      'Review overdue maintenance',
      `${maintainable} have overdue preventive maintenance.`,
      [
        fact('Equipment category', type ? maintainable : unavailable),
        field(d, 'overdue_count', 'Overdue items', numeric),
        field(d, 'oldest_due_at', 'Oldest due date', dateTime),
      ],
      ['equipment_type_known', 'overdue_count', 'oldest_due_at'],
      d,
    );
  },
  payroll_anomaly_summary: (d) =>
    family(
      'Payroll',
      'Review unresolved payroll flags',
      'Review anomaly flags',
      'Unresolved anomaly flags remain for this payroll period.',
      [
        field(d, 'period_start', 'Period start', date),
        field(d, 'period_end', 'Period end', date),
        field(d, 'unresolved_flags', 'Unresolved anomaly flags', numeric),
      ],
      ['period_start', 'period_end', 'unresolved_flags'],
      d,
    ),
  training_expiry: (d) =>
    family(
      'People and training',
      `Review training certification expiry${typeof d.training === 'string' && d.training.trim() ? ` · ${d.training}` : ''}`,
      'Review training matrix',
      'Training certifications are nearing expiry or have expired.',
      [
        field(d, 'training', 'Training'),
        field(d, 'affected_records', 'Affected records', numeric),
        field(d, 'already_expired', 'Already expired', numeric),
        field(d, 'next_expiry', 'Next expiry', date),
        field(d, 'horizon_days', 'Expiry horizon (days)', numeric),
      ],
      ['training', 'affected_records', 'already_expired', 'next_expiry', 'horizon_days'],
      d,
    ),
  master_hygiene: (d) => {
    const gaps: Record<string, string> = {
      items_missing_reorder_point: 'Set missing item reorder points',
      items_missing_safety_stock: 'Set missing item safety stock',
      items_missing_lead_time: 'Set missing item lead times',
      items_missing_standard_cost: 'Set missing item standard costs',
      items_without_approved_supplier: 'Add approved suppliers to items',
      customers_missing_credit_limit: 'Set missing customer credit limits',
      products_without_active_bom: 'Add active bills of materials to products',
    };
    const refs = rowArray(d.evidence)
      .filter((v): v is string => typeof v === 'string' && !!v.trim())
      .slice(0, 10);
    const tables: Table[] = refs.length
      ? [{ title: 'Sample at last check', columns: ['Reference'], rows: refs.map((v) => [v]) }]
      : [];
    return family(
      'Master data',
      dictValue(gaps, d.gap, 'Complete missing master data'),
      'Review master data',
      'Required master data is missing from affected records.',
      [field(d, 'affected_count', 'Affected records', numeric)],
      ['affected_count'],
      d,
      tables,
    );
  },
};

export function decisionView(rec: Recommendation): DecisionView {
  const raw = rec;
  const data = { ...recordOf(raw.rationale), sufficiency: raw.data_sufficiency };
  if (!has(specs, raw.type))
    return {
      title: 'Review business recommendation',
      area: 'Business operations',
      summary: 'Review the related business workflow for details.',
      facts: [],
      tables: [],
      evidenceLabel: 'Evidence unavailable',
      actionLabel: 'Open related workflow',
    };
  return specs[raw.type]!(data);
}
