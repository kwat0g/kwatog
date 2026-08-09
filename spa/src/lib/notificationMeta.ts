/**
 * Sprint P4 — per-notification-type metadata for the bell dropdown
 * and notifications page (icon + group bucket).
 *
 * `type` is the dot-namespaced key the backend sends
 * (`chain.pr_approved`, `ncr.escalation`, …) — the same keys
 * `NotificationCatalog` exposes on the preferences page.
 *
 * This file used to match PascalCase Laravel class basenames (`/PurchaseRequest/`,
 * `/Payroll/`) because that was the original notification format. The backend
 * moved to dot keys and the patterns were never updated, so most types missed
 * every rule and fell through to the generic grey "System" bell — and because
 * `group` drives the filter chips, the Approvals chip hid most real approvals
 * and the Alerts chip hid most real alerts. Exact keys are matched first now;
 * the old regexes remain as a fallback for rows already stored with a
 * fully-qualified class name.
 *
 * `group` powers the filter chips on `/notifications`:
 * - approvals: things waiting on a human decision, and the outcome of one
 * - alerts: something is wrong or degrading and needs attention
 * - system: informational progress through a chain
 */
import {
 AlertCircle,
 AlertTriangle,
 Bell,
 Calendar,
 CheckCircle2,
 CircleDollarSign,
 ClipboardCheck,
 Factory,
 FileText,
 FileWarning,
 Gauge,
 GraduationCap,
 HandCoins,
 KeyRound,
 Package,
 PackageCheck,
 Receipt,
 ShieldAlert,
 ShieldCheck,
 TrendingDown,
 Truck,
 UserPlus,
 Users,
 Wrench,
 type LucideIcon,
} from 'lucide-react';

export type NotificationGroup = 'approvals' | 'alerts' | 'system';

export interface NotificationMeta {
 icon: LucideIcon;
 group: NotificationGroup;
 /** Friendly label for the chip on the notifications list. */
 label: string;
}

/**
 * Exact match on the backend type key. Mirrors NotificationCatalog::defaults();
 * a key present there and absent here renders as a generic bell, which the
 * accompanying test guards against.
 */
