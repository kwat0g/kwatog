import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { warehouseMapApi } from '@/api/inventory/warehouseWms';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { Button } from '@/components/ui/Button';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
const STATUS_COLORS: Record<string, string> = {
  empty:   'bg-surface text-muted border border-subtle',
  ok:      'bg-success/10 text-success-fg border border-success/20',
  low:     'bg-warning/10 text-warning-fg border border-warning/20',
  full:    'bg-accent/10 text-accent-fg border border-accent/20',
  blocked: 'bg-danger/10 text-danger-fg border border-danger/20 line-through',
};

const STATUS_LABELS: Record<string, string> = {
  empty:   'Empty',
  ok:      'Stocked',
  low:     'Low',
  full:    'Full',
  blocked: 'Blocked',
};

export default function WarehouseMapPage() {
  usePermission();
  const [activeWh, setActiveWh] = useState<string | null>(null);
  const [activeZone, setActiveZone] = useState<string | null>(null);
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
const [selectedBin, setSelectedBin] = useState<{ id: string; detail: any } | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['warehouse', 'map'],
    queryFn: () => warehouseMapApi.map(),
  });

  const wh = data?.find((w) => w.id === activeWh) ?? data?.[0];
  const zones = wh?.zones ?? [];
  const zone = zones.find((z) => z.id === activeZone) ?? null;

  const binDetail = useQuery({
    queryKey: ['warehouse', 'bin', selectedBin?.id],
    queryFn: () => warehouseMapApi.binDetail(selectedBin!.id),
    enabled: !!selectedBin,
  });

  return (
    <div>
      <PageHeader
        title="Warehouse Map"
        backTo="/inventory/items"
        backLabel="Items"
        subtitle={data ? `${data.length} ${data.length === 1 ? 'warehouse' : 'warehouses'}` : undefined}
      />
      <div className="px-5 py-4 space-y-4">
        {isLoading && !data && (
          <div className="grid grid-cols-4 gap-4">
            {Array.from({ length: 4 }).map((_, i) => <SkeletonBlock key={i} className="h-48" />)}
          </div>
        )}
        {isError && <EmptyState icon="alert-circle" title="Failed to load warehouse map" action={<Button onClick={() => refetch()}>Retry</Button>} />}
        {data && data.length === 0 && (
          <EmptyState icon="inbox" title="No warehouses configured" description="Create warehouses, zones, and locations to see the map." />
        )}
        {data && data.length > 0 && (
          <>
            {/* Warehouse selector */}
            {data.length > 1 && (
              <div className="flex gap-1 flex-wrap">
                {data.map((w) => (
                  <button
                    key={w.id}
                    type="button"
                    onClick={() => { setActiveWh(w.id); setActiveZone(null); setSelectedBin(null); }}
                    className={`px-3 py-1.5 text-xs rounded-md font-medium transition-colors ${
                      wh?.id === w.id ? 'bg-accent text-white' : 'bg-surface text-muted hover:text-primary'
                    }`}
                  >
                    {w.code} — {w.name}
                  </button>
                ))}
              </div>
            )}

            <div className="grid grid-cols-12 gap-4">
              {/* Zone sidebar */}
              <div className="col-span-3 space-y-1">
                <div className="text-2xs uppercase tracking-wider text-muted font-medium px-1 mb-1">Zones</div>
                {zones.map((z) => (
                  <button
                    key={z.id}
                    type="button"
                    onClick={() => { setActiveZone(z.id); setSelectedBin(null); }}
                    className={`w-full text-left px-2 py-1.5 text-xs rounded-md transition-colors ${
                      zone?.id === z.id ? 'bg-accent/10 text-accent border border-accent/20' : 'text-muted hover:text-primary hover:bg-elevated'
                    }`}
                  >
                    <div className="font-mono">{z.code}</div>
                    <div className="text-2xs text-muted">{z.name} · {z.type_label}</div>
                    <div className="text-2xs text-muted">{z.locations.length} bins</div>
                  </button>
                ))}
              </div>

              {/* Bin grid */}
              <div className="col-span-6">
                <div className="text-xs text-muted mb-2">
                  {zone ? `${zone.name} — ${zone.locations.length} bins` : 'Select a zone to view bins'}
                </div>
                {zone && (
                  <div className="grid grid-cols-5 gap-1.5">
                    {zone.locations.map((loc) => (
                      <button
                        key={loc.id}
                        type="button"
                        onClick={() => setSelectedBin({ id: loc.id, detail: loc })}
                        className={`text-left p-2 rounded-md text-xs transition-all hover:shadow-md ${
                          STATUS_COLORS[loc.stock_status] || STATUS_COLORS['empty']
                        } ${selectedBin?.id === loc.id ? 'ring-2 ring-accent' : ''}`}
                      >
                        <div className="font-mono font-medium truncate">{loc.code}</div>
                        <div className="text-2xs mt-0.5">
                          {loc.stock_status === 'empty' ? '—' :
                           loc.current_item ? `${loc.current_item.code}` : '—'}
                        </div>
                        <div className="text-2xs font-mono">
                          {loc.stock_status !== 'empty' ? `${Number(loc.current_quantity).toFixed(1)}` : ''}
                        </div>
                        {loc.is_blocked && (
                          <div className="text-2xs text-danger-fg mt-0.5 truncate" title={loc.blocked_reason ?? ''}>
                            ⛔ blocked
                          </div>
                        )}
                      </button>
                    ))}
                  </div>
                )}
                {zone && zone.locations.length === 0 && (
                  <div className="text-sm text-muted py-8 text-center">No bins in this zone.</div>
                )}
              </div>

              {/* Bin detail panel */}
              <div className="col-span-3 space-y-3">
                {selectedBin && (
                  <Panel title={`Bin: ${selectedBin.detail.full_code}`}>
                    <div className="text-xs space-y-2">
                      <StatusRow label="Status">
                        <Chip variant={
                          selectedBin.detail.stock_status === 'ok' ? 'success' :
                          selectedBin.detail.stock_status === 'low' ? 'warning' :
                          selectedBin.detail.stock_status === 'full' ? 'info' :
                          selectedBin.detail.stock_status === 'blocked' ? 'danger' : 'neutral'
                        }>
                          {STATUS_LABELS[selectedBin.detail.stock_status]}
                        </Chip>
                      </StatusRow>
                      <StatusRow label="Code">{selectedBin.detail.code}</StatusRow>
                      {selectedBin.detail.rack && <StatusRow label="Rack">{selectedBin.detail.rack}</StatusRow>}
                      {selectedBin.detail.bin && <StatusRow label="Bin">{selectedBin.detail.bin}</StatusRow>}
                      {selectedBin.detail.capacity_kg && (
                        <StatusRow label="Capacity">{Number(selectedBin.detail.capacity_kg).toFixed(0)} kg</StatusRow>
                      )}
                      {selectedBin.detail.current_item && (
                        <>
                          <StatusRow label="Item">{selectedBin.detail.current_item.code} — {selectedBin.detail.current_item.name}</StatusRow>
                          <StatusRow label="Quantity">{Number(selectedBin.detail.current_quantity).toFixed(3)}</StatusRow>
                          {selectedBin.detail.current_lot_number && (
                            <StatusRow label="Lot">{selectedBin.detail.current_lot_number}</StatusRow>
                          )}
                        </>
                      )}
                      {selectedBin.detail.is_blocked && (
                        <StatusRow label="Blocked">{selectedBin.detail.blocked_reason ?? 'Yes'}</StatusRow>
                      )}

                      {binDetail.data && (
                        <div className="border-t border-subtle pt-2 mt-2 space-y-1">
                          <div className="text-2xs uppercase tracking-wider text-muted font-medium">Stock levels</div>
                          {binDetail.data.stock_levels.length === 0 ? (
                            <div className="text-muted">No stock at this bin.</div>
                          ) : (
                            binDetail.data.stock_levels.map((sl, i) => (
                              <div key={i} className="text-2xs">
                                <span className="font-mono">{sl.item_code}</span>
                                <span className="text-muted ml-1">{Number(sl.quantity).toFixed(3)}</span>
                                <span className="text-muted ml-1">avail: {Number(sl.available).toFixed(3)}</span>
                              </div>
                            ))
                          )}
                          {binDetail.data.last_movement && (
                            <div className="text-2xs text-muted pt-1">
                              Last: {binDetail.data.last_movement.movement_type.replace(/_/g, ' ')}
                              {' · '}{new Date(binDetail.data.last_movement.created_at).toLocaleDateString()}
                            </div>
                          )}
                        </div>
                      )}
                    </div>
                  </Panel>
                )}
                {!selectedBin && (
                  <div className="text-sm text-muted text-center py-8">Click a bin to see details.</div>
                )}
              </div>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

function StatusRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex justify-between gap-2">
      <span className="text-muted whitespace-nowrap">{label}</span>
      <span className="text-right font-mono">{children}</span>
    </div>
  );
}
