import type { ChangeEvent } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { client } from '@/api/client';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { Switch } from '@/components/ui/Switch';

/**
 * Sprint 8 — Task 77. Per-user notification preferences matrix.
 *
 * Rows = notification types (curated metadata).
 * Columns = in_app, email.
 * Toggling a switch optimistically updates and persists via PUT.
 */

const NOTIFICATION_TYPES: Array<{ key: string; label: string; description: string }> = [
  // — Chain 1: Order to Cash —
  { key: 'chain.so_confirmed',          label: 'Sales order confirmed',       description: 'A sales order has been confirmed by the customer.' },
  { key: 'production.wo_completed',     label: 'Work order completed',        description: 'A production work order has finished. Outgoing QC is next.' },
  { key: 'quality.inspection_failed',   label: 'QC inspection failed',        description: 'A quality inspection failed. An NCR may be required.' },
  { key: 'chain.delivery_confirmed',    label: 'Delivery confirmed',          description: 'A delivery has been confirmed and an invoice draft was created.' },

  // — Chain 2: Procure to Pay —
  { key: 'inventory.grn_received',      label: 'Goods receipt created',       description: 'Goods have been received against a purchase order.' },
  { key: 'inventory.low_stock',         label: 'Low stock alert',             description: 'An item fell below reorder point and an auto-PR was created.' },
  { key: 'chain.pr_approved',           label: 'Purchase request approved',   description: 'A purchase request has been fully approved.' },
  { key: 'chain.po_approved',           label: 'Purchase order approved',     description: 'A purchase order has been fully approved and is ready to send.' },

  // — Chain 3: Hire to Retire —
  { key: 'leave.submitted',             label: 'Leave request submitted',     description: 'An employee has submitted a leave request for your approval.' },
  { key: 'leave.pending_hr',            label: 'Leave pending HR approval',   description: 'A leave request has been approved by the dept head and needs HR sign-off.' },
  { key: 'leave.approved',              label: 'Leave request approved',      description: 'Your leave request has been approved.' },
  { key: 'leave.rejected',              label: 'Leave request rejected',      description: 'Your leave request was not approved.' },
  { key: 'attendance.ot_submitted',     label: 'Overtime request submitted',  description: 'An employee has submitted an overtime request for your approval.' },
  { key: 'attendance.ot_approved',      label: 'Overtime request approved',   description: 'Your overtime request has been approved.' },
  { key: 'attendance.ot_rejected',      label: 'Overtime request rejected',   description: 'Your overtime request was not approved.' },
  { key: 'loans.submitted',             label: 'Loan/CA request submitted',   description: 'An employee has submitted a loan or cash advance for Finance approval.' },
  { key: 'loans.approved',              label: 'Loan/CA approved',            description: 'Your loan or cash advance request has been approved.' },
  { key: 'loans.rejected',              label: 'Loan/CA rejected',            description: 'Your loan or cash advance request was not approved.' },
  { key: 'chain.payslip_ready',         label: 'Payslip ready',               description: 'Your payslip is ready to view.' },
  { key: 'chain.separation_initiated',  label: 'Separation initiated',        description: 'An employee separation process has started.' },

  // — Maintenance —
  { key: 'maintenance.breakdown',       label: 'Machine breakdown',           description: 'A machine has entered breakdown status and may have paused a work order.' },

  // — Approvals —
  { key: 'approval_reminder',           label: 'Approval reminder',           description: 'You have a pending approval that has been waiting over 24 hours.' },
  { key: 'approval_escalation',         label: 'Approval escalation',         description: 'An approval you are responsible for has been escalated due to timeout.' },
];

type Pref = { notification_type: string; channel: 'in_app' | 'email'; enabled: boolean };

export default function NotificationPreferencesPage() {
  const qc = useQueryClient();
  const { data, isLoading } = useQuery({
    queryKey: ['notification-preferences'],
    queryFn: () => client.get<{ data: Pref[] }>('/notification-preferences').then(r => r.data.data),
  });

  const upsert = useMutation({
    mutationFn: (preferences: Pref[]) =>
      client.put('/notification-preferences', { preferences }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['notification-preferences'] });
    },
    onError: () => toast.error('Failed to save preferences. Please try again.'),
  });

  const isEnabled = (type: string, channel: Pref['channel']) =>
    data?.find(p => p.notification_type === type && p.channel === channel)?.enabled ?? true;

  const onToggle = (type: string, channel: Pref['channel'], enabled: boolean) => {
    upsert.mutate([{ notification_type: type, channel, enabled }]);
  };

  // Switch is a controlled <input type="checkbox"> — onChange yields an event.
  const handleSwitch = (type: string, channel: Pref['channel']) =>
    (e: ChangeEvent<HTMLInputElement>) => onToggle(type, channel, e.target.checked);

  if (isLoading) {
    return (
      <div>
        <PageHeader title="Notification Preferences" backTo="/self-service" backLabel="Dashboard" />
        <div className="px-5 py-4 space-y-2">
          {[1, 2, 3, 4, 5].map((i) => <SkeletonBlock key={i} className="h-14 rounded-md" />)}
        </div>
      </div>
    );
  }

  return (
    <div>
      <PageHeader title="Notification Preferences" backTo="/self-service" backLabel="Dashboard" />
      <div className="px-5 py-4">
        <div className="rounded-md border border-default overflow-hidden">
          <div className="grid grid-cols-12 px-3 py-2 border-b border-default bg-subtle text-[10px] uppercase tracking-wider text-muted font-medium">
            <div className="col-span-8">Type</div>
            <div className="col-span-2 text-right">In-app</div>
            <div className="col-span-2 text-right">Email</div>
          </div>
          {NOTIFICATION_TYPES.map((row) => (
            <div key={row.key} className="grid grid-cols-12 px-3 py-3 border-b border-subtle last:border-b-0 items-center">
              <div className="col-span-8 pr-2">
                <div className="text-sm font-medium">{row.label}</div>
                <div className="text-xs text-muted">{row.description}</div>
              </div>
              <div className="col-span-2 flex justify-end">
                <Switch
                  checked={isEnabled(row.key, 'in_app')}
                  onChange={handleSwitch(row.key, 'in_app')}
                  aria-label={`Enable in-app for ${row.label}`}
                />
              </div>
              <div className="col-span-2 flex justify-end">
                <Switch
                  checked={isEnabled(row.key, 'email')}
                  onChange={handleSwitch(row.key, 'email')}
                  aria-label={`Enable email for ${row.label}`}
                />
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
