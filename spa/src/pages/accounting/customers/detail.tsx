import { Link, useNavigate, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Pencil, Plus } from 'lucide-react';
import { customersApi } from '@/api/accounting/customers';
import { invoicesApi } from '@/api/accounting/invoices';
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
import type { Invoice } from '@/types/accounting';

export default function CustomerDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { can } = usePermission();

  const { data: customer, isLoading, isError, refetch } = useQuery({
    queryKey: ['accounting', 'customers', id],
    queryFn: () => customersApi.show(id),
    enabled: !!id,
  });
  const { data: invoicesData } = useQuery({
    queryKey: ['accounting', 'invoices', { customer_id: id }],
    queryFn: () => invoicesApi.list({ customer_id: id, per_page: 50 }),
    enabled: !!id,
  });

  if (isLoading || (!customer && !isError)) return <SkeletonDetail />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load customer" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
  if (!customer) return null;

  const invoiceColumns: Column<Invoice>[] = [
    { key: 'invoice_number', header: 'Invoice no',
      cell: (r) => <Link to={`/accounting/invoices/${r.id}`} className="font-mono text-accent hover:underline">{r.invoice_number ?? 'DRAFT'}</Link> },
    { key: 'date',     header: 'Date', cell: (r) => <NumCell>{formatDate(r.date)}</NumCell> },
    { key: 'due_date', header: 'Due',  cell: (r) => <NumCell>{formatDate(r.due_date)}</NumCell> },
    { key: 'total',    header: 'Total', align: 'right', cell: (r) => <NumCell>{formatPeso(r.total_amount)}</NumCell> },
    { key: 'balance',  header: 'Balance', align: 'right', cell: (r) => <NumCell className="font-medium">{formatPeso(r.balance)}</NumCell> },
    { key: 'status',   header: 'Status', cell: (r) => <Chip variant={chipVariantForStatus(r.display_status)}>{r.display_status}</Chip> },
  ];

  const overLimit = customer.credit_limit && customer.credit_used && Number(customer.credit_used) > 0.8 * Number(customer.credit_limit);

  return (
    <div>
      <PageHeader
        title={
          <div className="flex items-center gap-3">
            <span>{customer.name}</span>
            <Chip variant={customer.is_active ? 'success' : 'neutral'}>{customer.is_active ? 'active' : 'inactive'}</Chip>
            {overLimit && <Chip variant="danger">credit warning</Chip>}
          </div>
        }
        backTo="/accounting/customers"
        backLabel="Customers"
        actions={
          <div className="flex gap-1.5">
            {can('accounting.invoices.create') && (
              <Button variant="secondary" size="sm" icon={<Plus size={14} />} onClick={() => navigate(`/accounting/invoices/create?customer_id=${customer.id}`)}>New invoice</Button>
            )}
            {can('accounting.customers.manage') && (
              <Button variant="primary" size="sm" icon={<Pencil size={14} />} onClick={() => navigate(`/accounting/customers/${customer.id}/edit`)}>Edit</Button>
            )}
          </div>
        }
      />

      <div className="px-5 py-4 grid grid-cols-3 gap-4">
        <StatCard label="Credit Limit" value={customer.credit_limit ? formatPeso(customer.credit_limit) : '—'} />
        <StatCard label="Credit Used" value={formatPeso(customer.credit_used ?? '0')} />
        <StatCard label="Available"
          value={customer.credit_available !== null ? formatPeso(customer.credit_available ?? '0') : '—'}
          delta={overLimit ? { value: '> 80% used', direction: 'down' } : undefined} />
      </div>

      <div className="px-5 grid grid-cols-3 gap-4">
        <Panel title="Contact" className="col-span-1">
          <dl className="text-xs space-y-2">
            <div><dt className="text-muted">Contact person</dt><dd>{customer.contact_person ?? '—'}</dd></div>
            <div><dt className="text-muted">Email</dt><dd>{customer.email ?? '—'}</dd></div>
            <div><dt className="text-muted">Phone</dt><dd className="font-mono">{customer.phone ?? '—'}</dd></div>
            <div><dt className="text-muted">TIN</dt><dd className="font-mono">{customer.tin ?? '—'}</dd></div>
            <div><dt className="text-muted">Address</dt><dd>{customer.address ?? '—'}</dd></div>
            <div><dt className="text-muted">Payment terms</dt><dd className="font-mono">{customer.payment_terms_days} days</dd></div>
          </dl>
        </Panel>
        <Panel title="Invoices" className="col-span-2">
          {invoicesData && invoicesData.data.length > 0
            ? <DataTable columns={invoiceColumns} data={invoicesData.data} meta={invoicesData.meta} />
            : <EmptyState icon="inbox" title="No invoices yet" />}
        </Panel>
      </div>
    </div>
  );
}
