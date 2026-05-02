import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { warehouseApi } from '@/api/inventory/warehouse';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';

const ZONE_TYPES = [
  { value: 'raw_materials', label: 'Raw materials' },
  { value: 'staging', label: 'Staging' },
  { value: 'finished_goods', label: 'Finished goods' },
  { value: 'spare_parts', label: 'Spare parts' },
  { value: 'quarantine', label: 'Quarantine' },
  { value: 'scrap', label: 'Scrap' },
];

export default function WarehousePage() {
  const qc = useQueryClient();
  const { can } = usePermission();
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'warehouse', 'tree'],
    queryFn: () => warehouseApi.tree(),
  });

  const [activeWh, setActiveWh] = useState<string | null>(null);
  const [zoneOpen, setZoneOpen] = useState(false);
  const [locOpen, setLocOpen] = useState(false);
  const [activeZone, setActiveZone] = useState<string | null>(null);

  const wh = data?.find((w) => w.id === activeWh) ?? data?.[0];
  const zones = wh?.zones ?? [];
  const zone = zones.find((z) => z.id === activeZone) ?? zones[0];

  const createZone = useMutation({
    mutationFn: (d: { name: string; code: string; zone_type: string }) =>
      warehouseApi.createZone({ ...d, warehouse_id: Number(wh!.id) }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['inventory', 'warehouse'] }); toast.success('Zone created.'); setZoneOpen(false); },
    onError: () => toast.error('Failed to create zone.'),
  });
  const createLoc = useMutation({
    mutationFn: (d: { code: string; rack?: string; bin?: string }) =>
      warehouseApi.createLocation({ ...d, zone_id: Number(zone!.id) }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['inventory', 'warehouse'] }); toast.success('Location created.'); setLocOpen(false); },
    onError: () => toast.error('Failed to create location.'),
  });
  const delLoc = useMutation({
    mutationFn: (id: string) => warehouseApi.deleteLocation(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['inventory', 'warehouse'] }); toast.success('Location deleted.'); },
    onError: (e: any) => toast.error(e?.response?.data?.message ?? 'Failed.'),
  });

  return (
    <div>
      <PageHeader title="Warehouse structure" />
      <div className="px-5 py-4 space-y-4">
        {isLoading && <SkeletonTable rows={4} columns={3} />}
        {isError && <EmptyState icon="alert-circle" title="Failed to load warehouse" action={<Button onClick={() => refetch()}>Retry</Button>} />}
        {data && data.length === 0 && <EmptyState icon="inbox" title="No warehouses configured" />}
        {data && data.length > 0 && wh && (
          <div className="grid grid-cols-12 gap-4">
            <Panel title="Warehouses" className="col-span-3">
              <ul className="text-sm">
                {data.map((w) => (
                  <li key={w.id}>
                    <button onClick={() => setActiveWh(w.id)}
                      className={`w-full text-left py-1.5 px-2 rounded-sm hover:bg-elevated ${wh.id === w.id ? 'bg-elevated font-medium' : ''}`}>
                      <div className="font-mono">{w.code}</div>
                      <div className="text-xs text-muted">{w.name}</div>
                    </button>
                  </li>
                ))}
              </ul>
            </Panel>
            <Panel
              title={`Zones — ${wh.name}`}
              className="col-span-4"
              actions={can('inventory.warehouse.manage') ? (
                <Button size="sm" variant="secondary" icon={<Plus size={12} />} onClick={() => setZoneOpen(true)}>Zone</Button>
              ) : null}
            >
              {zones.length === 0 ? <div className="text-sm text-muted">No zones yet.</div> : (
                <ul className="text-sm divide-y divide-subtle">
                  {zones.map((z) => (
                    <li key={z.id}>
                      <button onClick={() => setActiveZone(z.id)}
                        className={`w-full text-left py-2 px-2 rounded-sm hover:bg-elevated ${zone?.id === z.id ? 'bg-elevated font-medium' : ''}`}>
                        <div className="flex items-center gap-2">
                          <span className="font-mono">{z.code}</span>
                          <span>{z.name}</span>
                          <Chip variant="neutral">{z.zone_type.replace(/_/g, ' ')}</Chip>
                        </div>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>
            <Panel
              title={`Locations — ${zone?.name ?? '—'}`}
              className="col-span-5"
              actions={can('inventory.warehouse.manage') && zone ? (
                <Button size="sm" variant="secondary" icon={<Plus size={12} />} onClick={() => setLocOpen(true)}>Location</Button>
              ) : null}
            >
              {!zone || (zone.locations ?? []).length === 0
                ? <div className="text-sm text-muted">No locations yet.</div>
                : (
                  <table className="w-full text-xs">
                    <thead><tr className="text-2xs uppercase tracking-wider text-muted">
                      <th className="text-left py-1">Code</th><th>Rack</th><th>Bin</th><th></th>
                    </tr></thead>
                    <tbody>
                      {zone.locations!.map((l) => (
                        <tr key={l.id} className="h-8 border-t border-subtle">
                          <td className="font-mono">{l.code}</td>
                          <td>{l.rack ?? '—'}</td>
                          <td>{l.bin ?? '—'}</td>
                          <td className="text-right">
                            {can('inventory.warehouse.manage') && (
                              <button onClick={() => delLoc.mutate(l.id)} className="text-text-muted hover:text-danger" aria-label="Delete">
                                <Trash2 size={12} />
                              </button>
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

      <Modal isOpen={zoneOpen} onClose={() => setZoneOpen(false)} title="New zone" size="sm">
        <ZoneForm onSubmit={(d) => createZone.mutate(d)} pending={createZone.isPending} />
      </Modal>
      <Modal isOpen={locOpen} onClose={() => setLocOpen(false)} title="New location" size="sm">
        <LocForm onSubmit={(d) => createLoc.mutate(d)} pending={createLoc.isPending} />
      </Modal>
    </div>
  );
}

function ZoneForm({ onSubmit, pending }: { onSubmit: (d: { name: string; code: string; zone_type: string }) => void; pending: boolean }) {
  const [d, setD] = useState({ name: '', code: '', zone_type: 'raw_materials' });
  return (
    <div>
      <div className="space-y-3">
        <Input label="Name" required value={d.name} onChange={(e) => setD({ ...d, name: e.target.value })} />
        <Input label="Code" required value={d.code} onChange={(e) => setD({ ...d, code: e.target.value })} className="font-mono uppercase" />
        <Select label="Type" required value={d.zone_type} onChange={(e) => setD({ ...d, zone_type: e.target.value })}>
          {ZONE_TYPES.map((z) => <option key={z.value} value={z.value}>{z.label}</option>)}
        </Select>
      </div>
      <div className="flex justify-end gap-2 pt-3 border-t border-default mt-4">
        <Button variant="primary" onClick={() => onSubmit(d)} disabled={!d.name || !d.code || pending} loading={pending}>Create</Button>
      </div>
    </div>
  );
}

function LocForm({ onSubmit, pending }: { onSubmit: (d: { code: string; rack?: string; bin?: string }) => void; pending: boolean }) {
  const [d, setD] = useState({ code: '', rack: '', bin: '' });
  return (
    <div>
      <div className="space-y-3">
        <Input label="Code" required value={d.code} onChange={(e) => setD({ ...d, code: e.target.value })} className="font-mono" />
        <Input label="Rack" value={d.rack} onChange={(e) => setD({ ...d, rack: e.target.value })} />
        <Input label="Bin" value={d.bin} onChange={(e) => setD({ ...d, bin: e.target.value })} />
      </div>
      <div className="flex justify-end gap-2 pt-3 border-t border-default mt-4">
        <Button variant="primary" disabled={!d.code || pending} loading={pending}
                onClick={() => onSubmit({ code: d.code, rack: d.rack || undefined, bin: d.bin || undefined })}>Create</Button>
      </div>
    </div>
  );
}
