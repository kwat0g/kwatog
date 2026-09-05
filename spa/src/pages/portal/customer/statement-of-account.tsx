import { useQuery } from '@tanstack/react-query';
import { customerPortalApi } from '@/api/b2b/customer';
import { Button } from '@/components/ui/Button';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { StatCard } from '@/components/ui/StatCard';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { Chip } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { KpiGrid } from '@/components/dashboard/DashboardShell';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import type { StatementOfAccount } from '@/types/b2b';

type StatementTransaction = StatementOfAccount['transactions'][number];

export default function StatementOfAccountPage() {
  const { data: soa, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'statement-of-account'],
    queryFn: () => customerPortalApi.getStatementOfAccount(),
  });

  const transactionColumns: Column<StatementTransaction>[] = [
    { key: 'date', header: 'Date', cell: (r) => <span className="font-mono">{formatDate(r.date)}</span> },
    {
      key: 'type',
      header: 'Type',
      cell: (r) => (
        <Chip variant={r.type === 'payment' ? 'success' : 'info'}>{r.type}</Chip>
      ),
    },
    { key: 'reference', header: 'Reference', cell: (r) => <span className="font-mono">{r.reference}</span> },
    { key: 'description', header: 'Description', cell: (r) => r.description },
    { key: 'amount', header: 'Amount', align: 'right', cell: (r) => <NumCell>{formatPeso(r.amount)}</NumCell> },
    {
      key: 'running_balance',
      header: 'Running Balance',
      align: 'right',
      cell: (r) => <NumCell className="font-medium">{formatPeso(r.running_balance)}</NumCell>,
    },
  ];

  return (
    <div>
      <PageHeader
        title="Statement of Account"
        subtitle={soa ? `${soa.customer.name} · As of ${formatDate(soa.as_of)}` : 'Your outstanding balances and activity'}
        backTo="/portal/customer"
        backLabel="Portal"
      />

      <div className="px-5 py-4 space-y-4">
        {isLoading && <SkeletonDetail />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load statement"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}

        {!isLoading && !isError && !soa && (
          <EmptyState icon="receipt" title="Statement not available" />
        )}

        {!isLoading && !isError && soa && (
          <>
            <KpiGrid count={soa.aging_options.length + 1}>
              <StatCard label="Total Outstanding" value={formatPeso(soa.total_outstanding)} />
              {soa.aging_options.map((option) => (
                <StatCard
                  key={option.value}
                  label={option.label}
                  value={formatPeso(soa.aging[option.value])}
                />
              ))}
            </KpiGrid>

            <DataTable
              tableKey="portal-customer-statement"
              columns={transactionColumns}
              data={soa.transactions}
              emptyState={
                <EmptyState
                  icon="receipt"
                  title="No statement activity"
                  description="No invoices or payments exist through this date."
                />
              }
            />
          </>
        )}
      </div>
    </div>
  );
}
