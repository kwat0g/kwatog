import { useQuery } from '@tanstack/react-query';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { Button } from '@/components/ui/Button';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { StatCard } from '@/components/ui/StatCard';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { KpiGrid } from '@/components/dashboard/DashboardShell';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { cn } from '@/lib/cn';
import type { VendorStatementOfAccount } from '@/types/b2b';

const bucketColors: Record<string, string> = {
  current: 'text-success-fg',
  d1_30: 'text-warning-fg',
  d31_60: 'text-warning-fg',
  d61_90: 'text-danger-fg',
  d91_plus: 'text-danger-fg',
};

type OpenBill = VendorStatementOfAccount['open_bills'][number];

export default function SupplierStatementOfAccountPage() {
  const { data: soa, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'supplier', 'statement-of-account'],
    queryFn: () => supplierPortalApi.statementOfAccount(),
  });

  const bucketLabels = new Map<string, string>(
    (soa?.aging_bucket_options ?? []).map((option) => [option.value, option.label]),
  );

  const billColumns: Column<OpenBill>[] = [
    { key: 'bill_number', header: 'Bill #', cell: (r) => <span className="font-mono font-medium">{r.bill_number}</span> },
    {
      key: 'po',
      header: 'PO',
      cell: (r) =>
        r.purchase_order ? <span className="font-mono text-accent">{r.purchase_order.po_number}</span> : '—',
    },
    { key: 'date', header: 'Date', cell: (r) => <span className="font-mono">{r.date ? formatDate(r.date) : '—'}</span> },
    {
      key: 'due_date',
      header: 'Due Date',
      cell: (r) => (
        <span className={cn('font-mono', r.is_overdue && 'text-danger-fg')}>
          {r.due_date ? formatDate(r.due_date) : '—'}
        </span>
      ),
    },
    { key: 'total_amount', header: 'Total', align: 'right', cell: (r) => <NumCell>{formatPeso(r.total_amount)}</NumCell> },
    { key: 'balance', header: 'Balance', align: 'right', cell: (r) => <NumCell className="font-medium">{formatPeso(r.balance)}</NumCell> },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => <Chip variant={chipVariantForStatus(r.status)}>{r.status_label ?? r.status}</Chip>,
    },
    {
      key: 'aging_bucket',
      header: 'Bucket',
      cell: (r) => (
        <span className={cn('text-2xs font-medium', bucketColors[r.aging_bucket] ?? 'text-muted')}>
          {bucketLabels.get(r.aging_bucket) ?? r.aging_bucket}
        </span>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        title="Statement of Account"
        subtitle={soa ? `${soa.vendor_name ?? 'Vendor'} · As of ${formatDate(soa.as_of_date)}` : 'Your outstanding balances and aging'}
        backTo="/portal/supplier"
        backLabel="Portal"
      />

      <div className="px-5 py-4 space-y-4">
        {isLoading && <SkeletonDetail />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Could not load statement"
            description="Failed to load the statement of account. Please try again."
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}

        {!isLoading && !isError && !soa && (
          <EmptyState icon="receipt" title="Statement not available" />
        )}

        {!isLoading && !isError && soa && (
          <>
            <KpiGrid count={soa.aging_bucket_options.length + 1}>
              <StatCard label="Total Outstanding" value={formatPeso(soa.total_outstanding)} />
              {soa.aging_bucket_options.map((option) => (
                <StatCard
                  key={option.value}
                  label={option.label}
                  value={formatPeso(soa.aging_buckets[option.value])}
                />
              ))}
            </KpiGrid>

            <DataTable
              tableKey="portal-supplier-open-bills"
              columns={billColumns}
              data={soa.open_bills}
              emptyState={
                <EmptyState icon="circle-check" title="No open bills" description="All bills are paid." />
              }
            />
          </>
        )}
      </div>
    </div>
  );
}
