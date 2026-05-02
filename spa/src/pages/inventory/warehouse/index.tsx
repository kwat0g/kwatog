import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { warehouseApi } from '@/api/inventory/warehouse';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { Switch } from '@/components/ui/Switch';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid } from '@/lib/formErrors';
import { usePermission } from '@/hooks/usePermission';
import type { ApiValidationError } from '@/types';
import type { Warehouse, WarehouseLocation, WarehouseZone } from '@/types/inventory';

// ──────────────────────────────────────────────────────────────────────────────
// Schemas
// ──────────────────────────────────────────────────────────────────────────────

const codeRegex = /^[A-Z0-9-]+$/;

const warehouseSchema = z.object({
  name: z.string().trim().min(2, 'Name must be at least 2 characters.').max(100),
  code: z.string().trim().min(1).max(20).regex(codeRegex, 'Use uppercase letters, digits, hyphens.'),
  address: z.string().max(500).optional().or(z.literal('')),
  is_active: z.boolean().default(true),
});
type WarehouseFormValues = z.infer<typeof warehouseSchema>;

const zoneSchema = z.object({
  name: z.string().trim().min(2).max(50),
  code: z.string().trim().min(1).max(10).regex(codeRegex, 'Use uppercase letters, digits, hyphens.'),
  zone_type: z.enum(['raw_materials', 'staging', 'finished_goods', 'spare_parts', 'quarantine', 'scrap']),
});
type ZoneFormValues = z.infer<typeof zoneSchema>;

const locationSchema = z.object({
  code: z.string().trim().min(1).max(20).regex(codeRegex, 'Use uppercase letters, digits, hyphens.'),
  rack: z.string().max(10).optional().or(z.literal('')),
  bin: z.string().max(10).optional().or(z.literal('')),
  is_active: z.boolean().default(true),
});
type LocationFormValues = z.infer<typeof locationSchema>;

const ZONE_TYPES: Array<{ value: ZoneFormValues['zone_type']; label: string }> = [
  { value: 'raw_materials', label: 'Raw materials' },
  { value: 'staging', label: 'Staging' },
  { value: 'finished_goods', label: 'Finished goods' },
  { value: 'spare_parts', label: 'Spare parts' },
  { value: 'quarantine', label: 'Quarantine' },
  { value: 'scrap', label: 'Scrap' },
];

// ──────────────────────────────────────────────────────────────────────────────
// Page
// ──────────────────────────────────────────────────────────────────────────────

type DeleteTarget =
  | { kind: 'warehouse'; id: string; name: string }
  | { kind: 'zone'; id: string; name: string }
  | { kind: 'location'; id: string; code: string };

