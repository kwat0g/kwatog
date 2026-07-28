import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { bomsApi } from '@/api/mrp/boms';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { Td, Th, tableCls, trCls } from '@/components/ui/table-cells';

export default function BomDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [deleting, setDeleting] = useState(false);
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['mrp', 'boms', 'detail', id],
    queryFn: () => bomsApi.show(id!),
    enabled: !!id,
  });

  const handleDelete = async () => {
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
            <Button variant="danger" size="sm" onClick={() => setShowDeleteConfirm(true)} loading={deleting}>
              <Trash2 className="h-3.5 w-3.5 mr-1" /> Delete
            </Button>
          </div>
        }
      />
      <ConfirmDialog
        isOpen={showDeleteConfirm}
        onClose={() => setShowDeleteConfirm(false)}
        onConfirm={handleDelete}
        title="Delete this BOM?"
        description="This cannot be undone."
        variant="danger"
        confirmLabel="Delete"
        pending={deleting}
      />

      <div className="px-5 py-4 space-y-4">
        <Panel title="Materials" meta={`${data.item_count} ${data.item_count === 1 ? 'line' : 'lines'}`} noPadding>
          <table className={tableCls}>
            <thead>
              <tr>
                <Th className="w-12">#</Th>
                <Th>Item</Th>
                <Th align="right">Qty / unit</Th>
                <Th>UOM</Th>
                <Th align="right">Waste %</Th>
                <Th align="right">Effective</Th>
              </tr>
            </thead>
            <tbody>
              {data.items?.map((m, i) => (
                <tr key={m.id} className={trCls}>
                  <Td mono className="text-muted">{(i + 1).toString().padStart(2, '0')}</Td>
                  <Td>
                    <div className="font-mono">{m.item?.code}</div>
                    <div className="text-xs text-muted">{m.item?.name}</div>
                  </Td>
                  <Td align="right" mono>{Number(m.quantity_per_unit).toFixed(4)}</Td>
                  <Td>{m.unit}</Td>
                  <Td align="right" mono>{Number(m.waste_factor).toFixed(2)}</Td>
                  <Td align="right" mono className="font-medium">{Number(m.effective_quantity).toFixed(4)}</Td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      </div>
    </div>
  );
}
