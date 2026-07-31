import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { formatPeso } from '@/lib/formatNumber';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function SupplierInvoicesPage() {
  const { data: invoices, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'supplier', 'invoices'],
    queryFn: () => supplierPortalApi.listInvoices(),
    placeholderData: (prev) => prev,
  });

  return (
    <div>
      <PageHeader title="Invoices" subtitle="Bills you have issued to Ogami" />

      {/* One padded body holds every state, so loading and loaded agree on width. */}
      <div className="px-5 py-4 max-w-5xl">
        {isLoading && <SkeletonBlock className="h-64 rounded-md" />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load invoices"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}

        {!isLoading && !isError && (
          <Panel noPadding>
            {invoices && invoices.length > 0 ? (
              <table className={tableCls}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>Invoice #</Th>
                    <Th>Date</Th>
                    <Th align="right">Amount</Th>
                    <Th align="right">Balance</Th>
                    <Th>Due</Th>
                    <Th align="right">Status</Th>
                  </tr>
                </thead>
                <tbody>
                  {invoices.map((inv) => (
                    <tr key={inv.id} className={trCls}>
                      <Td>
                        <Link to={`/portal/supplier/invoices/${inv.id}`} className="font-mono text-accent hover:underline font-medium">
                          {inv.bill_number}
                        </Link>
                      </Td>
                      <Td className="text-muted">{inv.date ?? '—'}</Td>
                      <Td align="right" mono>{formatPeso(inv.total_amount)}</Td>
                      <Td align="right" mono>{formatPeso(inv.balance)}</Td>
                      <Td className="text-muted">{inv.due_date ?? '—'}</Td>
                      <Td align="right" mono>
                        <Chip variant={chipVariantForStatus(inv.status)}>{inv.status}</Chip>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <EmptyState icon="receipt" title="No invoices" description="Invoices from your customers will appear here." />
            )}
          </Panel>
        )}
      </div>
    </div>
  );
}
