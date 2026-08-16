import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { LuPencil, LuTrash2 } from '@/lib/icons';
import toast from 'react-hot-toast';
import { schedulesApi } from '@/api/maintenance/schedules';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';

export default function MaintenanceScheduleDetailPage() {
 const { id = '' } = useParams<{ id: string }>();
 const navigate = useNavigate();
 const qc = useQueryClient();
 const { can } = usePermission();
 const [deleting, setDeleting] = useState(false);
 const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['maintenance', 'schedules', id],
 queryFn: () => schedulesApi.show(id),
 enabled: !!id,
 });

 const handleDelete = async () => {
 setDeleting(true);
 try {
await schedulesApi.destroy(id);
  qc.invalidateQueries({ queryKey: ['maintenance', 'schedules'] });
  toast.success('Schedule archived.');
  navigate('/maintenance/schedules');
 } catch {
 toast.error('Failed to delete schedule.');
 setDeleting(false);
 }
 };

 if (isLoading) return <SkeletonDetail />;
 if (isError || !data) return (
 <EmptyState icon="alert-circle" title="Failed to load schedule"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
 );

 return (
 <div>
 <PageHeader
 title={data.description}
 backTo="/maintenance/schedules"
 backLabel="Schedules"
 actions={
 can('maintenance.schedules.manage') ? (
 <div className="flex gap-2">
 <Button variant="secondary" size="sm" onClick={() => navigate(`/maintenance/schedules/${id}/edit`)}>
 <LuPencil className="h-3.5 w-3.5 mr-1" /> Edit
 </Button>
<Button variant="danger" size="sm" onClick={() => setShowDeleteConfirm(true)} loading={deleting}>
  <LuTrash2 className="h-3.5 w-3.5 mr-1" /> Archive
  </Button>
 </div>
 ) : undefined
 }
 />

 <div className="px-5 pt-3 pb-4 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-2">
 <StatCard label="Target" value={data.maintainable?.name ?? data.maintainable_type} />
 <StatCard label="Interval" value={`${data.interval_value} ${data.interval_type}`} />
 <StatCard label="Last performed" value={data.last_performed_at ? formatDate(data.last_performed_at) : '—'} />
 <StatCard label="Next due" value={data.next_due_at ? formatDate(data.next_due_at) : '—'} />
 </div>

 <div className="px-5 pb-4 space-y-4">
 <Panel title="Details">
 <div className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
 <div>
 <span className="text-muted text-xs">Type</span>
 <div className="font-mono">{data.maintainable_type}</div>
 </div>
 {data.maintainable && (
 <div>
 <span className="text-muted text-xs">Target code</span>
 <div className="font-mono">{data.maintainable.code ?? '—'}</div>
 </div>
 )}
 <div>
 <span className="text-muted text-xs">Status</span>
 <div className="mt-0.5">
 <Chip variant={data.is_active ? 'success' : 'neutral'}>{data.is_active ? 'Active' : 'Disabled'}</Chip>
 </div>
 </div>
 {data.work_orders_count !== undefined && (
 <div>
 <span className="text-muted text-xs">Work orders generated</span>
 <div className="font-mono tabular-nums">{data.work_orders_count}</div>
 </div>
 )}
 </div>
 </Panel>
 </div>

 <ConfirmDialog
 isOpen={showDeleteConfirm}
 onClose={() => setShowDeleteConfirm(false)}
 onConfirm={handleDelete}
 title="Archive this schedule?"
 description="It will be archived and can be restored later from the schedules list."
 variant="danger"
 confirmLabel="Archive"
 pending={deleting}
 />
 </div>
 );
}
