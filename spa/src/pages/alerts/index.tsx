/** Task A2 — Smart Alert Engine list page. */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import {
  AlertTriangle, AlertCircle, Info, X, Boxes, Factory, Wrench, Receipt, FileText,
  ShieldCheck, Clock,
} from 'lucide-react';
import { alertsApi } from '@/api/alerts';
import type { Alert, AlertListParams, AlertSeverity, AlertType } from '@/types/alerts';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/formatDate';

const TYPE_LABEL: Record<AlertType, string> = {
  stock_critical:      'Stock critical',
  stock_low:           'Stock low',
  no_supplier:         'No supplier',
  machine_breakdown:   'Machine breakdown',
  mold_shot_limit:     'Mold approaching limit',
  mold_shot_critical:  'Mold critical limit',
  wo_overdue:          'Work order overdue',
  oee_below_threshold: 'OEE below threshold',
  ar_overdue_30:       'AR overdue 30+ days',
  ar_overdue_60:       'AR overdue 60+ days',
  ap_due_soon:         'AP due soon',
  qc_fail_rate_high:   'QC fail rate high',
};

const TYPE_ICON: Record<AlertType, typeof AlertCircle> = {
  stock_critical:      Boxes,
  stock_low:           Boxes,
  no_supplier:         Boxes,
  machine_breakdown:   Factory,
  mold_shot_limit:     Wrench,
  mold_shot_critical:  Wrench,
  wo_overdue:          Clock,
  oee_below_threshold: Factory,
  ar_overdue_30:       Receipt,
  ar_overdue_60:       Receipt,
  ap_due_soon:         FileText,
  qc_fail_rate_high:   ShieldCheck,
};

const severityVariant = (s: AlertSeverity): 'danger' | 'warning' | 'info' =>
  s === 'critical' ? 'danger' : s === 'warning' ? 'warning' : 'info';

const severityIcon = (s: AlertSeverity) =>
  s === 'critical' ? AlertTriangle : s === 'warning' ? AlertCircle : Info;

const SEVERITIES: { value: AlertSeverity; label: string }[] = [
  { value: 'critical', label: 'Critical' },
  { value: 'warning',  label: 'Warning'  },
  { value: 'info',     label: 'Info'     },
];

