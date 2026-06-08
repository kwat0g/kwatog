import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { bomsApi } from '@/api/mrp/boms';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';

export default function BomDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [deleting, setDeleting] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['mrp', 'boms', 'detail', id],
    queryFn: () => bomsApi.show(id!),
    enabled: !!id,
  });

  const handleDelete = async () => {
    if (!confirm('Delete this BOM? This cannot be undone.')) return;
    setDeleting(true);
    try {
      await bomsApi.delete(id!);
      qc.invalidateQueries({ queryKey: ['mrp', 'boms'] });
      toast.success('BOM deleted.');
      navigate('/mrp/boms');
    } catch {
      toast.error('Failed to delete BOM.');
      setDeleting(false);
    }
  };

  if (isLoading) return <div><PageHeader title="BOM" backTo="/mrp/boms" backLabel="BOMs"
    breadcrumbs={[{ label: 'MRP', href: '/mrp' }, { label: 'BOMs', href: '/mrp/boms' }, { label: 'Loading…' }]} /><SkeletonDetail /></div>;
  if (isError || !data) return (
    <div>
      <PageHeader title="BOM" backTo="/mrp/boms" backLabel="BOMs"
        breadcrumbs={[{ label: 'MRP', href: '/mrp' }, { label: 'BOMs', href: '/mrp/boms' }, { label: 'Error' }]} />
      <EmptyState icon="alert-circle" title="Failed to load BOM"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
    </div>
  );

  return (
    <div>
      <PageHeader
        title={
          <div className="flex items-center gap-3">
            <span className="font-mono">{data.product?.part_number ?? '—'}</span>
            <span>{data.product?.name}</span>
            <Chip variant={data.is_active ? 'success' : 'neutral'}>v{data.version}{data.is_active ? ' · active' : ' · archived'}</Chip>
          </div>
        }
        backTo="/mrp/boms"
        backLabel="BOMs"
        breadcrumbs={[{ label: 'MRP', href: '/mrp' }, { label: 'BOMs', href: '/mrp/boms' }, { label: data.product?.part_number ?? 'BOM' }]}
        actions={
          <div className="flex gap-2">
            <Button variant="secondary" size="sm" onClick={() => navigate(`/mrp/boms/${id}/edit`)}>
              <Pencil className="h-3.5 w-3.5 mr-1" /> Edit
            </Button>
            <Button variant="danger" size="sm" onClick={handleDelete} loading={deleting}>
              <Trash2 className="h-3.5 w-3.5 mr-1" /> Delete
            </Button>
          </div>
        }
      />
      <div className="px-5 py-4 space-y-4">
        <Panel title="Materials" meta={`${data.item_count} ${data.item_count === 1 ? 'line' : 'lines'}`} noPadding>
          <table className="w-full text-xs">
            <thead className="bg-subtle">
              <tr>
                <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2 w-12">#</th>
                <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Item</th>
                <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Qty / unit</th>
                <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">UOM</th>
                <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Waste %</th>
                <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Effective</th>
              </tr>
            </thead>
            <tbody>
              {data.items?.map((m, i) => (
                <tr key={m.id} className="border-t border-subtle hover:bg-subtle">
                  <td className="px-2.5 py-2 font-mono text-muted tabular-nums">{(i + 1).toString().padStart(2, '0')}</td>
                  <td className="px-2.5 py-2">
                    <div className="font-mono">{m.item?.code}</div>
                    <div className="text-xs text-muted">{m.item?.name}</div>
                  </td>
                  <td className="px-2.5 py-2 text-right font-mono tabular-nums">{Number(m.quantity_per_unit).toFixed(4)}</td>
                  <td className="px-2.5 py-2">{m.unit}</td>
                  <td className="px-2.5 py-2 text-right font-mono tabular-nums">{Number(m.waste_factor).toFixed(2)}</td>
                  <td className="px-2.5 py-2 text-right font-mono tabular-nums font-medium">{Number(m.effective_quantity).toFixed(4)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      </div>
    </div>
  );
}
