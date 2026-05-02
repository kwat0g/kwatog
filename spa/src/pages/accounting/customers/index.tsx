import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { customersApi, type CustomerListParams } from '@/api/accounting/customers';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import type { Customer } from '@/types/accounting';

export default function CustomersPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<CustomerListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['accounting', 'customers', filters],
    queryFn: () => customersApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<Customer>[] = [
    { key: 'name', header: 'Customer', cell: (r) => <Link to={`/accounting/customers/${r.id}`} className="text-accent hover:underline font-medium">{r.name}</Link> },
    { key: 'contact', header: 'Contact', cell: (r) => r.contact_person ?? '—' },
    { key: 'phone', header: 'Phone', cell: (r) => <span className="font-mono">{r.phone ?? '—'}</span> },
    { key: 'terms', header: 'Terms', align: 'right', cell: (r) => <NumCell>{r.payment_terms_days}d</NumCell> },
    { key: 'credit_limit', header: 'Credit limit', align: 'right', cell: (r) => <NumCell>{r.credit_limit ? formatPeso(r.credit_limit) : '—'}</NumCell> },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={r.is_active ? 'success' : 'neutral'}>{r.is_active ? 'active' : 'inactive'}</Chip> },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'is_active', label: 'Status', type: 'select',
      options: [{ value: '', label: 'All' }, { value: 'true', label: 'Active' }, { value: 'false', label: 'Inactive' }],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Customers"
        subtitle={data ? `${data.meta.total} customers` : undefined}
        actions={can('accounting.customers.manage') ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/accounting/customers/create')}>New customer</Button>
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
      {isError && <EmptyState icon="alert-circle" title="Failed to load customers" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState icon="inbox" title="No customers yet" description={can('accounting.customers.manage') ? 'Add your first customer to issue invoices.' : 'Nothing here yet.'}
          action={can('accounting.customers.manage') ? <Button variant="primary" onClick={() => navigate('/accounting/customers/create')}>New customer</Button> : undefined} />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4"><DataTable columns={columns} data={data.data} meta={data.meta} onPageChange={(page) => setFilters((f) => ({ ...f, page }))} /></div>
      )}
    </div>
  );
}
