import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { supplierRfqsApi } from '@/api/purchasing/rfqs';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDate } from '@/lib/formatDate';

export default function SupplierRfqsPage() {
  const query = useQuery({ queryKey: ['portal', 'supplier', 'rfqs'], queryFn: () => supplierRfqsApi.list(), placeholderData: (previous) => previous });
  if (query.isLoading && !query.data) return <SkeletonTable columns={4} rows={6} />;
  if (query.isError) return <EmptyState icon="alert-circle" title="Supplier RFQs unavailable" action={<Button onClick={() => query.refetch()}>Retry</Button>} />;
  const rows = query.data?.data ?? [];
  return <div><PageHeader title="Supplier RFQs" subtitle="Private sourcing invitations from Ogami" />{rows.length === 0 ? <EmptyState icon="file-text" title="No RFQ invitations" description="New invitations will appear here when Ogami opens a sourcing event for your company." /> : <div className="px-5 py-4 grid gap-3">{rows.map((row) => <Link key={row.id} to={`/portal/supplier/rfqs/${row.id}`} className="block rounded-md border border-default bg-surface p-4 hover:bg-elevated"><div className="flex items-start justify-between gap-3"><div><div className="font-mono text-sm">{row.rfq_number}</div><div className="font-medium mt-1">{row.title}</div><div className="text-xs text-muted mt-1">Deadline <span className="font-mono">{formatDate(row.closes_at)}</span></div></div><Chip variant={chipVariantForStatus(row.status)}>{row.status.replace(/_/g, ' ')}</Chip></div></Link>)}</div>}</div>;
}
