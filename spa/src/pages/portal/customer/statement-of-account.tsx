import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { formatPeso } from '@/lib/formatNumber';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

const BUCKET_LABELS: Record<string, string> = {
  current: 'Current',
  d1_30: '1–30 Days',
  d31_60: '31–60 Days',
  d61_90: '61–90 Days',
  d91_plus: '91+ Days',
};

const BUCKET_COLORS: Record<string, string> = {
  current: 'text-success',
  d1_30: 'text-warning',
  d31_60: 'text-warning',
  d61_90: 'text-danger',
  d91_plus: 'text-danger',
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
        subtitle={soa ? `${soa.customer_name ?? 'Customer'} · As of ${soa.as_of_date}` : undefined}
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
            <div className="grid grid-cols-1 md:grid-cols-5 gap-3">
              {Object.entries(soa.aging_buckets).map(([key, value]) => (
                <StatCard
                  key={key}
                  label={BUCKET_LABELS[key] ?? key}
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

            {/* Open Invoices */}
            <Panel title={`Open Invoices (${soa.open_invoices.length})`} noPadding>
              {soa.open_invoices.length > 0 ? (
                <table className={tableCls}>
                  <thead>
                    <tr className={theadTrCls}>
                      <Th>Invoice #</Th>
                      <Th>Date</Th>
                      <Th>Due Date</Th>
                      <Th align="right">Amount</Th>
                      <Th align="right">Balance</Th>
                      <Th align="right">Status</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {soa.open_invoices.map((inv) => (
                      <tr key={inv.id} className={trCls}>
                        <Td>
                          <Link to={`/portal/customer/invoices/${inv.id}`} className="font-mono text-accent hover:underline font-medium">
                            {inv.invoice_number}
                          </Link>
                        </Td>
                        <Td className="text-muted">{inv.date ?? '—'}</Td>
                        <Td className="text-muted">{inv.due_date ?? '—'}</Td>
                        <Td align="right" mono>{formatPeso(inv.total_amount)}</Td>
                        <Td align="right" mono>{formatPeso(inv.balance)}</Td>
                        <Td align="right" mono>
                          <Chip variant={chipVariantForStatus(inv.status)}>{inv.status}</Chip>
                        </Td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ) : (
                <EmptyState icon="receipt" title="All invoices are paid" description="No outstanding invoices at this time." />
              )}
            </Panel>
          </>
        )}
      </div>
    </div>
  );
}
