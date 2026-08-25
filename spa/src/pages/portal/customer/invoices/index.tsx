import { useState } from 'react';
import { PortalTable } from '@/components/portal/PortalTable';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { CompanyName } from '@/components/brand/CompanyName';
import { DataTablePagination } from '@/components/ui/DataTablePagination';

export default function CustomerInvoicesPage() {
  const [page, setPage] = useState(1);
  const {
    data: invoicesPage,
    isLoading,
    isError,
    refetch,
  } = useQuery({
    queryKey: ['portal', 'customer', 'invoices', { page }],
    queryFn: () => customerPortalApi.listInvoices({ page }),
    placeholderData: (prev) => prev,
  });
  const invoices = invoicesPage?.data ?? [];

  return (
    <div>
      <PageHeader
        title="Invoices"
        subtitle={
          <>
            Billing documents issued by <CompanyName />
          </>
        }
      />

      {/* One padded body holds every state, so loading and loaded agree on width. */}
      <div className="px-5 py-4 max-w-5xl">
        {isLoading && <SkeletonBlock className="h-64 rounded-md" />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load invoices"
            action={
              <Button variant="secondary" onClick={() => refetch()}>
                Retry
              </Button>
            }
          />
        )}

        {!isLoading && !isError && (
          <Panel noPadding>
            {invoices.length > 0 ? (
              <>
              <PortalTable>
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
                    <tr
                      key={inv.id}
                      className={trCls}
                    >
                      <Td>
                        <Link
                          to={`/portal/customer/invoices/${inv.id}`}
                          className="font-mono text-accent hover:underline font-medium"
                        >
                          {inv.invoice_number}
                        </Link>
                      </Td>
                      <Td className="text-muted">{inv.date ?? '—'}</Td>
                      <Td align="right" mono>
                        {formatPeso(inv.total_amount)}
                      </Td>
                      <Td align="right" mono>
                        {formatPeso(inv.balance)}
                      </Td>
                      <Td className="text-muted">{inv.due_date ?? '—'}</Td>
                      <Td align="right" mono>
                        <Chip variant={chipVariantForStatus(inv.status)}>
                          {inv.status_label ?? inv.status}
                        </Chip>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
</PortalTable>
              {invoicesPage?.meta && (
                <div className="px-4 pb-4">
                  <DataTablePagination meta={invoicesPage.meta} onPageChange={setPage} />
                </div>
              )}
              </>
            ) : (
              <EmptyState
                icon="receipt"
                title="No invoices"
                description="Your invoices will appear here once issued."
              />
            )}
          </Panel>
        )}
      </div>
    </div>
  );
}
