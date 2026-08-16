import { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { LuSearch } from '@/lib/icons';
import { warehouseMapApi } from '@/api/inventory/warehouseWms';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import { focusRingInset } from '@/lib/focus';
import { focusRing } from '@/lib/focus';
import { cn } from '@/lib/cn';
import { StockCountManager } from './stock-count';

const STATUS_COLORS: Record<string, string> = {
  empty: 'bg-surface text-muted border border-subtle',
  ok: 'bg-success-bg/10 text-success-fg border border-success/20',
  low: 'bg-warning-bg/10 text-warning-fg border border-warning/20',
  full: 'bg-accent/10 text-accent-fg border border-accent/20',
  blocked: 'bg-danger-bg/10 text-danger-fg border border-danger/20 line-through',
};

// Solid swatch for the legend (tint + border, no text colour).
const STATUS_SWATCH: Record<string, string> = {
  empty: 'bg-surface border border-subtle',
  ok: 'bg-success-bg/50 border border-success/40',
  low: 'bg-warning-bg/50 border border-warning/40',
  full: 'bg-accent/40 border border-accent/40',
  blocked: 'bg-danger-bg/50 border border-danger/40',
};

/**
 * Warehouse Map + Stock Count merged into one page (2026-08-08): the bin map
 * is the visual floor view, and the physical-count workflow lives behind the
 * Map | Stock Count toggle. Deep-linkable via ?view=count; the
 * /inventory/stock-count path (used by the barcode scanner) aliases into this
 * page in count view. The Stock Count tab only appears for users with
 * inventory.stock_count.view.
 */
export default function WarehouseMapPage() {
  const { can } = usePermission();
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const [searchParams] = useSearchParams();

  const canCount = can('inventory.stock_count.view');
  const rawView =
    pathname === '/inventory/stock-count' || searchParams.get('view') === 'count' ? 'count' : 'map';
  const view = rawView === 'count' && !canCount ? 'map' : rawView;
  const setView = (v: 'map' | 'count') => {
    // Always land on the canonical /inventory/warehouse-map URL so the
    // /inventory/stock-count alias is normalised away on toggle.
    navigate(v === 'count' ? '/inventory/warehouse-map?view=count' : '/inventory/warehouse-map', {
      replace: true,
    });
  };

  // A user without stock_count access landing on ?view=count is forced to map
  // view; drop the param so the URL matches what is actually rendered.
  useEffect(() => {
    if (rawView === 'count' && !canCount && pathname !== '/inventory/stock-count') {
      navigate('/inventory/warehouse-map', { replace: true });
    }
  }, [rawView, canCount, pathname, navigate]);

  const [activeWh, setActiveWh] = useState<string | null>(null);
  const [activeZone, setActiveZone] = useState<string | null>(null);
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const [selectedBin, setSelectedBin] = useState<{ id: string; detail: any } | null>(null);
  const [binQuery, setBinQuery] = useState('');

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['warehouse', 'map'],
    queryFn: () => warehouseMapApi.map(),
  });

  const wh = data?.find((w) => w.id === activeWh) ?? data?.[0];
  const zones = wh?.zones ?? [];
  const zone = zones.find((z) => z.id === activeZone) ?? null;
  const statusLegend = useMemo(() => {
    const labels = new Map<string, string>();
    for (const warehouse of data ?? []) {
      for (const currentZone of warehouse.zones) {
        for (const location of currentZone.locations) {
          if (!labels.has(location.stock_status)) {
            labels.set(location.stock_status, location.stock_status_label ?? location.stock_status);
          }
        }
      }
    }
    return [...labels.entries()];
  }, [data]);

  // Bin search — filters the current zone's bins by bin code, current item
  // code or name, or block reason, so counting/dispatching targets can be
  // found fast.
  const visibleBins = useMemo(() => {
    if (!zone) return [];
    const q = binQuery.trim().toLowerCase();
    if (!q) return zone.locations;
    return zone.locations.filter(
      (loc) =>
        loc.code.toLowerCase().includes(q) ||
        (loc.current_item?.code ?? '').toLowerCase().includes(q) ||
        (loc.current_item?.name ?? '').toLowerCase().includes(q) ||
        (loc.blocked_reason ?? '').toLowerCase().includes(q),
    );
  }, [zone, binQuery]);

  const binDetail = useQuery({
    queryKey: ['warehouse', 'bin', selectedBin?.id],
    queryFn: () => warehouseMapApi.binDetail(selectedBin!.id),
    enabled: !!selectedBin,
  });

  return (
    <div>
      <PageHeader
        title="Warehouse Map"
        subtitle={
          data ? `${data.length} ${data.length === 1 ? 'warehouse' : 'warehouses'}` : undefined
        }
        actions={
          canCount ? (
            <SegmentedControl
              size="sm"
              label="Warehouse map view"
              value={view}
              onChange={setView}
              options={[
                { value: 'map', label: 'Map' },
                { value: 'count', label: 'Stock Count' },
              ]}
            />
          ) : undefined
        }
      />
      <div className="px-5 py-4 space-y-4">
        {view === 'count' ? (
          <StockCountManager />
        ) : (
          <>
            {/* Legend — explains the bin colour coding. */}
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-2xs text-muted">
              <span className="font-medium uppercase tracking-wider">Legend</span>
              {statusLegend.map(([key, label]) => (
                <span key={key} className="inline-flex items-center gap-1.5">
                  <span
                    aria-hidden
                    className={cn('h-2.5 w-2.5 rounded-[3px]', STATUS_SWATCH[key])}
                  />
                  {label}
                </span>
              ))}
            </div>

            {isLoading && !data && (
              <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                {Array.from({ length: 4 }).map((_, i) => (
                  <SkeletonBlock key={i} className="h-48" />
                ))}
              </div>
            )}
            {isError && (
              <EmptyState
                icon="alert-circle"
                title="Failed to load warehouse map"
                action={<Button onClick={() => refetch()}>Retry</Button>}
              />
            )}
            {data && data.length === 0 && (
              <EmptyState
                icon="inbox"
                title="No warehouses configured"
                description="Create warehouses, zones, and locations to see the map."
              />
            )}
            {data && data.length > 0 && (
              <>
                {/* Warehouse selector */}
                {data.length > 1 && (
                  <SegmentedControl
                    size="sm"
                    label="Warehouse"
                    value={wh?.id ?? ''}
                    onChange={(id) => {
                      setActiveWh(id);
                      setActiveZone(null);
                      setSelectedBin(null);
                      setBinQuery('');
                    }}
                    options={data.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` }))}
                  />
                )}

                <div className="grid grid-cols-1 md:grid-cols-12 gap-4">
                  {/* Zone sidebar */}
                  <div className="col-span-3 space-y-1">
                    <div className="text-2xs uppercase tracking-wider text-muted font-medium px-1 mb-1">
                      Zones
                    </div>
                    {zones.map((z) => (
                      <button
                        key={z.id}
                        type="button"
                        onClick={() => {
                          setActiveZone(z.id);
                          setSelectedBin(null);
                          setBinQuery('');
                        }}
                        className={`w-full text-left px-2 py-1.5 text-xs rounded-md transition-colors cursor-pointer border ${focusRingInset} ${
                          zone?.id === z.id
                            ? 'bg-accent/10 text-accent border-accent/30 shadow-sm'
                            : 'bg-surface border-default text-secondary hover:border-subtle hover:text-primary hover:bg-elevated'
                        }`}
                      >
                        <div className="font-mono">{z.code}</div>
                        <div className="text-2xs text-muted">
                          {z.name} · {z.type_label}
                        </div>
                        <div className="text-2xs text-muted">{z.locations.length} bins</div>
                      </button>
                    ))}
                  </div>

                  {/* Bin grid */}
                  <div className="col-span-6">
                    {zone && (
                      <div className="mb-2">
                        <Input
                          fieldSize="sm"
                          placeholder="Search bin or item…"
                          value={binQuery}
                          onChange={(e) => setBinQuery(e.target.value)}
                          prefix={<LuSearch size={13} />}
                          className="font-mono"
                          containerClassName="max-w-xs"
                        />
                      </div>
                    )}
                    <div className="text-xs text-muted mb-2">
                      {zone
                        ? `${zone.name} — ${visibleBins.length} ${visibleBins.length === 1 ? 'bin' : 'bins'}${binQuery.trim() ? ' matching' : ''}`
                        : 'Select a zone to view bins'}
                    </div>
                    {zone && (
                      /* Bins get tapped while walking a rack with a tablet in one hand, so
              the three data rows sit at 12px rather than 10px and the grid drops
              to two columns on a phone — five 10px columns truncated the bin
              code, which is the one string the picker is matching against. */
                      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-1.5">
                        {visibleBins.map((loc) => (
                          <button
                            key={loc.id}
                            type="button"
                            onClick={() => setSelectedBin({ id: loc.id, detail: loc })}
                            className={cn(
                              'text-left p-2.5 rounded-md text-sm transition-colors cursor-pointer',
                              focusRing,
                              STATUS_COLORS[loc.stock_status] || STATUS_COLORS['empty'],
                              selectedBin?.id === loc.id && 'ring-2 ring-accent',
                            )}
                          >
                            <div className="font-mono font-medium truncate">{loc.code}</div>
                            <div className="text-xs mt-0.5 truncate">
                              {loc.stock_status === 'empty'
                                ? '—'
                                : loc.current_item
                                  ? `${loc.current_item.code}`
                                  : '—'}
                            </div>
                            <div className="text-xs font-mono tabular-nums">
                              {loc.stock_status !== 'empty'
                                ? `${Number(loc.current_quantity).toFixed(1)}`
                                : ''}
                            </div>
                            {loc.is_blocked && (
                              <div
                                className="text-xs text-danger-fg mt-0.5 truncate"
                                title={loc.blocked_reason ?? ''}
                              >
                                ⛔ {loc.stock_status_label ?? loc.stock_status}
                              </div>
                            )}
                          </button>
                        ))}
                      </div>
                    )}
                    {zone && visibleBins.length === 0 && (
                      <div className="text-sm text-muted py-5 text-center">
                        No bins match “{binQuery}”.
                      </div>
                    )}
                  </div>

                  {/* Bin detail panel */}
                  <div className="col-span-3 space-y-3">
                    {selectedBin && (
                      <Panel title={`Bin: ${selectedBin.detail.full_code}`}>
                        <div className="text-xs space-y-2">
                          <StatusRow label="Status">
                            <Chip
                              variant={
                                selectedBin.detail.stock_status === 'ok'
                                  ? 'success'
                                  : selectedBin.detail.stock_status === 'low'
                                    ? 'warning'
                                    : selectedBin.detail.stock_status === 'full'
                                      ? 'info'
                                      : selectedBin.detail.stock_status === 'blocked'
                                        ? 'danger'
                                        : 'neutral'
                              }
                            >
                              {selectedBin.detail.stock_status_label ??
                                selectedBin.detail.stock_status}
                            </Chip>
                          </StatusRow>
                          <StatusRow label="Code">{selectedBin.detail.code}</StatusRow>
                          {selectedBin.detail.rack && (
                            <StatusRow label="Rack">{selectedBin.detail.rack}</StatusRow>
                          )}
                          {selectedBin.detail.bin && (
                            <StatusRow label="Bin">{selectedBin.detail.bin}</StatusRow>
                          )}
                          {selectedBin.detail.capacity_kg && (
                            <StatusRow label="Capacity">
                              {Number(selectedBin.detail.capacity_kg).toFixed(0)} kg
                            </StatusRow>
                          )}
                          {selectedBin.detail.current_item && (
                            <>
                              <StatusRow label="Item">
                                {selectedBin.detail.current_item.code} —{' '}
                                {selectedBin.detail.current_item.name}
                              </StatusRow>
                              <StatusRow label="Quantity">
                                {Number(selectedBin.detail.current_quantity).toFixed(3)}
                              </StatusRow>
                              {selectedBin.detail.current_lot_number && (
                                <StatusRow label="Lot">
                                  {selectedBin.detail.current_lot_number}
                                </StatusRow>
                              )}
                            </>
                          )}
                          {selectedBin.detail.is_blocked && (
                            <StatusRow label="Blocked">
                              {selectedBin.detail.blocked_reason ?? 'Yes'}
                            </StatusRow>
                          )}

                          {binDetail.data && (
                            <div className="border-t border-subtle pt-2 mt-2 space-y-1">
                              <div className="text-2xs uppercase tracking-wider text-muted font-medium">
                                Stock levels
                              </div>
                              {binDetail.data.stock_levels.length === 0 ? (
                                <div className="text-muted">No stock at this bin.</div>
                              ) : (
                                binDetail.data.stock_levels.map((sl, i) => (
                                  <div key={i} className="text-2xs">
                                    <span className="font-mono">{sl.item_code}</span>
                                    <span className="text-muted ml-1">
                                      {Number(sl.quantity).toFixed(3)}
                                    </span>
                                    <span className="text-muted ml-1">
                                      avail: {Number(sl.available).toFixed(3)}
                                    </span>
                                  </div>
                                ))
                              )}
                              {binDetail.data.last_movement && (
                                <div className="text-2xs text-muted pt-1">
                                  Last:{' '}
                                  {binDetail.data.last_movement.movement_type_label ??
                                    binDetail.data.last_movement.movement_type.replace(/_/g, ' ')}
                                  {' · '}
                                  {formatDate(binDetail.data.last_movement.created_at)}
                                </div>
                              )}
                            </div>
                          )}
                        </div>
                      </Panel>
                    )}
                    {!selectedBin && (
                      <div className="text-sm text-muted text-center py-5">
                        Click a bin to see details.
                      </div>
                    )}
                  </div>
                </div>
              </>
            )}
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
