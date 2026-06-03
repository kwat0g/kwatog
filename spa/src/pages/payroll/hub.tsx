/**
 * S1 — Payroll Hub
 *
 * Supporting feature hub. Periods tab shows real payroll period data
 * inline; Adjustments and Gov Tables tabs show summaries with deep links.
 */
import { useSearchParams, Link } from 'react-router-dom';
import { useQuery, useQueries } from '@tanstack/react-query';
import { periodsApi } from '@/api/payroll/periods';
import { adjustmentsApi } from '@/api/payroll/adjustments';
import { govTablesApi } from '@/api/admin/gov-tables';
import { PageHeader } from '@/components/layout/PageHeader';
import { TabNavigation, type Tab } from '@/components/ui/TabNavigation';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import type { ContributionAgency, GovernmentTable } from '@/types/payroll';

const TABS: Tab[] = [
  { key: 'periods', label: 'Payroll Periods', to: '/payroll/hub?tab=periods' },
  { key: 'adjustments', label: 'Adjustments', to: '/payroll/hub?tab=adjustments' },
  { key: 'gov-tables', label: 'Gov. Tables', to: '/payroll/hub?tab=gov-tables' },
];

const PERIOD_STATUS_VARIANT: Record<string, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
  draft: 'neutral',
  computed: 'info',
  hr_approved: 'warning',
  confirmed: 'success',
  disbursed: 'success',
  rejected: 'danger',
};

/** ── Quick-action buttons shown at the top of the hub ── */
function QuickActions() {
  const quickLinks = [
    { label: 'All Periods',     to: '/payroll/periods',          icon: '📆' },
    { label: 'New Period',      to: '/payroll/periods/create',   icon: '➕' },
    { label: 'Pipeline',        to: '/payroll/pipeline',         icon: '🔬' },
    { label: 'Adjustments',     to: '/payroll/adjustments',      icon: '⚖️' },
    { label: 'Gov Tables',      to: '/admin/gov-tables',         icon: '📊' },
  ];
  return (
    <div className="px-5 pt-4 pb-2">
      <div className="flex items-center gap-2 flex-wrap">
        {quickLinks.map((link) => (
          <Link
            key={link.to}
            to={link.to}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs rounded-md border border-default bg-canvas text-secondary hover:bg-elevated hover:text-primary hover:border-accent transition-all duration-fast"
          >
            <span aria-hidden>{link.icon}</span>
            {link.label}
          </Link>
        ))}
      </div>
    </div>
  );
}

export default function PayrollHubPage() {
  const [searchParams] = useSearchParams();
  const activeTab = searchParams.get('tab') ?? 'periods';

  return (
    <div>
      <PageHeader
        title="Payroll"
        subtitle="Payroll & Benefits"
        breadcrumbs={[
          { label: 'Payroll' },
        ]}
      />
      <QuickActions />
      <TabNavigation tabs={TABS} defaultKey="periods" />
      <div className="px-5 py-4">
        {activeTab === 'periods' && <PeriodsTab />}
        {activeTab === 'adjustments' && <AdjustmentsTab />}
        {activeTab === 'gov-tables' && <GovTablesTab />}
      </div>
    </div>
  );
}

/* ─── Periods Tab ──────────────────────────────────────── */

