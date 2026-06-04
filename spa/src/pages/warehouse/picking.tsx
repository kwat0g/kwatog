import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { MapPin, Package, Truck } from 'lucide-react';
import { pickingListApi } from '@/api/inventory/warehouseWms';
import { materialIssuesApi } from '@/api/inventory/material-issues';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock, SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';


export default function PickingListPage() {
  const { can } = usePermission();
  const canPick = can('inventory.picking.manage');

  const [selectedMis, setSelectedMis] = useState<string | null>(null);

  const { data: slipResp, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'material-issues', 'list'],
    queryFn: () => materialIssuesApi.list({ status: 'issued', per_page: 50 }),
  });
  const slips = slipResp?.data ?? [];

  const { data: pickingList, isFetching: loadingList } = useQuery({
    queryKey: ['inventory', 'picking', selectedMis],
    queryFn: () => pickingListApi.forMis(selectedMis!),
    enabled: !!selectedMis,
  });

  return (
    <div>
      <PageHeader
        title="Picking Lists"
        backTo="/inventory/items"
        backLabel="Items"
        subtitle={slipResp ? `${slips.length} ready to pick` : undefined}
      />

      <div className="px-5 py-4">
        {isLoading && !slipResp && <SkeletonTable rows={4} columns={4} />}
        {isError && <EmptyState icon="alert-circle" title="Failed to load" action={<Button onClick={() => refetch()}>Retry</Button>} />}
        {slipResp && slips.length === 0 && (
          <EmptyState
            icon="inbox"
            title="No material issues to pick"
            description="All issued slips have been picked, or none are in 'issued' status."
          />
        )}
        {slipResp && slips.length > 0 && (
          <div className="grid grid-cols-12 gap-4">
            {/* Slip list */}
            <div className="col-span-3 space-y-1">
              <div className="text-2xs uppercase tracking-wider text-muted font-medium px-1 mb-1">
                Issued slips ({slips.length})
              </div>
              {slips.map((slip: { id: string; slip_number?: string; status: string; work_order?: string; issued_date?: string; created_at?: string }) => (
                <button
                  key={slip.id}
                  type="button"
                  onClick={() => setSelectedMis(slip.id)}
                  className={`w-full text-left px-2 py-1.5 text-xs rounded-md transition-colors ${
                    selectedMis === slip.id ? 'bg-accent/10 text-accent border border-accent/20' : 'text-muted hover:text-primary hover:bg-elevated'
                  }`}
                >
                  <div className="font-mono">{slip.slip_number}</div>
                  <div className="truncate">{slip.work_order ?? '—'}</div>
                  <div className="text-2xs text-muted mt-0.5">{formatDate(slip.issued_date ?? slip.created_at)}</div>
                </button>
              ))}
            </div>

            {/* Picking list detail */}
            <div className="col-span-9 space-y-3">
              {!selectedMis && (
                <div className="text-sm text-muted text-center py-8">Select a slip to view its picking list.</div>
              )}
              {selectedMis && loadingList && (
                <div className="space-y-3">
                  <SkeletonBlock className="h-40" />
                  <SkeletonBlock className="h-60" />
                </div>
              )}
              {pickingList && (
                <>
                  {/* Header */}
                  <Panel
                    title={`Picking: ${pickingList.slip_number}`}
                    meta={pickingList.work_order ? `Work Order: ${pickingList.work_order}` : undefined}
                  >
                    <div className="flex gap-4 text-xs text-muted">
                      <span>{pickingList.total_lines} lines</span>
                      <span>{pickingList.total_items} unique items</span>
                      <span>Issued: {formatDate(pickingList.issued_date)}</span>
                    </div>
                  </Panel>

                  {/* Picking lines */}
                  <Panel title="Pick items">
                    <div className="space-y-3">
                      {pickingList.lines.map((line, li) => (
                        <div key={li} className="border border-subtle rounded-md bg-surface/50">
                          {/* Line header */}
                          <div className="flex items-center justify-between px-3 py-1.5 border-b border-subtle bg-elevated/30">
                            <div className="flex items-center gap-2 text-xs">
                              <Package size={14} className="text-muted" />
                              <span className="font-mono font-medium">{line.item_code}</span>
                              <span className="text-muted">{line.item_name}</span>
                            </div>
                            <div className="text-xs">
                              Need: <span className="font-mono font-medium text-primary">{Number(line.quantity_required).toFixed(3)}</span>
                              {' '}{line.unit_of_measure}
                            </div>
                          </div>

                          {/* Suggestions */}
                          <div className="p-2 space-y-1">
                            {line.suggestions.length === 0 && (
                              <div className="text-xs text-muted italic px-2 py-1">
                                No available stock at any location.
                              </div>
                            )}
                            {line.suggestions.map((sug, si) => (
                              <div
                                key={si}
                                className="flex items-center justify-between px-2 py-1 rounded text-xs hover:bg-elevated/50 transition-colors"
                              >
                                <div className="flex items-center gap-2 min-w-0">
                                  <MapPin size={12} className="shrink-0 text-accent" />
                                  <span className="font-mono">{sug.location.full_code}</span>
                                  <span className="text-muted text-2xs">
                                    {sug.location.zone} / {sug.location.warehouse}
                                  </span>
                                </div>
                                <div className="flex items-center gap-3 shrink-0">
                                  {sug.lot_number && (
                                    <span className="text-2xs text-muted">Lot: {sug.lot_number}</span>
                                  )}
                                  <span className="text-muted text-2xs">
                                    Avail: <span className="font-mono">{Number(sug.quantity_available).toFixed(3)}</span>
                                  </span>
                                  <Chip variant="info">
                                    Pick: {Number(sug.quantity_to_pick).toFixed(3)}
                                  </Chip>
                                </div>
                              </div>
                            ))}
                          </div>
                        </div>
                      ))}
                    </div>
                  </Panel>

                  {/* Action */}
                  <div className="flex justify-end gap-2 pt-1">
                    <Button
                      variant="primary"
                      size="sm"
                      icon={<Truck size={14} />}
                      disabled={!canPick}
                      onClick={() => {
                        // Navigate to detail for recording the pick
                        window.location.href = `/inventory/material-issues/${selectedMis}`;
                      }}
                    >
                      Record picks
                    </Button>
                  </div>
                </>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
