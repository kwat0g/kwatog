import { PortalTable } from '@/components/portal/PortalTable';
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
import { CompanyName } from '@/components/brand/CompanyName';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { DataTablePagination } from '@/components/ui/DataTablePagination';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import type { SupplierBillSummary } from '@/types/b2b';

type InvoiceFilters = { page: number; per_page: number; status?: string };

export default function SupplierInvoicesPage() {
  const [filters, setFilters] = useUrlFilters<InvoiceFilters>({ page: 1, per_page: 25 });
  const {
    data,
    isLoading,
    isError,
    refetch,
  } = useQuery({
    queryKey: ['portal', 'supplier', 'invoices', filters],
    queryFn: () => supplierPortalApi.listInvoices(filters),
    placeholderData: (prev) => prev,
  });

  const invoices: SupplierBillSummary[] = data?.data ?? [];
  const filterConfig: FilterConfig[] = [{
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { value: '', label: 'All' },
      { value: 'unpaid', label: 'Unpaid' },
      { value: 'partial', label: 'Partially paid' },
      { value: 'paid', label: 'Paid' },
    ],
  }];

  return (
    <div>
      <PageHeader
        title="Invoices"
        subtitle={
          <>
            Bills you have issued to <CompanyName />
          </>
        }
      />

      <FilterBar
        filters={filterConfig}
        values={filters}
        onFilter={(key, value) => setFilters((current) => ({ ...current, [key]: value || undefined, page: 1 }))}
        searchable={false}
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
                          to={`/portal/supplier/invoices/${inv.id}`}
                          className="font-mono text-accent hover:underline font-medium"
                        >
                          {inv.bill_number}
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
            ) : (
              <EmptyState
                icon="receipt"
                title="No invoices"
                description="Invoices you submitted will appear here with their AP review status."
              />
            )}
          </Panel>
        )}
        {data && <DataTablePagination
          meta={data.meta}
          perPage={filters.per_page}
          onPageChange={(page) => setFilters((current) => ({ ...current, page }))}
          onPageSizeChange={(per_page) => setFilters((current) => ({ ...current, per_page, page: 1 }))}
        />}
      </div>
    </div>
  );
}
