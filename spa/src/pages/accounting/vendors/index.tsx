import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { vendorsApi, type VendorListParams } from '@/api/accounting/vendors';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import type { Vendor } from '@/types/accounting';

export default function VendorsPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<VendorListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['accounting', 'vendors', filters],
    queryFn: () => vendorsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<Vendor>[] = [
    { key: 'name', header: 'Vendor', cell: (r) => <Link to={`/accounting/vendors/${r.id}`} className="text-accent hover:underline font-medium">{r.name}</Link> },
    { key: 'contact', header: 'Contact', cell: (r) => r.contact_person ?? '—' },
    { key: 'phone', header: 'Phone', cell: (r) => <span className="font-mono">{r.phone ?? '—'}</span> },
    { key: 'terms', header: 'Terms', align: 'right', cell: (r) => <NumCell>{r.payment_terms_days}d</NumCell> },
    { key: 'open_balance', header: 'Open balance', align: 'right', cell: (r) => <NumCell className="font-medium">{formatPeso(r.open_balance ?? '0')}</NumCell> },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={r.is_active ? 'success' : 'neutral'}>{r.is_active ? 'active' : 'inactive'}</Chip> },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'is_active', label: 'Status', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'true',  label: 'Active' },
        { value: 'false', label: 'Inactive' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Vendors"
        subtitle={data ? `${data.meta.total} vendors` : undefined}
        actions={can('accounting.vendors.manage') ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/accounting/vendors/create')}>
            New vendor
          </Button>
        ) : null}
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by name or contact…"
      />
      {isLoading && !data && <SkeletonTable columns={6} rows={6} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load vendors" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState icon="inbox" title="No vendors yet" description={can('accounting.vendors.manage') ? 'Add your first vendor to record bills.' : 'Nothing here yet.'}
          action={can('accounting.vendors.manage') ? <Button variant="primary" onClick={() => navigate('/accounting/vendors/create')}>New vendor</Button> : undefined} />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4"><DataTable columns={columns} data={data.data} meta={data.meta} onPageChange={(page) => setFilters((f) => ({ ...f, page }))} /></div>
      )}
    </div>
  );
}
