/**
 * S2 — Accounting Hub (tab-based pattern)
 *
 * Data dashboard for finance & accounting. Each tab shows real inline data.
 * Config/reference pages (COA, vendors, statements, budgets) accessible via tabs.
 * Workflow pages (invoices, bills, JEs) stay in sidebar.
 */
import { useSearchParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { dashboardsApi } from '@/api/dashboards';
import { accountsApi } from '@/api/accounting/accounts';
import { vendorsApi } from '@/api/accounting/vendors';
import { budgetingApi } from '@/api/accounting/budgeting';
import { PageHeader } from '@/components/layout/PageHeader';
import { TabNavigation, type Tab } from '@/components/ui/TabNavigation';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';

const TABS: Tab[] = [
  { key: 'accounts', label: 'Accounts', to: '/accounting/hub?tab=accounts' },
  { key: 'vendors', label: 'Vendors', to: '/accounting/hub?tab=vendors' },
  { key: 'statements', label: 'Statements', to: '/accounting/hub?tab=statements' },
  { key: 'budgets', label: 'Budgets', to: '/accounting/hub?tab=budgets' },
];

/** Quick-action buttons shown at the top of the hub */
function QuickActions() {
  const quickLinks = [
    { label: 'New JE',           to: '/accounting/journal-entries/create', icon: '📝' },
    { label: 'New Invoice',      to: '/accounting/invoices/create',        icon: '🧾' },
    { label: 'New Bill',         to: '/accounting/bills/create',           icon: '📄' },
    { label: 'Trial Balance',    to: '/accounting/trial-balance',          icon: '⚖️' },
    { label: 'Income Statement', to: '/accounting/income-statement',       icon: '📊' },
    { label: 'Balance Sheet',    to: '/accounting/balance-sheet',          icon: '📈' },
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

export default function AccountingHubPage() {
  const [searchParams] = useSearchParams();
  const activeTab = searchParams.get('tab') ?? 'accounts';

  return (
    <div>
      <PageHeader
        title="Finance & Accounting"
        subtitle="General Ledger & Reporting"
        breadcrumbs={[
          { label: 'Accounting', href: '/accounting/hub' },
          { label: 'Hub' },
        ]}
      />
      <QuickActions />
      <TabNavigation tabs={TABS} defaultKey="accounts" />
      <div className="px-5 py-4">
        {activeTab === 'accounts' && <AccountsTab />}
        {activeTab === 'vendors' && <VendorsTab />}
        {activeTab === 'statements' && <StatementsTab />}
        {activeTab === 'budgets' && <BudgetsTab />}
      </div>
    </div>
  );
}

/* ─── Accounts Tab (COA) ───────────────────────────────── */

function AccountsTab() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['accounting-hub', 'accounts'],
    queryFn: () => accountsApi.list({ per_page: 50, sort: 'account_code', direction: 'asc' }),
    retry: false,
  });

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;

  const accounts = data?.data ?? [];
  const assets = accounts.filter((a: any) => a.account_type === 'asset');
  const liabilities = accounts.filter((a: any) => a.account_type === 'liability');
  const equity = accounts.filter((a: any) => a.account_type === 'equity');
  const revenue = accounts.filter((a: any) => a.account_type === 'revenue');
  const expenses = accounts.filter((a: any) => a.account_type === 'expense');

  return (
    <div className="space-y-4">
      {isError ? (
        <EmptyState icon="alert-circle" title="Could not load accounts"
          action={<Link to="/accounting/coa" className="text-sm text-accent hover:underline">Go to COA →</Link>} />
      ) : accounts.length === 0 ? (
        <EmptyState icon="book-open" title="No accounts configured" description="Add accounts to get started."
          action={<Link to="/accounting/coa" className="text-sm text-accent hover:underline">Manage COA →</Link>} />
      ) : (
        <>
          <div className="grid grid-cols-5 gap-3">
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Assets</p>
              <p className="text-2xl font-semibold mt-1">{assets.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Liabilities</p>
              <p className="text-2xl font-semibold mt-1">{liabilities.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Equity</p>
              <p className="text-2xl font-semibold mt-1">{equity.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Revenue</p>
              <p className="text-2xl font-semibold mt-1">{revenue.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Expenses</p>
              <p className="text-2xl font-semibold mt-1">{expenses.length}</p>
            </div>
          </div>
          <Panel title="Chart of Accounts" actions={<Link to="/accounting/coa" className="text-sm text-accent hover:underline">Manage →</Link>}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-text-subtle">
                    <th className="py-2 pr-3 font-medium">Code</th>
                    <th className="py-2 pr-3 font-medium">Name</th>
                    <th className="py-2 pr-3 font-medium">Type</th>
                    <th className="py-2 pr-3 font-medium">Balance</th>
                    <th className="py-2 font-medium">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {accounts.slice(0, 10).map((a: any) => (
                    <tr key={a.id} className="border-b border-default last:border-0 hover:bg-elevated/50">
                      <td className="py-2 pr-3 font-mono text-xs">{a.account_code}</td>
                      <td className="py-2 pr-3 font-medium">{a.account_name}</td>
                      <td className="py-2 pr-3 text-xs capitalize">{a.account_type?.replace('_', ' ')}</td>
                      <td className="py-2 pr-3 font-mono tabular-nums">
                        {a.current_balance != null
                          ? `₱${parseFloat(a.current_balance).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`
                          : '—'}
                      </td>
                      <td className="py-2">
                        <Chip variant={a.is_active ? 'success' : 'neutral'}>
                          {a.is_active ? 'Active' : 'Inactive'}
                        </Chip>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
          <div className="flex gap-3">
            <Link to="/accounting/coa" className="text-sm text-accent hover:underline">View full COA →</Link>
            <Link to="/accounting/trial-balance" className="text-sm text-accent hover:underline">Trial balance →</Link>
          </div>
        </>
      )}
    </div>
  );
}

/* ─── Vendors Tab ──────────────────────────────────────── */

function VendorsTab() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['accounting-hub', 'vendors'],
    queryFn: () => vendorsApi.list({ per_page: 10, sort: 'name', direction: 'asc' }),
    retry: false,
  });

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;

  const vendors = data?.data ?? [];
  const active = vendors.filter((v: any) => v.is_active);

  return (
    <div className="space-y-4">
      {isError ? (
        <EmptyState icon="alert-circle" title="Could not load vendors"
          action={<Link to="/accounting/vendors" className="text-sm text-accent hover:underline">Go to vendors →</Link>} />
      ) : vendors.length === 0 ? (
        <EmptyState icon="building" title="No vendors configured" description="Add vendors to manage payables."
          action={<Link to="/accounting/vendors" className="text-sm text-accent hover:underline">Manage vendors →</Link>} />
      ) : (
        <>
          <div className="grid grid-cols-3 gap-3">
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Total Vendors</p>
              <p className="text-2xl font-semibold mt-1">{vendors.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Active</p>
              <p className="text-2xl font-semibold mt-1">{active.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Outstanding AP</p>
              <p className="text-2xl font-semibold mt-1 font-mono">
                ₱{vendors.reduce((sum: number, v: any) => sum + parseFloat(v.outstanding_balance ?? '0'), 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
              </p>
            </div>
          </div>
          <Panel title="Vendor Accounts" actions={<Link to="/accounting/vendors" className="text-sm text-accent hover:underline">View all →</Link>}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-text-subtle">
                    <th className="py-2 pr-3 font-medium">Code</th>
                    <th className="py-2 pr-3 font-medium">Name</th>
                    <th className="py-2 pr-3 font-medium">Contact</th>
                    <th className="py-2 pr-3 font-medium">Outstanding</th>
                    <th className="py-2 font-medium">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {vendors.slice(0, 10).map((v: any) => (
                    <tr key={v.id} className="border-b border-default last:border-0 hover:bg-elevated/50">
                      <td className="py-2 pr-3 font-mono text-xs">{v.vendor_code}</td>
                      <td className="py-2 pr-3">
                        <Link to={`/accounting/vendors/${v.id}`} className="text-accent hover:underline font-medium">
                          {v.name}
                        </Link>
                      </td>
                      <td className="py-2 pr-3 text-xs text-secondary">{v.contact_person ?? '—'}</td>
                      <td className="py-2 pr-3 font-mono tabular-nums">
                        {v.outstanding_balance != null
                          ? `₱${parseFloat(v.outstanding_balance).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`
                          : '—'}
                      </td>
                      <td className="py-2">
                        <Chip variant={v.is_active ? 'success' : 'neutral'}>
                          {v.is_active ? 'Active' : 'Inactive'}
                        </Chip>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
          <div className="flex gap-3">
            <Link to="/accounting/vendors" className="text-sm text-accent hover:underline">View all vendors →</Link>
            <Link to="/accounting/vendors/create" className="text-sm text-accent hover:underline">New vendor →</Link>
          </div>
        </>
      )}
    </div>
  );
}

/* ─── Statements Tab ───────────────────────────────────── */

function StatementsTab() {
  const { data, isLoading } = useQuery({
    queryKey: ['accounting-hub', 'statements-summary'],
    queryFn: () => dashboardsApi.accounting(),
    retry: false,
  });

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;

  const kpis = data?.kpis ?? [];
  const arBalance = kpis.find((k: any) => k.label === 'AR Balance')?.value ?? '₱0.00';
  const apBalance = kpis.find((k: any) => k.label === 'AP Balance')?.value ?? '₱0.00';
  const unpostedJes = kpis.find((k: any) => k.label === 'Unposted JEs')?.value ?? '0';

  const statements = [
    {
      title: 'Trial Balance',
      description: 'Account balances as of current date',
      to: '/accounting/trial-balance',
      icon: '⚖️',
    },
    {
      title: 'Income Statement',
      description: 'Revenue and expenses (P&L)',
      to: '/accounting/income-statement',
      icon: '📊',
    },
    {
      title: 'Balance Sheet',
      description: 'Assets, liabilities, and equity',
      to: '/accounting/balance-sheet',
      icon: '📈',
    },
    {
      title: 'Cash Flow',
      description: 'Operating, investing, and financing activities',
      to: '/accounting/cash-flow',
      icon: '💰',
    },
  ];

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-3 gap-3">
        <div className="rounded-lg border border-default p-3">
          <p className="text-2xs text-text-subtle uppercase tracking-wider">AR Balance</p>
          <p className="text-2xl font-semibold mt-1 font-mono">{arBalance}</p>
        </div>
        <div className="rounded-lg border border-default p-3">
          <p className="text-2xs text-text-subtle uppercase tracking-wider">AP Balance</p>
          <p className="text-2xl font-semibold mt-1 font-mono">{apBalance}</p>
        </div>
        <div className="rounded-lg border border-default p-3">
          <p className="text-2xs text-text-subtle uppercase tracking-wider">Unposted JEs</p>
          <p className="text-2xl font-semibold mt-1">{unpostedJes}</p>
        </div>
      </div>

      <Panel title="Financial Statements">
        <div className="grid grid-cols-2 gap-3">
          {statements.map((stmt) => (
            <Link
              key={stmt.to}
              to={stmt.to}
              className="p-4 border border-default rounded-md hover:bg-elevated hover:border-accent transition-all duration-fast"
            >
              <div className="flex items-start gap-3">
                <span className="text-2xl" aria-hidden>{stmt.icon}</span>
                <div className="flex-1">
                  <h4 className="font-semibold text-sm text-primary mb-0.5">{stmt.title}</h4>
                  <p className="text-xs text-secondary">{stmt.description}</p>
                </div>
              </div>
            </Link>
          ))}
        </div>
      </Panel>

      <div className="flex gap-3">
        <Link to="/accounting/journal-entries" className="text-sm text-accent hover:underline">View journal entries →</Link>
        <Link to="/accounting/coa" className="text-sm text-accent hover:underline">Manage COA →</Link>
      </div>
    </div>
  );
}

/* ─── Budgets Tab ──────────────────────────────────────── */

function BudgetsTab() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['accounting-hub', 'budgets'],
    queryFn: () => budgetingApi.list({ per_page: 10 }),
    retry: false,
  });

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;

  const budgets = data?.data ?? [];
  const active = budgets.filter((b: any) => b.status === 'active');
  const totalBudget = active.reduce((sum: number, b: any) => sum + parseFloat(b.total_amount ?? '0'), 0);
  const totalUtilized = active.reduce((sum: number, b: any) => sum + parseFloat(b.utilized_amount ?? '0'), 0);
  const utilizationPct = totalBudget > 0 ? ((totalUtilized / totalBudget) * 100).toFixed(1) : '0.0';

  return (
    <div className="space-y-4">
      {isError ? (
        <EmptyState icon="alert-circle" title="Could not load budgets"
          action={<Link to="/budgeting" className="text-sm text-accent hover:underline">Go to budgets →</Link>} />
      ) : budgets.length === 0 ? (
        <EmptyState icon="trending-up" title="No budgets configured" description="Create budgets to track spending."
          action={<Link to="/budgeting" className="text-sm text-accent hover:underline">Manage budgets →</Link>} />
      ) : (
        <>
          <div className="grid grid-cols-4 gap-3">
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Total Budgets</p>
              <p className="text-2xl font-semibold mt-1">{budgets.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Active</p>
              <p className="text-2xl font-semibold mt-1">{active.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Total Allocated</p>
              <p className="text-2xl font-semibold mt-1 font-mono">
                ₱{totalBudget.toLocaleString('en-PH', { minimumFractionDigits: 2 })}
              </p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Utilization</p>
              <p className="text-2xl font-semibold mt-1">{utilizationPct}%</p>
            </div>
          </div>
          <Panel title="Budget Overview" actions={<Link to="/budgeting" className="text-sm text-accent hover:underline">View all →</Link>}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-text-subtle">
                    <th className="py-2 pr-3 font-medium">Fiscal Year</th>
                    <th className="py-2 pr-3 font-medium">Name</th>
                    <th className="py-2 pr-3 font-medium">Allocated</th>
                    <th className="py-2 pr-3 font-medium">Utilized</th>
                    <th className="py-2 font-medium">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {budgets.slice(0, 10).map((b: any) => (
                    <tr key={b.id} className="border-b border-default last:border-0 hover:bg-elevated/50">
                      <td className="py-2 pr-3 font-mono text-xs">{b.fiscal_year}</td>
                      <td className="py-2 pr-3">
                        <Link to={`/budgeting/${b.id}`} className="text-accent hover:underline font-medium">
                          {b.name}
                        </Link>
                      </td>
                      <td className="py-2 pr-3 font-mono tabular-nums">
                        ₱{parseFloat(b.total_amount ?? '0').toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                      </td>
                      <td className="py-2 pr-3 font-mono tabular-nums">
                        ₱{parseFloat(b.utilized_amount ?? '0').toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                      </td>
                      <td className="py-2">
                        <Chip variant={b.status === 'active' ? 'success' : b.status === 'draft' ? 'warning' : 'neutral'}>
                          {b.status}
                        </Chip>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
          <div className="flex gap-3">
            <Link to="/budgeting" className="text-sm text-accent hover:underline">View all budgets →</Link>
            <Link to="/budgeting/create" className="text-sm text-accent hover:underline">New budget →</Link>
          </div>
        </>
      )}
    </div>
  );
}
