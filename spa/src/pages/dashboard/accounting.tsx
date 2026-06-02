import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { usePermission } from '@/hooks/usePermission';
import { dashboardsApi } from '@/api/dashboards';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { SkeletonDetail, SkeletonBlock } from '@/components/ui/Skeleton';
import { formatPeso } from '@/lib/formatNumber';
import { ChainBottleneckWidget } from '@/components/dashboard/ChainBottleneckWidget';

/* ─── Typed interface ─── */

interface JeEntry {
  id: string;
  entry_number?: string | null;
  status: string;
  total_debit: string;
  date: string;
}

interface ArOverdueCustomer {
  customer_id: string;
  total_overdue: string;
}

interface AccountingDashboardData {
  kpis: Array<{ label: string; value: string; unit: string }>;
  panels: {
    recent_jes?: JeEntry[];
    top_overdue_ar?: ArOverdueCustomer[];
  };
}

/* ─── Helpers ─── */

function statusVariant(status: string): 'neutral' | 'success' | 'warning' | 'danger' | 'info' {
  switch (status) {
    case 'posted':
      return 'success';
    case 'draft':
      return 'warning';
    case 'void':
      return 'danger';
    default:
      return 'neutral';
  }
}

function statusLabel(status: string): string {
  switch (status) {
    case 'posted':
      return 'Posted';
    case 'draft':
      return 'Draft';
    case 'void':
      return 'Void';
    default:
      return status;
  }
}

/* ─── Sub-panels ─── */

function KpiRow({ kpis }: { kpis: AccountingDashboardData['kpis'] }) {
  return (
    <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
      {kpis.map((k) => (
        <div
          key={k.label}
          className="rounded-lg border bg-white p-4 shadow-xs"
          aria-label={`${k.label}: ${k.value} ${k.unit}`}
        >
          <p className="text-xs font-medium text-muted uppercase tracking-wide">{k.label}</p>
          <p className="mt-1 text-2xl font-bold font-mono tabular-nums text-primary">
            {k.unit === 'PHP' ? formatPeso(Number(k.value)) : k.value}
            <span className="ml-1 text-xs font-normal text-muted">{k.unit === 'PHP' ? '' : k.unit}</span>
          </p>
        </div>
      ))}
    </div>
  );
}

function RecentJePanel({ entries }: { entries: JeEntry[] }) {
  if (!entries || entries.length === 0) {
    return (
      <Panel title="Recent Journal Entries">
        <p className="text-sm text-muted py-4 text-center">No journal entries yet.</p>
      </Panel>
    );
  }

  return (
    <Panel
      title="Recent Journal Entries"
      actions={
        <Link className="text-xs text-link hover:underline" to="/accounting/journal-entries">
          All JEs →
        </Link>
      }
    >
      <div className="space-y-2">
        {entries.map((je) => (
          <Link
            key={je.id}
            to={`/accounting/journal-entries/${je.id}`}
            className="flex items-center justify-between rounded-md px-3 py-2 hover:bg-muted/40 transition-colors"
            aria-label={`JE ${je.entry_number ?? je.id}: ${statusLabel(je.status)}, ${formatPeso(Number(je.total_debit))}`}
          >
            <div className="flex items-center gap-2 min-w-0">
              <span className="text-sm font-medium truncate">{je.entry_number ?? '—'}</span>
              <Chip variant={statusVariant(je.status)}>{statusLabel(je.status)}</Chip>
            </div>
            <div className="flex items-center gap-3 shrink-0">
              <span className="text-sm font-mono tabular-nums">{formatPeso(Number(je.total_debit))}</span>
              <span className="text-xs text-muted">{je.date}</span>
            </div>
          </Link>
        ))}
      </div>
    </Panel>
  );
}

function TopOverdueArPanel({ customers }: { customers: ArOverdueCustomer[] }) {
  if (!customers || customers.length === 0) {
    return (
      <Panel title="Top Overdue AR">
        <p className="text-sm text-muted py-4 text-center">No overdue receivables.</p>
      </Panel>
    );
  }

  return (
    <Panel
      title="Top Overdue AR"
      actions={
        <Link className="text-xs text-link hover:underline" to="/accounting/invoices">
          All Invoices →
        </Link>
      }
    >
      <div className="space-y-2">
        {customers.map((c) => (
          <Link
            key={c.customer_id}
            to={`/accounting/customers/${c.customer_id}`}
            className="flex items-center justify-between rounded-md px-3 py-2 hover:bg-muted/40 transition-colors"
            aria-label={`Customer overdue: ${formatPeso(Number(c.total_overdue))}`}
          >
            <span className="text-sm text-muted">Customer</span>
            <span className="text-sm font-mono tabular-nums font-semibold text-danger">
              {formatPeso(Number(c.total_overdue))}
            </span>
          </Link>
        ))}
      </div>
    </Panel>
  );
}

/* ─── Page ─── */

export default function AccountingDashboard() {
  const { can } = usePermission();

  const q = useQuery({
    queryKey: ['dashboard', 'accounting'],
    queryFn: (): Promise<AccountingDashboardData> =>
      dashboardsApi.accounting() as Promise<AccountingDashboardData>,
    refetchInterval: 60_000,
  });

  if (q.isLoading && !q.data) {
    return (
      <div className="space-y-5 px-5 py-6">
        <SkeletonBlock className="h-8 w-64" />
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <SkeletonBlock key={i} className="h-24 rounded-lg" />
          ))}
        </div>
        <SkeletonDetail />
      </div>
    );
  }

  if (q.isError || !q.data) {
    return (
      <div className="px-5 py-6">
        <EmptyState
          icon="alert-circle"
          title="Could not load dashboard"
          description="There was a problem fetching your accounting overview. Please try again."
          action={<Button variant="secondary" onClick={() => q.refetch()}>Retry</Button>}
        />
      </div>
    );
  }

  const { kpis, panels } = q.data;

  return (
    <div className="space-y-5 px-5 py-6" aria-label="Accounting dashboard">
      <h1 className="text-lg font-semibold text-primary">Accounting Overview</h1>

      {/* Row 1: KPIs */}
      <KpiRow kpis={kpis} />

      {/* Row 2: Recent JEs + Overdue AR */}
      <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <RecentJePanel entries={panels.recent_jes ?? []} />
        <TopOverdueArPanel customers={panels.top_overdue_ar ?? []} />
      </div>

      {/* Row 3: Bottleneck widget (permission-gated, self-fetching) */}
      {can('dashboard.view_bottlenecks') && (
        <ChainBottleneckWidget audience="finance_officer" hideWhenEmpty />
      )}
    </div>
  );
}
