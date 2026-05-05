/**
 * Sprint P4 — per-notification-type metadata for the bell dropdown
 * and notifications page (icon + group bucket).
 *
 * `type` is the fully-qualified Notification class name from Laravel
 * (e.g. `App\Modules\Quality\Notifications\NcrCreated`). We match by
 * basename so module paths can move without breaking the lookup.
 *
 * `group` powers the filter chips on `/notifications` (P4):
 *   - approvals: Leave/PR/PO/Loan/Payroll approval requests + decisions
 *   - alerts:    Smart alerts from the AlertEngine + breakdown / NCR
 *   - system:    everything else (auth, deploys, generic)
 */
import {
  AlertCircle,
  Bell,
  Calendar,
  CheckCircle2,
  FileText,
  HandCoins,
  Package,
  ShieldAlert,
  Truck,
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

/** Match by suffix of the type basename (case-insensitive). */
const RULES: Array<{ pattern: RegExp; meta: NotificationMeta }> = [
  // Quality
  { pattern: /Ncr/i,                   meta: { icon: ShieldAlert, group: 'alerts',    label: 'Quality' } },
  { pattern: /Inspection/i,            meta: { icon: ShieldAlert, group: 'alerts',    label: 'Quality' } },

  // Maintenance / breakdowns
  { pattern: /Breakdown|Maintenance/i, meta: { icon: Wrench,      group: 'alerts',    label: 'Maintenance' } },

  // Inventory / alerts
  { pattern: /Stock|Inventory/i,       meta: { icon: Package,     group: 'alerts',    label: 'Inventory' } },

  // Procure-to-pay approvals
  { pattern: /PurchaseRequest/i,       meta: { icon: FileText,    group: 'approvals', label: 'Purchasing' } },
  { pattern: /PurchaseOrder/i,         meta: { icon: Package,     group: 'approvals', label: 'Purchasing' } },
  { pattern: /Bill/i,                  meta: { icon: FileText,    group: 'approvals', label: 'Accounting' } },

  // HR-side approvals
  { pattern: /Leave/i,                 meta: { icon: Calendar,    group: 'approvals', label: 'Leave' } },
  { pattern: /Loan|CashAdvance/i,      meta: { icon: HandCoins,   group: 'approvals', label: 'Loans' } },
  { pattern: /Payroll/i,               meta: { icon: HandCoins,   group: 'approvals', label: 'Payroll' } },

  // Order-to-cash / fulfilment
  { pattern: /Delivery|Shipment/i,     meta: { icon: Truck,       group: 'system',    label: 'Logistics' } },
  { pattern: /Invoice|Collection/i,    meta: { icon: FileText,    group: 'approvals', label: 'Billing' } },
];

const DEFAULT: NotificationMeta = { icon: Bell, group: 'system', label: 'System' };

export function notificationMeta(type: string | undefined): NotificationMeta {
  if (!type) return DEFAULT;
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