const BY_TYPE: Record<string, NotificationMeta> = {
 // ── Chain 1 · Order to Cash ──────────────────────────────────────────
 'chain.so_confirmed': { icon: ClipboardCheck, group: 'system', label: 'Sales' },
 'chain.in_process_qc_required': { icon: ShieldCheck, group: 'system', label: 'Quality' },
 'production.wo_completed': { icon: Factory, group: 'system', label: 'Production' },
 'chain.outgoing_qc_required': { icon: ShieldCheck, group: 'system', label: 'Quality' },
 'quality.inspection_failed': { icon: ShieldAlert, group: 'alerts', label: 'Quality' },
 'chain.delivery_drafted': { icon: Truck, group: 'system', label: 'Logistics' },
 'chain.delivery_confirmed': { icon: Truck, group: 'system', label: 'Logistics' },
 'return.restocked': { icon: PackageCheck, group: 'system', label: 'Returns' },

 // ── Chain 2 · Procure to Pay ─────────────────────────────────────────
 'inventory.grn_received': { icon: PackageCheck, group: 'system', label: 'Inventory' },
 'chain.incoming_qc_required': { icon: ShieldCheck, group: 'system', label: 'Quality' },
 'inventory.low_stock': { icon: Package, group: 'alerts', label: 'Inventory' },
 'chain.pr_approved': { icon: FileText, group: 'approvals', label: 'Purchasing' },
 // Auto-conversion failed (no supplier / no price) — needs a human to convert
 // the PR by hand, so it belongs in alerts, not the informational stream.
 'chain.pr_auto_convert_skipped': { icon: FileWarning, group: 'alerts', label: 'Purchasing' },
 'chain.po_approved': { icon: Package, group: 'approvals', label: 'Purchasing' },
 'auto_po_pending': { icon: Package, group: 'approvals', label: 'Purchasing' },
 'purchasing.supplier_deterioration': { icon: TrendingDown, group: 'alerts', label: 'Purchasing' },
 'return.shipped_to_vendor': { icon: Truck, group: 'system', label: 'Returns' },

 // ── Chain 3 · Hire to Retire ─────────────────────────────────────────
 'leave.submitted': { icon: Calendar, group: 'approvals', label: 'Leave' },
 'leave.pending_hr': { icon: Calendar, group: 'approvals', label: 'Leave' },
 'leave.approved': { icon: Calendar, group: 'approvals', label: 'Leave' },
 'leave.rejected': { icon: Calendar, group: 'approvals', label: 'Leave' },
 'attendance.ot_submitted': { icon: Calendar, group: 'approvals', label: 'Overtime' },
 'attendance.ot_approved': { icon: Calendar, group: 'approvals', label: 'Overtime' },
 'attendance.ot_rejected': { icon: Calendar, group: 'approvals', label: 'Overtime' },
 'loans.submitted': { icon: HandCoins, group: 'approvals', label: 'Loans' },
 'loans.approved': { icon: HandCoins, group: 'approvals', label: 'Loans' },
 'loans.rejected': { icon: HandCoins, group: 'approvals', label: 'Loans' },
 'chain.payslip_ready': { icon: Receipt, group: 'system', label: 'Payroll' },
 'chain.separation_initiated': { icon: Users, group: 'system', label: 'HR' },
 'recruitment.new_application': { icon: UserPlus, group: 'system', label: 'Recruitment' },
 'training.expiry': { icon: GraduationCap, group: 'alerts', label: 'Training' },

 // ── Quality & compliance ─────────────────────────────────────────────
 'auto_ncr_created': { icon: ShieldAlert, group: 'alerts', label: 'Quality' },
 'ncr.escalation': { icon: ShieldAlert, group: 'alerts', label: 'Quality' },
 'ncr.recurrence': { icon: ShieldAlert, group: 'alerts', label: 'Quality' },
 'ncr.return_to_supplier': { icon: ShieldAlert, group: 'alerts', label: 'Quality' },
 'spc_alert': { icon: Gauge, group: 'alerts', label: 'SPC' },
 'effectiveness_due': { icon: ClipboardCheck, group: 'approvals', label: 'Quality' },
 'effectiveness_overdue': { icon: AlertTriangle, group: 'alerts', label: 'Quality' },
 'document.review_due': { icon: FileWarning, group: 'approvals', label: 'Documents' },
 '8d.sla': { icon: AlertTriangle, group: 'alerts', label: 'Complaints' },

 // ── Finance & accounting ─────────────────────────────────────────────
 'ar.dunning.escalation': { icon: CircleDollarSign, group: 'alerts', label: 'Receivables' },
 'invoice.auto_failed': { icon: FileWarning, group: 'alerts', label: 'Billing' },

 // ── Maintenance, planning & approvals ────────────────────────────────
 'mrp_run_completed': { icon: Factory, group: 'system', label: 'MRP' },
 'maintenance.breakdown': { icon: Wrench, group: 'alerts', label: 'Maintenance' },
 'approval_reminder': { icon: CheckCircle2, group: 'approvals', label: 'Approval' },
 'approval_escalation': { icon: AlertTriangle, group: 'approvals', label: 'Approval' },

 // ── Security & administration ────────────────────────────────────────
 'permission.override': { icon: KeyRound, group: 'system', label: 'Access' },
};

/**
 * Legacy fallback for rows whose `type` is a fully-qualified Laravel
 * notification class (`App\Modules\Quality\Notifications\NcrCreated`). Matched
 * against the class basename, after the exact-key lookup misses.
 */