export default function AlertsListPage() {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [filters, setFilters] = useState<AlertListParams>({
    page: 1, per_page: 50, is_dismissed: false,
  });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['alerts', filters],
    queryFn: () => alertsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const dismiss = useMutation({
    mutationFn: (id: string) => alertsApi.dismiss(id),
    onSuccess: () => {
      toast.success('Alert dismissed');
      queryClient.invalidateQueries({ queryKey: ['alerts'] });
      queryClient.invalidateQueries({ queryKey: ['alerts', 'unread-count'] });
    },
    onError: () => toast.error('Failed to dismiss alert'),
  });

  const toggleSeverity = (sev: AlertSeverity) => {
    setFilters((f) => {
      const cur = f.severity ?? [];
      const next = cur.includes(sev) ? cur.filter((s) => s !== sev) : [...cur, sev];
      return { ...f, severity: next.length ? next : undefined, page: 1 };
    });
  };

  const groups = (data?.data ?? []).reduce<Record<AlertSeverity, Alert[]>>(
    (acc, a) => {
      (acc[a.severity] ||= []).push(a);
      return acc;
    },
    { critical: [], warning: [], info: [] },
  );

  const total = data?.meta.total ?? 0;

  return (
    <div>
      <PageHeader
        title="Alerts"
        subtitle={data ? `${total} active ${total === 1 ? 'alert' : 'alerts'}` : undefined}
      />

      <div className="px-5 pb-3 flex items-center gap-2 flex-wrap">
        {SEVERITIES.map((s) => {
          const active = (filters.severity ?? []).includes(s.value);
          return (
            <button
              key={s.value}
              onClick={() => toggleSeverity(s.value)}
              className={`px-2 py-1 text-xs rounded-md border transition-colors ${
                active
                  ? 'border-default bg-elevated text-default'
                  : 'border-subtle text-muted hover:border-default hover:text-default'
              }`}
              aria-pressed={active}
            >
              <span className="flex items-center gap-1.5">
                <Chip variant={severityVariant(s.value)}>{s.label.toLowerCase()}</Chip>
              </span>
            </button>
          );
        })}
        <span className="text-2xs text-subtle ml-2">
          Showing {filters.is_dismissed ? 'dismissed' : 'active'} alerts
        </span>
        <button
          onClick={() => setFilters((f) => ({ ...f, is_dismissed: !f.is_dismissed, page: 1 }))}
          className="ml-auto text-xs text-muted hover:text-default underline-offset-2 hover:underline"
        >
          {filters.is_dismissed ? 'Show active' : 'Show dismissed'}
        </button>
      </div>

      {isLoading && !data && <SkeletonTable columns={1} rows={6} />}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load alerts"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {data && data.data.length === 0 && (
        <EmptyState
          icon="alert-circle"
          title={filters.is_dismissed ? 'No dismissed alerts' : 'No active alerts'}
          description={
            filters.is_dismissed
              ? 'Dismissed alerts appear here for audit reference.'
              : 'The system is healthy. Alerts will appear here when thresholds are crossed.'
          }
        />
      )}

      {data && data.data.length > 0 && (
        <div className="px-5 pb-6 space-y-4">
          {(['critical', 'warning', 'info'] as AlertSeverity[]).map((sev) => {
            const items = groups[sev];
            if (!items.length) return null;
            const SevIcon = severityIcon(sev);
            return (
              <section key={sev}>
                <h2 className="text-2xs uppercase tracking-wider text-muted font-medium mb-2 flex items-center gap-2">
                  <SevIcon size={11} />
                  <span>{sev}</span>
                  <span className="font-mono tabular-nums text-subtle">·</span>
                  <span className="font-mono tabular-nums text-subtle">{items.length}</span>
                </h2>
                <div className="rounded-md border border-subtle divide-y divide-subtle bg-canvas">
                  {items.map((a) => {
                    const Icon = TYPE_ICON[a.type] ?? AlertCircle;
                    return (
                      <div
                        key={a.id}
                        className={`flex items-start gap-3 px-3 py-2.5 ${a.is_read ? '' : 'bg-elevated/40'}`}
                      >
                        <Icon
                          size={14}
                          className={`mt-0.5 shrink-0 ${
                            a.severity === 'critical'
                              ? 'text-danger-fg'
                              : a.severity === 'warning'
                                ? 'text-warning-fg'
                                : 'text-muted'
                          }`}
                        />
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-2 flex-wrap">
                            <span className="text-sm font-medium text-default">{a.title}</span>
                            <Chip variant="neutral">{TYPE_LABEL[a.type] ?? a.type}</Chip>
                            {!a.is_read && !a.is_dismissed && (
                              <Chip variant="info">new</Chip>
                            )}
                            {a.is_dismissed && a.dismissed_at && (
                              <span className="text-2xs text-subtle">
                                dismissed {formatDateTime(a.dismissed_at)}
                              </span>
                            )}
                          </div>
                          <p className="text-xs text-muted mt-0.5">{a.message}</p>
                          {a.entity && (
                            <p className="text-2xs text-subtle font-mono mt-0.5">
                              {a.entity.type} · {a.entity.label}
                            </p>
                          )}
                          <p className="text-2xs text-subtle font-mono tabular-nums mt-0.5">
                            {formatDateTime(a.created_at)}
                          </p>
                        </div>
                        {!a.is_dismissed && can('alerts.dismiss') && (
                          <Button
                            variant="secondary"
                            size="sm"
                            icon={<X size={12} />}
                            onClick={() => dismiss.mutate(a.id)}
                            disabled={dismiss.isPending}
                            aria-label={`Dismiss alert ${a.title}`}
                          >
                            Dismiss
                          </Button>
                        )}
                      </div>
                    );
                  })}
                </div>
              </section>
            );
          })}
        </div>
      )}
    </div>
  );
}
