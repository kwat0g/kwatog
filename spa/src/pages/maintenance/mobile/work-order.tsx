import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import { itemsApi } from '@/api/inventory/items';
import toast from 'react-hot-toast';
import { ArrowLeft, Plus, Trash2, Play, CheckCircle2, AlertTriangle } from 'lucide-react';
import { BottomSheet } from '@/components/ui/BottomSheet';
import type { SparePartUsage } from '@/types/maintenance';
import type { Item } from '@/types/inventory';
import { client } from '@/api/client';
import type { ApiSuccess, PaginatedResponse } from '@/types';
import { formatDateTime } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';

export default function MobileWorkOrderDetail() {
  const { mwoId } = useParams<{ mwoId: string }>();
  const queryClient = useQueryClient();

  // ── Fetch MWO details ──────────────────────────────────
  const { data: wo, isLoading, error, refetch } = useQuery({
    queryKey: ['maintenance', 'mwo', mwoId],
    queryFn: () => workOrdersApi.show(mwoId!),
    enabled: !!mwoId,
  });

  // ── Form state ─────────────────────────────────────────
  const [remarks, setRemarks] = useState('');
  const [downtimeMinutes, setDowntimeMinutes] = useState('');
  const [showPartSheet, setShowPartSheet] = useState(false);

  // ── Start mutation ─────────────────────────────────────
  const startMutation = useMutation({
    mutationFn: () => workOrdersApi.start(mwoId!),
    onSuccess: () => {
      toast.success('Work order started');
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'mwo', mwoId] });
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'mobile-mwos'] });
    },
    onError: () => toast.error('Failed to start work order.'),
  });

  // ── Complete mutation ──────────────────────────────────
  const completeMutation = useMutation({
    mutationFn: () =>
      workOrdersApi.complete(mwoId!, {
        remarks: remarks.trim() || undefined,
        downtime_minutes: parseInt(downtimeMinutes, 10) || 0,
      }),
    onSuccess: () => {
      toast.success('Work order completed');
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'mwo', mwoId] });
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'mobile-mwos'] });
    },
    onError: () => toast.error('Failed to complete work order.'),
  });

  // ── Spare part recording ───────────────────────────────
  const [partSearch, setPartSearch] = useState('');
  const [selectedItem, setSelectedItem] = useState<Item | null>(null);
  const [partQty, setPartQty] = useState('');
  const [partLocationId, setPartLocationId] = useState('');

  const { data: itemsData } = useQuery({
    queryKey: ['inventory', 'items', 'spare_parts', partSearch],
    queryFn: () => itemsApi.list({ item_type: 'spare_part', search: partSearch, per_page: 20 }),
    enabled: showPartSheet && partSearch.length >= 2,
  });

  // Fetch stock levels for selected item to pick location
  const { data: stockData } = useQuery({
    queryKey: ['inventory', 'stock-levels', selectedItem?.id],
    queryFn: () =>
      client
        .get<PaginatedResponse<{ id: string; location: { id: string; code: string }; quantity_on_hand: string }>>('/inventory/stock-levels', {
          params: { item_id: selectedItem?.id, per_page: 50 },
        })
        .then(r => r.data),
    enabled: !!selectedItem,
  });

  const sparePartMutation = useMutation({
    mutationFn: (data: { item_id: string; location_id: string; quantity: string }) =>
      client
        .post<ApiSuccess<SparePartUsage>>(`/maintenance/work-orders/${mwoId}/spare-parts`, data)
        .then(r => r.data.data),
    onSuccess: () => {
      toast.success('Spare part recorded');
      setSelectedItem(null);
      setPartQty('');
      setPartLocationId('');
      setPartSearch('');
      setShowPartSheet(false);
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'mwo', mwoId] });
    },
    onError: () => toast.error('Failed to record spare part.'),
  });

  const canAddPart = selectedItem && partLocationId && parseFloat(partQty) > 0;

  // ── Loading / Error states ─────────────────────────────
  if (isLoading) {
    return (
      <div role="status" aria-live="polite" aria-busy="true" className="space-y-3 animate-pulse">
        <span className="sr-only">Loading work order...</span>
        <div className="h-6 w-24 rounded bg-elevated" />
        <div className="h-48 rounded-md bg-elevated" />
        <div className="h-48 rounded-md bg-elevated" />
      </div>
    );
  }

  if (error || !wo) {
    return (
      <div className="py-12 text-center" role="alert">
        <div className="text-danger mb-2">Could not load work order.</div>
        <button
          type="button"
          onClick={() => refetch()}
          className="text-sm underline min-h-[44px] px-3 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 rounded"
        >
          Try again
        </button>
      </div>
    );
  }

  const isTerminal = wo.status === 'completed' || wo.status === 'cancelled';
  const canStart = wo.status === 'open' || wo.status === 'assigned';
  const canComplete = wo.status === 'in_progress';

  return (
    <div className="space-y-4">
      {/* Back link */}
      <Link
        to="/maintenance/mobile"
        className="inline-flex items-center gap-1.5 text-sm text-secondary min-h-[44px] rounded focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
      >
        <ArrowLeft className="w-4 h-4" />
        Back to list
      </Link>

      {/* MWO Summary card */}
      <div className="rounded-md border border-default bg-canvas p-4">
        <div className="flex items-center justify-between">
          <span className="font-mono text-sm font-medium">{wo.mwo_number}</span>
          <span
            className={`text-xs px-2 py-0.5 rounded font-medium ${
              wo.priority === 'critical'
                ? 'bg-danger-bg text-danger-fg'
                : wo.priority === 'high'
                  ? 'bg-warning-bg text-warning-fg'
                  : 'bg-info-bg text-info-fg'
            }`}
          >
            {wo.priority === 'critical' && <AlertTriangle className="w-3 h-3 inline mr-1" />}
            {wo.priority}
          </span>
        </div>

        <div className="mt-2 text-sm font-medium">{wo.maintainable?.name ?? 'Unknown target'}</div>
        <div className="text-xs text-muted mt-0.5">
          {wo.maintainable?.code ? `(${wo.maintainable.code})` : ''} &middot;{' '}
          <span className="capitalize">{wo.type}</span> &middot;{' '}
          <span className="capitalize">{wo.status.replace(/_/g, ' ')}</span>
        </div>

        {wo.description && (
          <p className="mt-3 text-sm text-secondary whitespace-pre-wrap">
            {wo.description}
          </p>
        )}

        {wo.assignee && (
          <div className="mt-3 text-xs text-muted">
            Assigned to: <span className="font-medium text-secondary">{wo.assignee.name}</span>
          </div>
        )}
      </div>

      {/* Start button */}
      {canStart && !isTerminal && (
        <button
          type="button"
          onClick={() => startMutation.mutate()}
          disabled={startMutation.isPending}
          className="w-full min-h-[52px] rounded-md bg-success hover:bg-success/90 disabled:bg-elevated text-white font-medium text-base transition-colors focus:outline-none focus:ring-2 focus:ring-success focus:ring-offset-2 inline-flex items-center justify-center gap-2"
        >
          <Play className="w-5 h-5" />
          {startMutation.isPending ? 'Starting...' : 'Start Work'}
        </button>
      )}

      {/* Parts Used section */}
      {!isTerminal && (
        <div className="rounded-md border border-default bg-canvas p-4">
          <div className="flex items-center justify-between mb-3">
            <h2 className="text-base font-medium">Parts Used</h2>
            <button
              type="button"
              onClick={() => setShowPartSheet(true)}
              className="inline-flex items-center gap-1 text-sm text-accent min-h-[44px] px-3 rounded focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
            >
              <Plus className="w-4 h-4" />
              Add
            </button>
          </div>

          {wo.spare_parts && wo.spare_parts.length > 0 ? (
            <div className="space-y-2">
              {wo.spare_parts.map((sp: SparePartUsage) => (
                <div
                  key={sp.id}
                  className="flex items-center justify-between p-2 rounded bg-surface text-sm"
                >
                  <div>
                    <div className="font-medium">{sp.item?.name ?? 'Unknown'}</div>
                    <div className="text-xs text-muted">
                      {sp.item?.code} &middot; Qty: <span className="font-mono tabular-nums">{sp.quantity}</span>
                    </div>
                  </div>
                  <div className="font-mono tabular-nums text-xs text-muted">
                    {formatPeso(sp.total_cost)}
                  </div>
                </div>
              ))}
              <div className="text-right text-xs text-muted pt-1 border-t border-subtle">
                Total cost:{' '}
                <span className="font-mono tabular-nums font-medium text-primary">
                  {formatPeso(wo.cost)}
                </span>
              </div>
            </div>
          ) : (
            <p className="text-sm text-muted">No parts recorded yet.</p>
          )}
        </div>
      )}

      {/* Read-only parts for terminal states */}
      {isTerminal && wo.spare_parts && wo.spare_parts.length > 0 && (
        <div className="rounded-md border border-default bg-canvas p-4">
          <h2 className="text-base font-medium mb-3">Parts Used</h2>
          <div className="space-y-2">
            {wo.spare_parts.map((sp: SparePartUsage) => (
              <div
                key={sp.id}
                className="flex items-center justify-between p-2 rounded bg-surface text-sm"
              >
                <div>
                  <div className="font-medium">{sp.item?.name ?? 'Unknown'}</div>
                  <div className="text-xs text-muted">
                    Qty: <span className="font-mono tabular-nums">{sp.quantity}</span>
                  </div>
                </div>
                <div className="font-mono tabular-nums text-xs text-muted">
                  {formatPeso(sp.total_cost)}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Completion form */}
      {canComplete && (
        <form
          onSubmit={e => {
            e.preventDefault();
            completeMutation.mutate();
          }}
          className="rounded-md border border-default bg-canvas p-4 space-y-4"
        >
          <h2 className="text-base font-medium">Complete Work Order</h2>

          <div>
            <label htmlFor="remarks" className="block text-sm font-medium text-secondary mb-1">
              Work Performed
            </label>
            <textarea
              id="remarks"
              value={remarks}
              onChange={e => setRemarks(e.target.value)}
              rows={3}
              placeholder="Describe what was done..."
              className="w-full rounded-md border border-default bg-canvas px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-accent focus:border-accent resize-none"
            />
          </div>

          <div>
            <label htmlFor="downtime" className="block text-sm font-medium text-secondary mb-1">
              Downtime (minutes)
            </label>
            <input
              id="downtime"
              type="number"
              inputMode="numeric"
              min="0"
              value={downtimeMinutes}
              onChange={e => setDowntimeMinutes(e.target.value)}
              placeholder="0"
              className="w-full rounded-md border border-default bg-canvas px-4 py-4 text-2xl font-mono tabular-nums text-center focus:outline-none focus:ring-2 focus:ring-accent focus:border-accent"
            />
          </div>

          <button
            type="submit"
            disabled={completeMutation.isPending}
            className="w-full min-h-[52px] rounded-md bg-accent hover:bg-accent-hover disabled:bg-elevated text-white font-medium text-base transition-colors focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 inline-flex items-center justify-center gap-2"
          >
            <CheckCircle2 className="w-5 h-5" />
            {completeMutation.isPending ? 'Completing...' : 'Complete Work Order'}
          </button>
        </form>
      )}

      {/* Activity log */}
      {wo.logs && wo.logs.length > 0 && (
        <div className="rounded-md border border-default bg-canvas p-4">
          <h2 className="text-base font-medium mb-3">Activity Log</h2>
          <div className="space-y-2">
            {wo.logs.map(log => (
              <div key={log.id} className="text-sm">
                <div className="text-secondary">{log.description}</div>
                <div className="text-xs text-muted mt-0.5 font-mono tabular-nums">
                  {log.logger?.name ?? 'System'}
                  {log.created_at && ` — ${formatDateTime(log.created_at)}`}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* ── Add Spare Part Bottom Sheet ─────────────────── */}
      <BottomSheet
        isOpen={showPartSheet}
        onClose={() => {
          setShowPartSheet(false);
          setSelectedItem(null);
          setPartSearch('');
          setPartQty('');
          setPartLocationId('');
        }}
        title="Add Spare Part"
      >
        <div className="space-y-4">
          {/* Item search */}
          {!selectedItem ? (
            <>
              <div>
                <label htmlFor="part_search" className="block text-sm font-medium text-secondary mb-1">
                  Search spare parts
                </label>
                <input
                  id="part_search"
                  type="text"
                  value={partSearch}
                  onChange={e => setPartSearch(e.target.value)}
                  placeholder="Type to search..."
                  autoFocus
                  className="w-full rounded-md border border-default bg-canvas px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-accent focus:border-accent"
                />
              </div>

              {itemsData?.data && itemsData.data.length > 0 && (
                <div className="space-y-1 max-h-[40vh] overflow-y-auto">
                  {itemsData.data.map((item: Item) => (
                    <button
                      key={item.id}
                      type="button"
                      onClick={() => setSelectedItem(item)}
                      className="w-full text-left p-3 rounded-md hover:bg-surface active:bg-elevated min-h-[44px] focus:outline-none focus:ring-2 focus:ring-accent"
                    >
                      <div className="text-sm font-medium">{item.name}</div>
                      <div className="text-xs text-muted">{item.code} &middot; {item.unit_of_measure}</div>
                    </button>
                  ))}
                </div>
              )}

              {partSearch.length >= 2 && itemsData?.data?.length === 0 && (
                <p className="text-sm text-muted text-center py-4">No spare parts found.</p>
              )}
            </>
          ) : (
            <>
              {/* Selected item summary */}
              <div className="flex items-center justify-between p-3 rounded-md bg-surface">
                <div>
                  <div className="text-sm font-medium">{selectedItem.name}</div>
                  <div className="text-xs text-muted">{selectedItem.code}</div>
                </div>
                <button
                  type="button"
                  onClick={() => {
                    setSelectedItem(null);
                    setPartLocationId('');
                    setPartQty('');
                  }}
                  className="text-muted hover:text-danger min-h-[44px] min-w-[44px] flex items-center justify-center rounded focus:outline-none focus:ring-2 focus:ring-danger"
                >
                  <Trash2 className="w-4 h-4" />
                </button>
              </div>

              {/* Location picker */}
              <div>
                <label htmlFor="part_location" className="block text-sm font-medium text-secondary mb-1">
                  Source Location
                </label>
                <select
                  id="part_location"
                  value={partLocationId}
                  onChange={e => setPartLocationId(e.target.value)}
                  className="w-full rounded-md border border-default bg-canvas px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-accent focus:border-accent min-h-[44px]"
                >
                  <option value="">Select location...</option>
                  {stockData?.data?.map(
                    (sl: { id: string; location: { id: string; code: string }; quantity_on_hand: string }) => (
                      <option key={sl.location.id} value={sl.location.id}>
                        {sl.location.code} (Qty: {sl.quantity_on_hand})
                      </option>
                    ),
                  )}
                </select>
              </div>

              {/* Quantity */}
              <div>
                <label htmlFor="part_qty" className="block text-sm font-medium text-secondary mb-1">
                  Quantity ({selectedItem.unit_of_measure})
                </label>
                <input
                  id="part_qty"
                  type="number"
                  inputMode="decimal"
                  min="0"
                  step="0.01"
                  value={partQty}
                  onChange={e => setPartQty(e.target.value)}
                  placeholder="0"
                  className="w-full rounded-md border border-default bg-canvas px-4 py-4 text-xl font-mono tabular-nums text-center focus:outline-none focus:ring-2 focus:ring-accent focus:border-accent"
                />
              </div>

              {/* Submit */}
              <button
                type="button"
                disabled={!canAddPart || sparePartMutation.isPending}
                onClick={() => {
                  if (!canAddPart) return;
                  sparePartMutation.mutate({
                    item_id: selectedItem.id,
                    location_id: partLocationId,
                    quantity: partQty,
                  });
                }}
                className="w-full min-h-[52px] rounded-md bg-accent hover:bg-accent-hover disabled:bg-elevated text-white font-medium text-base transition-colors focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
              >
                {sparePartMutation.isPending ? 'Recording...' : 'Add Part'}
              </button>
            </>
          )}
        </div>
      </BottomSheet>
    </div>
  );
}
