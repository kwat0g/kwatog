import type { ChangeEvent } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { client } from '@/api/client';
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
  { key: 'leave.submitted',         label: 'Leave submitted',     description: 'A subordinate filed a leave request awaiting your approval.' },
  { key: 'leave.approved',          label: 'Leave approved',      description: 'Your leave request was approved.' },
  { key: 'leave.rejected',          label: 'Leave rejected',      description: 'Your leave request was rejected.' },
  { key: 'payroll.finalized',       label: 'Payroll finalized',   description: 'A payroll period has been finalized.' },
  { key: 'pr.urgent',               label: 'Urgent purchase request', description: 'Auto-generated PR flagged urgent due to low stock.' },
  { key: 'wo.completed',            label: 'Work order completed',description: 'A work order you supervise has finished.' },
  { key: 'machine.breakdown',       label: 'Machine breakdown',   description: 'A machine entered breakdown status.' },
  { key: 'maintenance.assigned',    label: 'Maintenance assigned',description: 'You were assigned a maintenance work order.' },
  { key: 'ncr.opened',              label: 'NCR opened',          description: 'A new non-conformance report was opened.' },
  { key: 'mold.shot_limit',         label: 'Mold shot limit',     description: 'A mold reached its preventive shot threshold.' },
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
      <div className="px-4 py-4 space-y-2">
        {[1, 2, 3, 4, 5].map((i) => <SkeletonBlock key={i} className="h-14 rounded-md" />)}
      </div>
    );
  }

  return (
    <div className="px-4 py-4">
      <h1 className="text-lg font-medium mb-3">Notification Preferences</h1>
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
  );
}
