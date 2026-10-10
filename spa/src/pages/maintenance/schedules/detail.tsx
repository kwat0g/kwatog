import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
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
import { formatDate, formatDateTime } from '@/lib/formatDate';
import { reportMutationError } from '@/lib/formErrors';
import type { MaintenanceWindowSuggestion } from '@/types/maintenance';

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
 const canManage = can('maintenance.schedules.manage');
 const canAssign = can('maintenance.wo.assign');
 const windows = useQuery({
 queryKey: ['maintenance', 'schedules', id, 'window-suggestions'],
 queryFn: () => schedulesApi.windowSuggestions(id),
 enabled: !!id && canManage,
 });
 const confirmWindow = useMutation({
 mutationFn: (slot: MaintenanceWindowSuggestion) => schedulesApi.confirmWindow(windows.data!.work_order_id!, {
  planned_start_at: slot.planned_start_at, planned_end_at: slot.planned_end_at, employee_id: slot.technician.id,
 }),
 onSuccess: () => {
  void qc.invalidateQueries({ queryKey: ['maintenance', 'schedules', id, 'window-suggestions'] });
  void qc.invalidateQueries({ queryKey: ['maintenance', 'work-orders'] });
  toast.success('Window reserved and technician assigned.');
 },
 onError: (error) => {
  reportMutationError(error, 'Could not reserve the window. Refresh suggestions and choose again.');
  void windows.refetch();
 },
 });

 const handleDelete = async () => {
 setDeleting(true);
 try {
await schedulesApi.destroy(id);
  qc.invalidateQueries({ queryKey: ['maintenance', 'schedules'] });
  toast.success('Schedule archived.');
  navigate('/maintenance/schedules');
 } catch (error) {
 reportMutationError(error, 'Could not archive the maintenance schedule.');
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
 <StatCard label={data.progress ? 'Progress to service' : 'Next due'}
   value={data.progress ? `${data.progress.current ?? '—'} / ${data.progress.target} ${data.progress.unit}`
     : data.next_due_at ? formatDate(data.next_due_at) : '—'} />
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
 <div>
 <span className="text-muted text-xs">Estimated duration</span>
 <div>{data.estimated_duration_minutes} minutes</div>
 </div>
 {data.work_orders_count !== undefined && (
 <div>
 <span className="text-muted text-xs">Work orders generated</span>
 <div className="font-mono tabular-nums">{data.work_orders_count}</div>
 </div>
 )}
 </div>
 </Panel>
 {canManage && <Panel title="Preventive window">
  {windows.isLoading && <p className="text-sm text-muted">Finding available windows…</p>}
  {windows.isError && <p role="alert" className="text-sm text-danger-fg">Window suggestions could not load. <button type="button" className="underline" onClick={() => void windows.refetch()}>Retry</button></p>}
  {windows.data?.confirmed_window && <div className="space-y-2 text-sm">
   <p>Reserved {formatDateTime(windows.data.confirmed_window.planned_start_at)} to {formatDateTime(windows.data.confirmed_window.planned_end_at)}.</p>
   <p>Technician: {windows.data.confirmed_window.technician?.name ?? 'Unassigned'}</p>
   {windows.data.work_order_id && <Button variant="secondary" size="sm" onClick={() => navigate(`/maintenance/work-orders/${windows.data?.work_order_id}`)}>View work order</Button>}
   <p className="text-muted">Reservation only. Equipment stops when maintenance starts.</p>
  </div>}
  {windows.data && !windows.data.confirmed_window && <>
   {windows.data.suggestions.length > 0 && <div className="space-y-3">
    <p className="text-sm text-muted">Available within seven days. Confirming reserves equipment and assigns technician.</p>
    {!canAssign && <p role="status" className="text-sm text-muted">Assignment permission is required to confirm a window.</p>}
    <div className="grid gap-3 md:grid-cols-3">
     {windows.data.suggestions.map((slot) => <div key={slot.planned_start_at} className="bg-subtle rounded-md p-3 space-y-2 text-sm">
      <p className="font-medium">{formatDateTime(slot.planned_start_at)}</p>
      <p className="text-muted">Until {formatDateTime(slot.planned_end_at)}</p>
      <p>{slot.technician.name}{slot.technician.employee_no ? ` · ${slot.technician.employee_no}` : ''}</p>
      <Button variant="primary" size="sm" disabled={!canAssign || confirmWindow.isPending} loading={confirmWindow.isPending && confirmWindow.variables?.planned_start_at === slot.planned_start_at}
       onClick={() => confirmWindow.mutate(slot)}>Confirm window with {slot.technician.name}</Button>
     </div>)}
    </div>
   </div>}
   {windows.data.suggestions.length === 0 && <div className="space-y-2 text-sm">
    <p>No staffed window available in the next seven days.</p>
    {Object.values(windows.data.blockers).flat().map((reason) => <p key={reason} className="text-muted">{reason}</p>)}
   </div>}
  </>}
 </Panel>}
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
