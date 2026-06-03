import { useQuery } from '@tanstack/react-query';
import { BookOpen, FileText, Receipt, FileBarChart, Users, Building, BarChart, TrendingUp } from 'lucide-react';
import { HubPage, HubCard, NavTile, type HubStat } from '@/components/hub';
import { Chip } from '@/components/ui/Chip';
import { Spinner } from '@/components/ui/Spinner';
import { Link } from 'react-router-dom';
import { dashboardsApi } from '@/api/dashboards';

export default function AccountingHubPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['accounting', 'hub'],
    queryFn: () => dashboardsApi.accounting(),
    refetchInterval: 60_000,
  });

  const arBalance = data?.kpis?.find((k: any) => k.label === 'AR Balance')?.value ?? '₱0.00';
  const apBalance = data?.kpis?.find((k: any) => k.label === 'AP Balance')?.value ?? '₱0.00';
  const unpostedJes = data?.kpis?.find((k: any) => k.label === 'Unposted JEs')?.value ?? '0';
  const budgetUtilization = data?.kpis?.find((k: any) => k.label === 'Budget Utilization')?.value ?? '0';

  const stats: HubStat[] = [
    { label: 'AR Balance', value: arBalance, linkTo: '/accounting/ar' },
    { label: 'AP Balance', value: apBalance, linkTo: '/accounting/ap' },
    { label: 'Unposted JEs', value: unpostedJes, linkTo: '/accounting/journal-entries' },
    { label: 'Budget Utilization', value: `${budgetUtilization}%`, linkTo: '/accounting/budgets' },
  ];

  const recentJes = (data?.panels?.recent_jes as any[]) ?? [];
  const arAgingSummary = (data?.panels?.ar_aging as any[]) ?? [];

  return (
    <HubPage title="Finance & Accounting" subtitle="General ledger, AP/AR, and financial reporting" breadcrumbs={[{ label: 'Accounting' }]} stats={isLoading ? undefined : stats}>
      {isLoading ? (
        <div className="flex justify-center py-12"><Spinner /></div>
      ) : (
        <>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <HubCard title="Recent Journal Entries" icon={FileText} viewAllHref="/accounting/journal-entries">
              {recentJes.length === 0 ? (
                <p className="text-sm text-muted">No recent journal entries.</p>
              ) : (
                <div className="space-y-2">
                  {recentJes.slice(0, 5).map((je: any) => (
                    <div key={je.id} className="flex items-center justify-between text-sm">
                      <Link to={`/accounting/journal-entries/${je.id}`} className="text-accent hover:underline">{je.je_no}</Link>
                      <Chip variant={je.status === 'posted' ? 'success' : je.status === 'draft' ? 'warning' : 'neutral'} >{je.status}</Chip>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>

            <HubCard title="AR Aging Summary" icon={Receipt} viewAllHref="/accounting/ar">
              {arAgingSummary.length === 0 ? (
                <p className="text-sm text-muted">No AR aging data.</p>
              ) : (
                <div className="space-y-2">
                  {arAgingSummary.slice(0, 5).map((item: any, idx: number) => (
                    <div key={idx} className="flex items-center justify-between text-sm">
                      <span className="text-primary">{item.bucket}</span>
                      <span className="font-mono tabular-nums text-muted">{item.amount}</span>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>
          </div>

          <div>
            <h3 className="text-xs font-medium text-muted uppercase tracking-wider mb-3">All Sections</h3>
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
              <NavTile to="/accounting/coa" icon={BookOpen} label="COA" description="Chart of accounts" />
              <NavTile to="/accounting/journal-entries" icon={FileText} label="Journal Entries" description="Manual and auto-generated JEs" />
              <NavTile to="/accounting/invoices" icon={Receipt} label="Invoices" description="Customer invoices" />
              <NavTile to="/accounting/bills" icon={FileBarChart} label="Bills" description="Supplier bills" />
              <NavTile to="/accounting/vendors" icon={Building} label="Vendors" description="Supplier accounts" />
              <NavTile to="/accounting/customers" icon={Users} label="Customers" description="Customer accounts" />
              <NavTile to="/accounting/trial-balance" icon={BarChart} label="Trial Balance" description="Account balances" />
              <NavTile to="/accounting/income-statement" icon={TrendingUp} label="Income Statement" description="P&L report" />
              <NavTile to="/accounting/balance-sheet" icon={FileBarChart} label="Balance Sheet" description="Assets, liabilities, equity" />
              <NavTile to="/accounting/budgets" icon={TrendingUp} label="Budgets" description="Budget planning and tracking" />
            </div>
          </div>
        </>
      )}
    </HubPage>
  );
}
