import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { supplierRfqsApi } from '@/api/purchasing/rfqs';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDate } from '@/lib/formatDate';

export default function SupplierRfqDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const query = useQuery({ queryKey: ['portal', 'supplier', 'rfqs', id], queryFn: () => supplierRfqsApi.show(id), enabled: !!id });
  if (query.isLoading) return <SkeletonTable columns={4} rows={6} />;
  if (query.isError || !query.data) return <EmptyState icon="alert-circle" title="RFQ invitation unavailable" action={<Button onClick={() => query.refetch()}>Retry</Button>} />;
  const rfq = query.data;
  const canQuote = rfq.status === 'open';
  return <div><PageHeader title={<span className="font-mono">{rfq.rfq_number}</span>} subtitle={rfq.title} backTo="/portal/supplier/rfqs" backLabel="Supplier RFQs" actions={<Chip variant={chipVariantForStatus(rfq.status)}>{rfq.status.replace(/_/g, ' ')}</Chip>} /><div className="px-5 py-4 max-w-5xl space-y-4"><Panel title="Invitation"><p className="text-sm text-muted">{rfq.instructions || 'Review the requirements and submit your quotation before the deadline.'}</p><div className="mt-3 text-sm">Deadline <span className="font-mono">{formatDate(rfq.closes_at)}</span></div></Panel><Panel title="Requirements"><div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-muted"><th className="py-2">Material / requirement</th><th className="py-2">Quantity</th><th className="py-2">Required date</th></tr></thead><tbody>{rfq.items?.map((item) => <tr key={item.id} className="border-b border-subtle"><td className="py-2">{item.description}<div className="text-xs text-muted">{item.item?.code ?? 'Exact specification'}</div></td><td className="py-2 font-mono">{item.quantity} {item.unit ?? ''}</td><td className="py-2 font-mono">{item.required_delivery_date ? formatDate(item.required_delivery_date) : '—'}</td></tr>)}</tbody></table></div></Panel>{canQuote ? <Link to={`/portal/supplier/rfqs/${id}/quote`}><Button variant="primary">Prepare quotation</Button></Link> : <p className="text-sm text-muted">This RFQ is no longer accepting submissions.</p>}</div></div>;
}