function PeriodsTab() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['payroll-hub', 'periods'],
    queryFn: () => periodsApi.list({ per_page: 10 }),
    retry: false,
  });

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;
  if (isError || !data?.data?.length) {
    return (
      <EmptyState icon="receipt" title="No payroll periods" description="Create a payroll period to get started."
        action={<Link to="/payroll/periods/create" className="text-sm text-accent hover:underline">Create payroll period →</Link>} />
    );
  }

  return (
    <div className="space-y-4">
      <Panel title="Payroll Periods" actions={<Link to="/payroll/periods" className="text-sm text-accent hover:underline">View all →</Link>}>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-text-subtle">
                <th className="py-2 pr-3 font-medium">Period</th>
                <th className="py-2 pr-3 font-medium">Date Range</th>
                <th className="py-2 pr-3 font-medium">Employees</th>
                <th className="py-2 pr-3 font-medium">Gross</th>
                <th className="py-2 pr-3 font-medium">Status</th>
              </tr>
            </thead>
            <tbody>
              {data.data.slice(0, 10).map((p: any) => (
                <tr key={p.id} className="border-b border-default last:border-0 hover:bg-elevated/50">
                  <td className="py-2 pr-3">
                    <Link to={`/payroll/periods/${p.id}`} className="text-accent hover:underline font-medium">
                      {p.label ?? `Payroll #${p.id.slice(0, 8)}`}
                    </Link>
                  </td>
                  <td className="py-2 pr-3 text-secondary font-mono text-xs">
                    {p.start_date?.slice(0, 10)} — {p.end_date?.slice(0, 10)}
                  </td>
                  <td className="py-2 pr-3">{p.employee_count ?? '—'}</td>
                  <td className="py-2 pr-3 font-mono tabular-nums">
                    {p.gross_total ? `₱ ${Number(p.gross_total).toLocaleString()}` : '—'}
                  </td>
                  <td className="py-2 pr-3">
                    <Chip variant={PERIOD_STATUS_VARIANT[p.status] ?? 'neutral'} >
                      {(p.status ?? 'draft').replace(/_/g, ' ')}
                    </Chip>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
      <div className="flex gap-3">
        <Link to="/payroll/periods" className="text-sm text-accent hover:underline">View all periods →</Link>
        <Link to="/payroll/periods/create" className="text-sm text-accent hover:underline">New period →</Link>
      </div>
    </div>
  );
}

/* ─── Adjustments Tab ──────────────────────────────────── */

function AdjustmentsTab() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['payroll-hub', 'adjustments'],
    queryFn: () => adjustmentsApi.list({ per_page: 10, sort: 'created_at', direction: 'desc' }),
    retry: false,
  });

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;

  const adjustments = data?.data ?? [];
  const pendingCount = adjustments.filter((a: any) => a.status === 'pending').length;
  const approvedCount = adjustments.filter((a: any) => a.status === 'approved' || a.status === 'applied').length;

  return (
    <div className="space-y-4">
      {isError ? (
        <EmptyState icon="alert-circle" title="Could not load adjustments"
          action={<Link to="/payroll/adjustments" className="text-sm text-accent hover:underline">Go to adjustments →</Link>} />
      ) : adjustments.length === 0 ? (
        <EmptyState icon="inbox" title="No adjustments" description="Adjustments are raised against finalized payroll periods."
          action={<Link to="/payroll/adjustments/create" className="text-sm text-accent hover:underline">Raise adjustment →</Link>} />
      ) : (
        <>
          <div className="grid grid-cols-3 gap-3">
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Total</p>
              <p className="text-2xl font-semibold mt-1">{adjustments.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Pending</p>
              <p className="text-2xl font-semibold mt-1 text-warning-fg">{pendingCount}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Approved</p>
              <p className="text-2xl font-semibold mt-1 text-success-fg">{approvedCount}</p>
            </div>
          </div>
          <Panel title="Recent Adjustments" actions={<Link to="/payroll/adjustments" className="text-sm text-accent hover:underline">View all →</Link>}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-text-subtle">
                    <th className="py-2 pr-3 font-medium">Employee</th>
                    <th className="py-2 pr-3 font-medium">Type</th>
                    <th className="py-2 pr-3 font-medium text-right">Amount</th>
                    <th className="py-2 pr-3 font-medium">Period</th>
                    <th className="py-2 font-medium">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {adjustments.slice(0, 10).map((a: any) => (
                    <tr key={a.id} className="border-b border-default last:border-0 hover:bg-elevated/50">
                      <td className="py-2 pr-3">
                        <span className="font-medium">{a.employee?.full_name ?? '—'}</span>
                      </td>
                      <td className="py-2 pr-3">
                        <Chip variant={a.type === 'underpayment' ? 'info' : 'warning'}>
                          {a.type_label ?? a.type}
                        </Chip>
                      </td>
                      <td className="py-2 pr-3 text-right font-mono tabular-nums font-medium">
                        {formatPeso(a.amount)}
                      </td>
                      <td className="py-2 pr-3 text-xs text-muted">{a.period?.label ?? '—'}</td>
                      <td className="py-2">
                        <Chip variant={a.status === 'approved' || a.status === 'applied' ? 'success' : a.status === 'rejected' ? 'danger' : 'warning'}>
                          {a.status_label ?? a.status}
                        </Chip>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
          <div className="flex gap-3">
            <Link to="/payroll/adjustments" className="text-sm text-accent hover:underline">View all adjustments →</Link>
            <Link to="/payroll/adjustments/create" className="text-sm text-accent hover:underline">New adjustment →</Link>
          </div>
        </>
      )}
    </div>
  );
}

/* ─── Gov Tables Tab ───────────────────────────────────── */

const AGENCIES: { key: ContributionAgency; label: string; description: string }[] = [
  { key: 'sss',        label: 'SSS',        description: 'Social Security System' },
  { key: 'philhealth', label: 'PhilHealth', description: 'Philippine Health Insurance' },
  { key: 'pagibig',    label: 'Pag-IBIG',   description: 'Home Development Mutual Fund' },
  { key: 'bir',        label: 'BIR Tax',    description: 'Bureau of Internal Revenue' },
];

function GovTablesTab() {
  const results = useQueries({
    queries: AGENCIES.map((a) => ({
      queryKey: ['payroll-hub', 'gov-tables', a.key],
      queryFn: () => govTablesApi.list(a.key),
      retry: false,
      staleTime: 60_000,
    })),
  });

  const isLoading = results.some((q) => q.isLoading);
  const isError = results.some((q) => q.isError);

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;

  return (
    <div className="space-y-4">
      {isError && (
        <EmptyState icon="alert-circle" title="Could not load some government tables"
          action={<Link to="/admin/gov-tables" className="text-sm text-accent hover:underline">Manage tables →</Link>} />
      )}
      <div className="grid grid-cols-2 gap-3">
        {AGENCIES.map((a, idx) => {
          const rows: GovernmentTable[] = results[idx]?.data ?? [];
          const active = rows.filter((r) => r.is_active);
          const latest = active.length > 0
            ? active.reduce((latest, r) => r.effective_date > latest.effective_date ? r : latest)
            : null;

          return (
            <div key={a.key} className="rounded-lg border border-default p-4">
              <div className="flex items-start justify-between mb-2">
                <div>
                  <h4 className="text-sm font-medium">{a.label}</h4>
                  <p className="text-2xs text-text-subtle">{a.description}</p>
                </div>
                <Chip variant={active.length > 0 ? 'success' : 'neutral'}>
                  {active.length > 0 ? `${active.length} active` : 'No data'}
                </Chip>
              </div>
              <div className="flex gap-4 text-2xs text-muted mt-2">
                {latest && (
                  <>
                    <span>Latest: {formatDate(latest.effective_date)}</span>
                    <span>Brackets: {rows.length}</span>
                  </>
                )}
                {!latest && <span className="italic">No active brackets</span>}
              </div>
              <div className="mt-2">
                <Link to="/admin/gov-tables" className="text-xs text-accent hover:underline">
                  Update {a.label} tables →
                </Link>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
