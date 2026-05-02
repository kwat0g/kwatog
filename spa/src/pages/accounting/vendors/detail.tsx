import { useNavigate, useParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Pencil, Plus } from 'lucide-react';
import { vendorsApi } from '@/api/accounting/vendors';
import { billsApi } from '@/api/accounting/bills';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import type { Bill } from '@/types/accounting';

export default function VendorDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { can } = usePermission();

  const { data: vendor, isLoading, isError, refetch } = useQuery({
    queryKey: ['accounting', 'vendors', id],
    queryFn: () => vendorsApi.show(id),
    enabled: !!id,
  });
  const { data: billsData } = useQuery({
    queryKey: ['accounting', 'bills', { vendor_id: id }],
    queryFn: () => billsApi.list({ vendor_id: id, per_page: 50 }),
    enabled: !!id,
  });

  if (isLoading || (!vendor && !isError)) return <SkeletonDetail />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load vendor" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
  if (!vendor) return null;

  const billColumns: Column<Bill>[] = [
    { key: 'bill_number', header: 'Bill no', cell: (r) => <Link to={`/accounting/bills/${r.id}`} className="font-mono text-accent hover:underline">{r.bill_number}</Link> },
    { key: 'date',        header: 'Date',     cell: (r) => <NumCell>{formatDate(r.date)}</NumCell> },
    { key: 'due_date',    header: 'Due',      cell: (r) => <NumCell>{formatDate(r.due_date)}</NumCell> },
    { key: 'total',       header: 'Total', align: 'right', cell: (r) => <NumCell>{formatPeso(r.total_amount)}</NumCell> },
    { key: 'balance',     header: 'Balance', align: 'right', cell: (r) => <NumCell className="font-medium">{formatPeso(r.balance)}</NumCell> },
    { key: 'status',      header: 'Status', cell: (r) => <Chip variant={chipVariantForStatus(r.status)}>{r.status}</Chip> },
  ];

  return (
    <div>
      <PageHeader
        title={
          <div className="flex items-center gap-3">
            <span>{vendor.name}</span>
            <Chip variant={vendor.is_active ? 'success' : 'neutral'}>{vendor.is_active ? 'active' : 'inactive'}</Chip>
          </div>
        }
        backTo="/accounting/vendors"
        backLabel="Vendors"
        actions={
          <div className="flex gap-1.5">
            {can('accounting.bills.create') && (
              <Button variant="secondary" size="sm" icon={<Plus size={14} />} onClick={() => navigate(`/accounting/bills/create?vendor_id=${vendor.id}`)}>
                New bill
              </Button>
            )}
            {can('accounting.vendors.manage') && (
              <Button variant="primary" size="sm" icon={<Pencil size={14} />} onClick={() => navigate(`/accounting/vendors/${vendor.id}/edit`)}>
                Edit
              </Button>
            )}
          </div>
        }
      />

      <div className="px-5 py-4 grid grid-cols-3 gap-4">
        <StatCard label="Open Balance" value={formatPeso(vendor.open_balance ?? '0')} />
        <StatCard label="Payment Terms" value={`${vendor.payment_terms_days} days`} />
        <StatCard label="Bills" value={String(vendor.bills_count ?? billsData?.meta.total ?? 0)} />
      </div>

      <div className="px-5 grid grid-cols-3 gap-4">
        <Panel title="Contact" className="col-span-1">
          <dl className="text-xs space-y-2">
            <div><dt className="text-muted">Contact person</dt><dd>{vendor.contact_person ?? '—'}</dd></div>
            <div><dt className="text-muted">Email</dt><dd>{vendor.email ?? '—'}</dd></div>
            <div><dt className="text-muted">Phone</dt><dd className="font-mono">{vendor.phone ?? '—'}</dd></div>
            <div><dt className="text-muted">TIN</dt><dd className="font-mono">{vendor.tin ?? '—'}</dd></div>
            <div><dt className="text-muted">Address</dt><dd>{vendor.address ?? '—'}</dd></div>
          </dl>
        </Panel>
        <Panel title="Bills" className="col-span-2">
          {billsData && billsData.data.length > 0
            ? <DataTable columns={billColumns} data={billsData.data} meta={billsData.meta} />
            : <EmptyState icon="inbox" title="No bills yet" />}
        </Panel>
      </div>
    </div>
  );
}