export default function WarehousePage() {
  const qc = useQueryClient();
  const { can } = usePermission();
  const canManage = can('inventory.warehouse.manage');

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'warehouse', 'tree'],
    queryFn: () => warehouseApi.tree(),
  });

  const [activeWh, setActiveWh] = useState<string | null>(null);
  const [activeZone, setActiveZone] = useState<string | null>(null);

  const [whModal, setWhModal] = useState<{ mode: 'create' | 'edit'; existing: Warehouse | null } | null>(null);
  const [zoneModal, setZoneModal] = useState<{ mode: 'create' | 'edit'; existing: WarehouseZone | null } | null>(null);
  const [locModal, setLocModal] = useState<{ mode: 'create' | 'edit'; existing: WarehouseLocation | null } | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<DeleteTarget | null>(null);

  const wh = data?.find((w) => w.id === activeWh) ?? data?.[0];
  const zones = wh?.zones ?? [];
  const zone = zones.find((z) => z.id === activeZone) ?? zones[0];
  const locations = zone?.locations ?? [];

  const invalidate = () => qc.invalidateQueries({ queryKey: ['inventory', 'warehouse'] });

  const delWh = useMutation({
    mutationFn: (id: string) => warehouseApi.deleteWarehouse(id),
    onSuccess: () => { invalidate(); toast.success('Warehouse deleted.'); setConfirmDelete(null); },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed to delete warehouse.'),
  });
  const delZone = useMutation({
    mutationFn: (id: string) => warehouseApi.deleteZone(id),
    onSuccess: () => { invalidate(); toast.success('Zone deleted.'); setConfirmDelete(null); },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed to delete zone.'),
  });
  const delLoc = useMutation({
    mutationFn: (id: string) => warehouseApi.deleteLocation(id),
    onSuccess: () => { invalidate(); toast.success('Location deleted.'); setConfirmDelete(null); },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed to delete location.'),
  });

  return (
    <div>
      <PageHeader
        title="Warehouse structure"
        subtitle={data ? `${data.length} ${data.length === 1 ? 'warehouse' : 'warehouses'}` : undefined}
        actions={
          canManage ? (
            <Button
              variant="primary"
              size="sm"
              icon={<Plus size={14} />}
              onClick={() => setWhModal({ mode: 'create', existing: null })}
            >
              New warehouse
            </Button>
          ) : null
        }
      />

      <div className="px-5 py-4 space-y-4">
        {isLoading && !data && <SkeletonTree />}
        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load warehouse"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}
        {data && data.length === 0 && (
          <EmptyState
            icon="inbox"
            title="No warehouses configured"
            description={canManage ? 'Add your first warehouse to start managing stock locations.' : 'Nothing here yet.'}
            action={canManage ? (
              <Button variant="primary" onClick={() => setWhModal({ mode: 'create', existing: null })}>
                New warehouse
              </Button>
            ) : undefined}
          />
        )}
        {data && data.length > 0 && wh && (
          <div className="grid grid-cols-12 gap-4">
            {/* Warehouses column */}
            <Panel title="Warehouses" className="col-span-3">
              <ul className="text-sm">
                {data.map((w) => (
                  <li key={w.id} className="group">
                    <div className={`flex items-center gap-1 rounded-sm transition-colors ${wh.id === w.id ? 'bg-elevated' : 'hover:bg-elevated'}`}>
                      <button
                        type="button"
                        onClick={() => { setActiveWh(w.id); setActiveZone(null); }}
                        className="flex-1 text-left py-1.5 px-2"
                      >
                        <div className="font-mono text-xs">{w.code}</div>
                        <div className="text-2xs text-muted">{w.name}</div>
                      </button>
                      {canManage && (
                        <div className="hidden group-hover:flex pr-1 gap-0.5">
                          <IconBtn label={`Edit ${w.name}`} onClick={() => setWhModal({ mode: 'edit', existing: w })}>
                            <Pencil size={12} />
                          </IconBtn>
                          <IconBtn label={`Delete ${w.name}`} danger onClick={() => setConfirmDelete({ kind: 'warehouse', id: w.id, name: w.name })}>
                            <Trash2 size={12} />
                          </IconBtn>
                        </div>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            </Panel>

            {/* Zones column */}
            <Panel
              title={`Zones — ${wh.name}`}
              className="col-span-4"
              actions={canManage ? (
                <Button size="sm" variant="secondary" icon={<Plus size={12} />} onClick={() => setZoneModal({ mode: 'create', existing: null })}>
                  Zone
                </Button>
              ) : null}
            >
              {zones.length === 0 ? (
                <div className="text-sm text-muted">No zones yet.</div>
              ) : (
                <ul className="text-sm divide-y divide-subtle">
                  {zones.map((z) => (
                    <li key={z.id} className="group">
                      <div className={`flex items-center gap-1 rounded-sm transition-colors ${zone?.id === z.id ? 'bg-elevated' : 'hover:bg-elevated'}`}>
                        <button
                          type="button"
                          onClick={() => setActiveZone(z.id)}
                          className="flex-1 text-left py-2 px-2 flex items-center gap-2"
                        >
                          <span className="font-mono text-xs">{z.code}</span>
                          <span className="flex-1 truncate">{z.name}</span>
                          <Chip variant="neutral">{z.zone_type.replace(/_/g, ' ')}</Chip>
                        </button>
                        {canManage && (
                          <div className="hidden group-hover:flex pr-1 gap-0.5">
                            <IconBtn label={`Edit ${z.name}`} onClick={() => setZoneModal({ mode: 'edit', existing: z })}>
                              <Pencil size={12} />
                            </IconBtn>
                            <IconBtn label={`Delete ${z.name}`} danger onClick={() => setConfirmDelete({ kind: 'zone', id: z.id, name: z.name })}>
                              <Trash2 size={12} />
                            </IconBtn>
                          </div>
                        )}
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>

            {/* Locations column */}
            <Panel
              title={`Locations — ${zone?.name ?? '—'}`}
              className="col-span-5"
              actions={canManage && zone ? (
                <Button size="sm" variant="secondary" icon={<Plus size={12} />} onClick={() => setLocModal({ mode: 'create', existing: null })}>
                  Location
                </Button>
              ) : null}
            >
              {!zone || locations.length === 0 ? (
                <div className="text-sm text-muted">No locations yet.</div>
              ) : (
                <table className="w-full text-xs">
                  <thead>
                    <tr className="text-2xs uppercase tracking-wider text-muted">
                      <th className="text-left py-1 font-medium">Code</th>
                      <th className="text-left font-medium">Rack</th>
                      <th className="text-left font-medium">Bin</th>
                      <th className="text-left font-medium">Status</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {locations.map((l) => (
                      <tr key={l.id} className="h-8 border-t border-subtle group">
                        <td className="font-mono">{l.code}</td>
                        <td>{l.rack ?? <span className="text-muted">—</span>}</td>
                        <td>{l.bin ?? <span className="text-muted">—</span>}</td>
                        <td>
                          <Chip variant={l.is_active ? 'success' : 'neutral'}>{l.is_active ? 'active' : 'inactive'}</Chip>
                        </td>
                        <td className="text-right">
                          {canManage && (
                            <div className="hidden group-hover:flex justify-end gap-0.5">
                              <IconBtn label={`Edit ${l.code}`} onClick={() => setLocModal({ mode: 'edit', existing: l })}>
                                <Pencil size={12} />
                              </IconBtn>
                              <IconBtn label={`Delete ${l.code}`} danger onClick={() => setConfirmDelete({ kind: 'location', id: l.id, code: l.code })}>
                                <Trash2 size={12} />
                              </IconBtn>
                            </div>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </Panel>
          </div>
        )}
      </div>

      {/* Warehouse modal */}
      <Modal isOpen={!!whModal} onClose={() => setWhModal(null)} title={whModal?.mode === 'edit' ? `Edit ${whModal.existing?.name}` : 'New warehouse'} size="sm">
        {whModal && (
          <WarehouseForm
            mode={whModal.mode}
            existing={whModal.existing}
            onClose={() => setWhModal(null)}
            onSaved={() => { invalidate(); setWhModal(null); }}
          />
        )}
      </Modal>

      {/* Zone modal */}
      <Modal isOpen={!!zoneModal} onClose={() => setZoneModal(null)} title={zoneModal?.mode === 'edit' ? `Edit ${zoneModal.existing?.name}` : 'New zone'} size="sm">
        {zoneModal && wh && (
          <ZoneForm
            mode={zoneModal.mode}
            existing={zoneModal.existing}
            warehouseId={wh.id}
            onClose={() => setZoneModal(null)}
            onSaved={() => { invalidate(); setZoneModal(null); }}
          />
        )}
      </Modal>

      {/* Location modal */}
      <Modal isOpen={!!locModal} onClose={() => setLocModal(null)} title={locModal?.mode === 'edit' ? `Edit ${locModal.existing?.code}` : 'New location'} size="sm">
        {locModal && zone && (
          <LocationForm
            mode={locModal.mode}
            existing={locModal.existing}
            zoneId={zone.id}
            onClose={() => setLocModal(null)}
            onSaved={() => { invalidate(); setLocModal(null); }}
          />
        )}
      </Modal>

      {/* Delete confirmation */}
      <ConfirmDialog
        isOpen={!!confirmDelete}
        onClose={() => setConfirmDelete(null)}
        onConfirm={() => {
          if (!confirmDelete) return;
          if (confirmDelete.kind === 'warehouse') delWh.mutate(confirmDelete.id);
          else if (confirmDelete.kind === 'zone') delZone.mutate(confirmDelete.id);
          else delLoc.mutate(confirmDelete.id);
        }}
        title={
          confirmDelete?.kind === 'warehouse' ? 'Delete warehouse?'
          : confirmDelete?.kind === 'zone' ? 'Delete zone?'
          : 'Delete location?'
        }
        description={
          confirmDelete ? (
            <>
              <span className="font-medium text-primary">
                {confirmDelete.kind === 'location' ? confirmDelete.code : confirmDelete.name}
              </span>
              {' will be permanently removed. Deletion fails if there are dependent records (zones, locations, or stock).'}
            </>
          ) : null
        }
        confirmLabel="Delete"
        variant="danger"
        pending={delWh.isPending || delZone.isPending || delLoc.isPending}
      />
    </div>
  );
}

// ──────────────────────────────────────────────────────────────────────────────
// Sub-components
// ──────────────────────────────────────────────────────────────────────────────

function IconBtn({ children, label, danger, onClick }: { children: React.ReactNode; label: string; danger?: boolean; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      className={`p-1 rounded-sm transition-colors text-text-muted ${danger ? 'hover:text-danger' : 'hover:text-primary'} hover:bg-canvas`}
    >
      {children}
    </button>
  );
}

function SkeletonTree() {
  return (
    <div className="grid grid-cols-12 gap-4">
      <SkeletonBlock className="col-span-3 h-64" />
      <SkeletonBlock className="col-span-4 h-64" />
      <SkeletonBlock className="col-span-5 h-64" />
    </div>
  );
}

// ──────────────────────────────────────────────────────────────────────────────
// Forms
// ──────────────────────────────────────────────────────────────────────────────

function applyServerErrors<T extends Record<string, unknown>>(
  e: AxiosError<ApiValidationError>,
  setError: (field: keyof T, opts: { type: string; message: string }) => void,
  fallback: string,
) {
  if (e.response?.status === 422 && e.response.data?.errors) {
    Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
      setError(field as keyof T, {
        type: 'server',
        message: Array.isArray(msgs) ? (msgs as string[])[0] : String(msgs),
      });
    });
    toast.error('Please fix the highlighted fields.');
  } else {
    toast.error(e.response?.data?.message ?? fallback);
  }
}

function WarehouseForm({ mode, existing, onClose, onSaved }: {
  mode: 'create' | 'edit'; existing: Warehouse | null; onClose: () => void; onSaved: () => void;
}) {
  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<WarehouseFormValues>({
    resolver: zodResolver(warehouseSchema),
    defaultValues: {
      name: existing?.name ?? '',
      code: existing?.code ?? '',
      address: existing?.address ?? '',
      is_active: existing?.is_active ?? true,
    },
  });

  const m = useMutation({
    mutationFn: (d: WarehouseFormValues) => {
      const payload = { ...d, address: d.address?.trim() || null };
      return mode === 'create'
        ? warehouseApi.createWarehouse(payload)
        : warehouseApi.updateWarehouse(existing!.id, payload);
    },
    onSuccess: () => { toast.success(mode === 'create' ? 'Warehouse created.' : 'Warehouse updated.'); onSaved(); },
    onError: (e: AxiosError<ApiValidationError>) => applyServerErrors<WarehouseFormValues>(e, setError, 'Failed to save warehouse.'),
  });

  return (
    <form onSubmit={handleSubmit((d) => m.mutate(d), onFormInvalid<WarehouseFormValues>())} className="py-3">
      <div className="space-y-3">
        <Input label="Name" required maxLength={100} autoFocus {...register('name')} error={errors.name?.message} />
        <Input label="Code" required maxLength={20} {...register('code')} error={errors.code?.message}
               className="font-mono uppercase" placeholder="WH01" />
        <Input label="Address" maxLength={500} {...register('address')} error={errors.address?.message} />
        <Switch label="Active" {...register('is_active')} />
      </div>
      <div className="flex justify-end gap-2 pt-3 mt-4 border-t border-default">
        <Button type="button" variant="secondary" onClick={onClose} disabled={m.isPending}>Cancel</Button>
        <Button type="submit" variant="primary" loading={m.isPending} disabled={m.isPending || isSubmitting}>
          {mode === 'create' ? 'Create' : 'Save changes'}
        </Button>
      </div>
    </form>
  );
}

function ZoneForm({ mode, existing, warehouseId, onClose, onSaved }: {
  mode: 'create' | 'edit'; existing: WarehouseZone | null; warehouseId: string; onClose: () => void; onSaved: () => void;
}) {
  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<ZoneFormValues>({
    resolver: zodResolver(zoneSchema),
    defaultValues: {
      name: existing?.name ?? '',
      code: existing?.code ?? '',
      zone_type: existing?.zone_type ?? 'raw_materials',
    },
  });

  const m = useMutation({
    mutationFn: (d: ZoneFormValues) =>
      mode === 'create'
        ? warehouseApi.createZone({ warehouse_id: warehouseId, ...d })
        : warehouseApi.updateZone(existing!.id, d),
    onSuccess: () => { toast.success(mode === 'create' ? 'Zone created.' : 'Zone updated.'); onSaved(); },
    onError: (e: AxiosError<ApiValidationError>) => applyServerErrors<ZoneFormValues>(e, setError, 'Failed to save zone.'),
  });

  return (
    <form onSubmit={handleSubmit((d) => m.mutate(d), onFormInvalid<ZoneFormValues>())} className="py-3">
      <div className="space-y-3">
        <Input label="Name" required maxLength={50} autoFocus {...register('name')} error={errors.name?.message} />
        <Input label="Code" required maxLength={10} {...register('code')} error={errors.code?.message}
               className="font-mono uppercase" placeholder="A1" />
        <Select label="Type" required {...register('zone_type')} error={errors.zone_type?.message}>
          {ZONE_TYPES.map((z) => <option key={z.value} value={z.value}>{z.label}</option>)}
        </Select>
      </div>
      <div className="flex justify-end gap-2 pt-3 mt-4 border-t border-default">
        <Button type="button" variant="secondary" onClick={onClose} disabled={m.isPending}>Cancel</Button>
        <Button type="submit" variant="primary" loading={m.isPending} disabled={m.isPending || isSubmitting}>
          {mode === 'create' ? 'Create' : 'Save changes'}
        </Button>
      </div>
    </form>
  );
}

function LocationForm({ mode, existing, zoneId, onClose, onSaved }: {
  mode: 'create' | 'edit'; existing: WarehouseLocation | null; zoneId: string; onClose: () => void; onSaved: () => void;
}) {
  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<LocationFormValues>({
    resolver: zodResolver(locationSchema),
    defaultValues: {
      code: existing?.code ?? '',
      rack: existing?.rack ?? '',
      bin: existing?.bin ?? '',
      is_active: existing?.is_active ?? true,
    },
  });

  const m = useMutation({
    mutationFn: (d: LocationFormValues) => {
      const payload = { ...d, rack: d.rack?.trim() || null, bin: d.bin?.trim() || null };
      return mode === 'create'
        ? warehouseApi.createLocation({ zone_id: zoneId, ...payload })
        : warehouseApi.updateLocation(existing!.id, payload);
    },
    onSuccess: () => { toast.success(mode === 'create' ? 'Location created.' : 'Location updated.'); onSaved(); },
    onError: (e: AxiosError<ApiValidationError>) => applyServerErrors<LocationFormValues>(e, setError, 'Failed to save location.'),
  });

  return (
    <form onSubmit={handleSubmit((d) => m.mutate(d), onFormInvalid<LocationFormValues>())} className="py-3">
      <div className="space-y-3">
        <Input label="Code" required maxLength={20} autoFocus {...register('code')} error={errors.code?.message}
               className="font-mono uppercase" placeholder="A1-01" />
        <div className="grid grid-cols-2 gap-3">
          <Input label="Rack" maxLength={10} {...register('rack')} error={errors.rack?.message} />
          <Input label="Bin" maxLength={10} {...register('bin')} error={errors.bin?.message} />
        </div>
        <Switch label="Active" {...register('is_active')} />
      </div>
      <div className="flex justify-end gap-2 pt-3 mt-4 border-t border-default">
        <Button type="button" variant="secondary" onClick={onClose} disabled={m.isPending}>Cancel</Button>
        <Button type="submit" variant="primary" loading={m.isPending} disabled={m.isPending || isSubmitting}>
          {mode === 'create' ? 'Create' : 'Save changes'}
        </Button>
      </div>
    </form>
  );
}
