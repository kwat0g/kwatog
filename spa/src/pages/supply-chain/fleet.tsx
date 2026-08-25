/** Sprint 7 — Task 67 — Fleet vehicle registry and lifecycle controls. */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import toast from 'react-hot-toast';
import { vehiclesApi } from '@/api/supply-chain';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { usePermission } from '@/hooks/usePermission';
import type { Vehicle } from '@/types/supplyChain';

const STATUS_CHIP: Record<string, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
 available: 'success',
 in_use: 'info',
 maintenance: 'warning',
 retired: 'neutral',
};

type VehicleForm = {
 plate_number: string;
 name: string;
 vehicle_type: string;
 capacity_kg: string;
 status: string;
 notes: string;
};

const EMPTY_FORM: VehicleForm = {
 plate_number: '',
 name: '',
 vehicle_type: 'truck',
 capacity_kg: '',
 status: 'available',
 notes: '',
};

function errorMessage(error: unknown, fallback: string): string {
 if (isAxiosError(error) && typeof error.response?.data?.message === 'string') {
  return error.response.data.message;
 }
 return fallback;
}

export default function FleetPage() {
 const qc = useQueryClient();
 const { can } = usePermission();
 const canManage = can('supply_chain.fleet.manage');
 const [showArchived, setShowArchived] = useState(false);
 const [formOpen, setFormOpen] = useState(false);
 const [editing, setEditing] = useState<Vehicle | null>(null);
 const [form, setForm] = useState<VehicleForm>(EMPTY_FORM);
 const [archiveId, setArchiveId] = useState<string | null>(null);
 const [page, setPage] = useState(1);
 const [perPage, setPerPage] = useState(50);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['supply-chain', 'vehicles', { showArchived, page, perPage }],
 queryFn: () => vehiclesApi.list({ page, per_page: perPage, trashed: showArchived ? 'with' : undefined }),
 placeholderData: (prev) => prev,
 });
 const { data: vehicleOptions } = useQuery({
 queryKey: ['supply-chain', 'vehicles', 'options'],
 queryFn: () => vehiclesApi.options(),
 staleTime: 300_000,
 });
 const statusLabels = Object.fromEntries((vehicleOptions?.statuses ?? []).map((option) => [option.value, option.label]));
 const typeLabels = Object.fromEntries((vehicleOptions?.types ?? []).map((option) => [option.value, option.label]));

 const save = useMutation({
 mutationFn: () => {
  const payload = {
   plate_number: form.plate_number.trim(),
   name: form.name.trim(),
   vehicle_type: form.vehicle_type,
   capacity_kg: form.capacity_kg === '' ? null : Number(form.capacity_kg),
   status: form.status,
   notes: form.notes.trim() || null,
  };
  return editing ? vehiclesApi.update(editing.id, payload) : vehiclesApi.create(payload);
 },
 onSuccess: () => {
  toast.success(editing ? 'Vehicle updated.' : 'Vehicle created.');
  setFormOpen(false);
  setEditing(null);
  setForm(EMPTY_FORM);
  qc.invalidateQueries({ queryKey: ['supply-chain', 'vehicles'] });
 },
 onError: (error) => toast.error(errorMessage(error, 'Could not save vehicle.')),
 });

 const archive = useMutation({
 mutationFn: (id: string) => vehiclesApi.destroy(id),
 onSuccess: () => {
  toast.success('Vehicle archived.');
  setArchiveId(null);
  qc.invalidateQueries({ queryKey: ['supply-chain', 'vehicles'] });
 },
 onError: (error) => toast.error(errorMessage(error, 'Could not archive vehicle.')),
 });

 const restore = useMutation({
 mutationFn: (id: string) => vehiclesApi.restore(id),
 onSuccess: () => {
  toast.success('Vehicle restored.');
  qc.invalidateQueries({ queryKey: ['supply-chain', 'vehicles'] });
 },
 onError: (error) => toast.error(errorMessage(error, 'Could not restore vehicle.')),
 });

 const openCreate = () => {
  setEditing(null);
  setForm(EMPTY_FORM);
  setFormOpen(true);
 };

 const openEdit = (vehicle: Vehicle) => {
  setEditing(vehicle);
  setForm({
   plate_number: vehicle.plate_number,
   name: vehicle.name,
   vehicle_type: vehicle.vehicle_type,
   capacity_kg: vehicle.capacity_kg === null ? '' : String(vehicle.capacity_kg),
   status: vehicle.status,
   notes: vehicle.notes ?? '',
  });
  setFormOpen(true);
 };

 const toggleArchived = () => {
  setShowArchived((value) => !value);
  setPage(1);
 };

 const changePageSize = (nextPerPage: number) => {
  setPerPage(nextPerPage);
  setPage(1);
 };

 const columns: Column<Vehicle>[] = [
  { key: 'plate', header: 'Plate', cell: (r) => <span className="font-mono">{r.plate_number}</span> },
  { key: 'name', header: 'Name', cell: (r) => r.name },
  { key: 'type', header: 'Type', cell: (r) => <Chip variant="neutral">{typeLabels[r.vehicle_type] ?? r.vehicle_type}</Chip> },
  { key: 'capacity', header: 'Capacity (kg)', align: 'right', cell: (r) => <NumCell>{r.capacity_kg ?? '—'}</NumCell> },
  { key: 'status', header: 'Status', cell: (r) => <Chip variant={STATUS_CHIP[r.status] ?? 'neutral'}>{r.deleted_at ? 'Archived' : (statusLabels[r.status] ?? r.status)}</Chip> },
  ...(canManage ? [{
   key: 'actions',
   header: 'Actions',
   cell: (r: Vehicle) => r.deleted_at ? (
    <Button size="sm" variant="secondary" onClick={(event) => { event.stopPropagation(); restore.mutate(r.id); }} loading={restore.isPending}>Restore</Button>
   ) : (
    <div className="flex items-center gap-1.5" onClick={(event) => event.stopPropagation()}>
     <Button size="sm" variant="ghost" onClick={() => openEdit(r)}>Edit</Button>
     <Button size="sm" variant="ghost" className="text-danger-fg" onClick={() => setArchiveId(r.id)}>Archive</Button>
    </div>
   ),
  } as Column<Vehicle>] : []),
 ];

 return (
 <div>
  <PageHeader
   title="Fleet"
   subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'vehicle' : 'vehicles'}` : undefined}
   actions={
    <div className="flex items-center gap-2">
     <Button size="sm" variant="secondary" onClick={toggleArchived}>
      {showArchived ? 'Hide archived' : 'Show archived'}
     </Button>
     {canManage && <Button size="sm" variant="primary" onClick={openCreate}>New vehicle</Button>}
    </div>
   }
  />
  {isLoading && !data && <SkeletonTable columns={canManage ? 6 : 5} rows={5} />}
  {isError && <EmptyState icon="alert-circle" title="Failed to load fleet" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
  {data && data.data.length === 0 && <EmptyState icon="truck" title={showArchived ? 'No archived vehicles' : 'No vehicles'} description={canManage ? 'Create a vehicle to make it available for delivery assignment.' : undefined} />}
  {data && data.data.length > 0 && <div className="px-5 py-4"><DataTable columns={columns} data={data.data} meta={data.meta} onPageChange={setPage} onPageSizeChange={changePageSize} /></div>}

  <Modal isOpen={formOpen} onClose={() => { if (!save.isPending) setFormOpen(false); }} title={editing ? `Edit ${editing.name}` : 'New vehicle'}>
   <div className="space-y-3">
    <Input label="Plate number" required value={form.plate_number} onChange={(event) => setForm((current) => ({ ...current, plate_number: event.target.value }))} maxLength={20} />
    <Input label="Name" required value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} maxLength={100} />
    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
     <Select label="Type" required value={form.vehicle_type} onChange={(event) => setForm((current) => ({ ...current, vehicle_type: event.target.value }))}>
      {(vehicleOptions?.types ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
     </Select>
     <Input label="Capacity (kg)" type="number" min="0" step="0.01" value={form.capacity_kg} onChange={(event) => setForm((current) => ({ ...current, capacity_kg: event.target.value }))} />
    </div>
    <Select label="Status" value={form.status} onChange={(event) => setForm((current) => ({ ...current, status: event.target.value }))}>
     {(vehicleOptions?.statuses ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
    </Select>
    <Textarea label="Notes" value={form.notes} onChange={(event) => setForm((current) => ({ ...current, notes: event.target.value }))} maxLength={500} rows={3} />
    <ModalFooter>
     <Button variant="secondary" onClick={() => setFormOpen(false)} disabled={save.isPending}>Cancel</Button>
     <Button variant="primary" onClick={() => save.mutate()} loading={save.isPending} disabled={!form.plate_number.trim() || !form.name.trim() || !form.vehicle_type}>Save vehicle</Button>
    </ModalFooter>
   </div>
  </Modal>

  <ConfirmDialog
   isOpen={Boolean(archiveId)}
   onClose={() => setArchiveId(null)}
   onConfirm={() => { if (archiveId) archive.mutate(archiveId); }}
   title="Archive this vehicle?"
   description="Vehicles with scheduled or active deliveries cannot be archived. Historical delivery records remain readable after a successful archive."
   confirmLabel="Archive vehicle"
   variant="danger"
   pending={archive.isPending}
  />
 </div>
 );
}
