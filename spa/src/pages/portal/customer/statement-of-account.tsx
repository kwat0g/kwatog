import { useQuery } from '@tanstack/react-query';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { formatPeso } from '@/lib/formatNumber';
import { Chip } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

const BUCKET_COLORS: Record<string, string> = {
  current: 'text-success',
  d30_days: 'text-warning',
  d60_days: 'text-warning',
  d90_plus: 'text-danger',
};

export default function StatementOfAccountPage() {
  const { data: soa, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'statement-of-account'],
    queryFn: () => customerPortalApi.getStatementOfAccount(),
  });

  return (
    <div>
      <PageHeader
        title="Statement of Account"
        subtitle={soa ? `${soa.customer.name} · As of ${soa.as_of}` : undefined}
        backTo="/portal/customer"
        backLabel="Portal"
      />

      {/* One padded body holds every state, so loading and loaded agree on width. */}
      <div className="px-5 py-4 space-y-4 max-w-5xl">
        {isLoading && <SkeletonBlock className="h-96 rounded-md" />}

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
            {/* Aging buckets */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
              {Object.entries(soa.aging).map(([key, value]) => (
                <StatCard
                  key={key}
                  label={soa.aging_options.find((option) => option.value === key)?.label ?? key}
                  value={formatPeso(value)}
                  className={BUCKET_COLORS[key]}
                />
              ))}
            </div>

            <div className="text-center p-3 bg-surface border border-default rounded-md">
              <span className="text-2xs uppercase tracking-wide text-muted">Total Outstanding</span>
              <p className="text-xl font-medium font-mono text-primary mt-1">
                {formatPeso(soa.total_outstanding)}
              </p>
            </div>

            {/* Statement ledger */}
            <Panel title={`Transactions (${soa.transactions.length})`} noPadding>
              {soa.transactions.length > 0 ? (
                <table className={tableCls}>
                  <thead>
                    <tr className={theadTrCls}>
                      <Th>Date</Th>
                      <Th>Type</Th>
                      <Th>Reference</Th>
                      <Th>Description</Th>
                      <Th align="right">Amount</Th>
                      <Th align="right">Running Balance</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {soa.transactions.map((transaction, index) => (
                      <tr key={`${transaction.date}-${transaction.reference}-${index}`} className={trCls}>
                        <Td className="text-muted">{transaction.date}</Td>
                        <Td><Chip variant={transaction.type === 'payment' ? 'success' : 'info'}>{transaction.type}</Chip></Td>
                        <Td mono>{transaction.reference}</Td>
                        <Td>{transaction.description}</Td>
                        <Td align="right" mono>{formatPeso(transaction.amount)}</Td>
                        <Td align="right" mono>{formatPeso(transaction.running_balance)}</Td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ) : (
                <EmptyState icon="receipt" title="No statement activity" description="No invoices or payments exist through this date." />
              )}
            </Panel>
          </>
        )}
      </div>
    </div>
  );
}
