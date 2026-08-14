import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LuPencil, LuTrash2, LuArchiveRestore, LuRefreshCw } from '@/lib/icons';
import toast from 'react-hot-toast';
import { bomsApi } from '@/api/mrp/boms';
import { formatPeso } from '@/lib/formatNumber';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function BomDetailPage() {
 const { id } = useParams<{ id: string }>();
 const navigate = useNavigate();
 const qc = useQueryClient();
 const [deleting, setDeleting] = useState(false);
 const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
 const [restoring, setRestoring] = useState(false);

 const recalculate = useMutation({
 mutationFn: () => bomsApi.recalculate(id!),
 onSuccess: (bom) => {
 qc.setQueryData(['mrp', 'boms', 'detail', id], bom);
 qc.invalidateQueries({ queryKey: ['mrp', 'boms'] });
 toast.success('BOM cost recalculated.');
 },
 onError: () => toast.error('Failed to recalculate BOM cost.'),
 });

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
  toast.success('BOM archived.');
  navigate('/mrp/boms');
  } catch {
  toast.error('Failed to archive BOM.');
  setDeleting(false);
  }
 };

 const handleRestore = async () => {
  setRestoring(true);
  try {
  await bomsApi.restore(id!);
  qc.invalidateQueries({ queryKey: ['mrp', 'boms'] });
  toast.success('BOM restored.');
  } catch {
  toast.error('Failed to restore BOM.');
  } finally {
  setRestoring(false);
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
  <Button variant="secondary" size="sm" onClick={() => recalculate.mutate()} loading={recalculate.isPending}>
  <LuRefreshCw className={`h-3.5 w-3.5 mr-1 ${recalculate.isPending ? 'animate-spin' : ''}`} /> Recalculate cost
  </Button>
  <Button variant="secondary" size="sm" onClick={() => navigate(`/mrp/boms/${id}/edit`)}>
  <LuPencil className="h-3.5 w-3.5 mr-1" /> Edit
  </Button>
  {data.deleted_at && <Button variant="secondary" size="sm" onClick={handleRestore} loading={restoring}>
  <LuArchiveRestore className="h-3.5 w-3.5 mr-1" /> Restore
  </Button>}
  {!data.deleted_at && <Button variant="danger" size="sm" onClick={() => setShowDeleteConfirm(true)} loading={deleting}>
  <LuTrash2 className="h-3.5 w-3.5 mr-1" /> Archive
  </Button>}
  </div>
  }
 />
<ConfirmDialog
  isOpen={showDeleteConfirm}
  onClose={() => setShowDeleteConfirm(false)}
  onConfirm={handleDelete}
  title="Archive this BOM?"
  description="It will be archived and can be restored later."
  variant="danger"
  confirmLabel="Archive"
  pending={deleting}
  />

 <div className="px-5 py-4 space-y-4">
 <Panel title="Costing" meta={data.costed_at ? `Updated ${data.costed_at.slice(0, 16).replace('T', ' ')}` : 'Not calculated'}>
 <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
 <div>
 <div className="text-xs uppercase tracking-wider text-muted">Material cost / unit</div>
 <div className="mt-1 text-xl font-mono font-medium">{formatPeso(data.material_cost)}</div>
 </div>
 <div>
 <div className="text-xs uppercase tracking-wider text-muted">Cost basis</div>
 <div className="mt-1 text-sm">{data.cost_basis ?? '—'}</div>
 </div>
 <div>
 <div className="text-xs uppercase tracking-wider text-muted">Warnings</div>
 <div className="mt-1 text-sm">{data.cost_warnings?.length ? `${data.cost_warnings.length} item${data.cost_warnings.length === 1 ? '' : 's'} at zero cost` : 'None'}</div>
 </div>
 </div>
 {data.cost_warnings?.length > 0 && <div className="mt-4 space-y-1 text-xs text-warning-fg">
 {data.cost_warnings.map((warning) => <div key={`${warning.type}-${warning.item_code}`}>{warning.message}</div>)}
 </div>}
 </Panel>
 <Panel title="Materials" meta={`${data.item_count} ${data.item_count === 1 ? 'line' : 'lines'}`} noPadding>
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th className="w-12">#</Th>
 <Th>Item</Th>
 <Th align="right">Qty / unit</Th>
 <Th>UOM</Th>
 <Th align="right">Waste %</Th>
 <Th align="right">Effective</Th>
 <Th align="right">Cost qty</Th>
 <Th align="right">Unit cost</Th>
 <Th align="right">Extended</Th>
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
 <Td align="right" mono>{m.cost_quantity === null ? '—' : Number(m.cost_quantity).toFixed(6)}</Td>
 <Td align="right" mono>{m.unit_cost === null ? '—' : formatPeso(m.unit_cost)}</Td>
 <Td align="right" mono className="font-medium">{m.extended_cost === null ? '—' : formatPeso(m.extended_cost)}</Td>
 </tr>
 ))}
 </tbody>
 </table>
 </Panel>
 </div>
 </div>
 );
}