const RULES: Array<{ pattern: RegExp; meta: NotificationMeta }> = [
 // Quality
 { pattern: /Ncr/i, meta: { icon: ShieldAlert, group: 'alerts', label: 'Quality' } },
 { pattern: /Inspection/i, meta: { icon: ShieldAlert, group: 'alerts', label: 'Quality' } },

 // Maintenance / breakdowns
 { pattern: /Breakdown|Maintenance/i, meta: { icon: Wrench, group: 'alerts', label: 'Maintenance' } },

 // Inventory / alerts
 { pattern: /Stock|Inventory/i, meta: { icon: Package, group: 'alerts', label: 'Inventory' } },

 // Procure-to-pay approvals
 { pattern: /PurchaseRequest/i, meta: { icon: FileText, group: 'approvals', label: 'Purchasing' } },
 { pattern: /PurchaseOrder/i, meta: { icon: Package, group: 'approvals', label: 'Purchasing' } },
 { pattern: /Bill/i, meta: { icon: FileText, group: 'approvals', label: 'Accounting' } },

 // HR-side approvals
 { pattern: /Leave/i, meta: { icon: Calendar, group: 'approvals', label: 'Leave' } },
 { pattern: /Loan|CashAdvance/i, meta: { icon: HandCoins, group: 'approvals', label: 'Loans' } },
 { pattern: /Payroll/i, meta: { icon: HandCoins, group: 'approvals', label: 'Payroll' } },

 // Order-to-cash / fulfilment
 { pattern: /Delivery|Shipment/i, meta: { icon: Truck, group: 'system', label: 'Logistics' } },
 { pattern: /Invoice|Collection/i, meta: { icon: FileText, group: 'approvals', label: 'Billing' } },
];

const DEFAULT: NotificationMeta = { icon: Bell, group: 'system', label: 'System' };

export function notificationMeta(type: string | undefined): NotificationMeta {
 if (!type) return DEFAULT;

 const exact = BY_TYPE[type];
 if (exact) return exact;

 const baseName = type.split('\\').pop() ?? type;
 for (const rule of RULES) {
 if (rule.pattern.test(baseName)) return rule.meta;
 }
 // Fallbacks by keyword.
 if (/approved|rejected/i.test(baseName)) {
 return { icon: CheckCircle2, group: 'approvals', label: 'Approval' };
 }
 if (/alert|warn/i.test(baseName)) {
 return { icon: AlertCircle, group: 'alerts', label: 'Alert' };
 }
 return DEFAULT;
}

/** Type keys with explicit metadata — used by tests to detect catalog drift. */
export const KNOWN_NOTIFICATION_TYPES = Object.keys(BY_TYPE);

/**
 * Returns a YYYY-MM-DD bucket key for a notification's `created_at` so
 * the list page can group rows under Today / Yesterday / Earlier this
 * week / Older.
 */
export function dateBucket(createdAt: string): 'today' | 'yesterday' | 'this_week' | 'older' {
 const created = new Date(createdAt);
 const now = new Date();

 const startOfDay = (d: Date) => {
 const x = new Date(d);
 x.setHours(0, 0, 0, 0);
 return x;
 };

 const today = startOfDay(now).getTime();
 const yesterday = today - 24 * 60 * 60 * 1000;
 const weekAgo = today - 7 * 24 * 60 * 60 * 1000;
 const ts = created.getTime();

 if (ts >= today) return 'today';
 if (ts >= yesterday) return 'yesterday';
 if (ts >= weekAgo) return 'this_week';
 return 'older';
}

const BUCKET_LABELS: Record<ReturnType<typeof dateBucket>, string> = {
 today: 'Today',
 yesterday: 'Yesterday',
 this_week: 'Earlier this week',
 older: 'Older',
};

export function bucketLabel(bucket: ReturnType<typeof dateBucket>): string {
 return BUCKET_LABELS[bucket];
}

/** Compact "2 hours ago" formatter for the bell dropdown. */
export function timeAgo(iso: string): string {
 const ts = new Date(iso).getTime();
 if (Number.isNaN(ts)) return '';
 const diff = Math.max(0, Date.now() - ts);
 const m = Math.floor(diff / 60_000);
 if (m < 1) return 'just now';
 if (m < 60) return `${m}m ago`;
 const h = Math.floor(m / 60);
 if (h < 24) return `${h}h ago`;
 const d = Math.floor(h / 24);
 if (d < 7) return `${d}d ago`;
 return new Date(iso).toLocaleDateString();
}
