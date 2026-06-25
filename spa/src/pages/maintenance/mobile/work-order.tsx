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
        <div className="h-6 w-24 rounded bg-zinc-100 dark:bg-zinc-800" />
        <div className="h-48 rounded-lg bg-zinc-100 dark:bg-zinc-800" />
        <div className="h-48 rounded-lg bg-zinc-100 dark:bg-zinc-800" />
      </div>
    );
  }

  if (error || !wo) {
    return (
      <div className="py-12 text-center" role="alert">
        <div className="text-red-600 dark:text-red-400 mb-2">Could not load work order.</div>
        <button
          type="button"
          onClick={() => refetch()}
          className="text-sm underline min-h-[44px] px-3 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 rounded"
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
        className="inline-flex items-center gap-1.5 text-sm text-zinc-600 dark:text-zinc-400 min-h-[44px] rounded focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
      >
        <ArrowLeft className="w-4 h-4" />
        Back to list
      </Link>

      {/* MWO Summary card */}
      <div className="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4">
        <div className="flex items-center justify-between">
          <span className="font-mono text-sm font-medium">{wo.mwo_number}</span>
          <span
            className={`text-xs px-2 py-0.5 rounded font-medium ${
              wo.priority === 'critical'
                ? 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200'
                : wo.priority === 'high'
                  ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'
                  : 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200'
            }`}
          >
            {wo.priority === 'critical' && <AlertTriangle className="w-3 h-3 inline mr-1" />}
            {wo.priority}
          </span>
        </div>

        <div className="mt-2 text-sm font-medium">{wo.maintainable?.name ?? 'Unknown target'}</div>
        <div className="text-xs text-zinc-500 mt-0.5">
          {wo.maintainable?.code ? `(${wo.maintainable.code})` : ''} &middot;{' '}
          <span className="capitalize">{wo.type}</span> &middot;{' '}
          <span className="capitalize">{wo.status.replace(/_/g, ' ')}</span>
        </div>

        {wo.description && (
          <p className="mt-3 text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">
            {wo.description}
          </p>
        )}

        {wo.assignee && (
          <div className="mt-3 text-xs text-zinc-500">
            Assigned to: <span className="font-medium text-zinc-700 dark:text-zinc-300">{wo.assignee.name}</span>
          </div>
        )}
      </div>

      {/* Start button */}
      {canStart && !isTerminal && (
        <button
          type="button"
          onClick={() => startMutation.mutate()}
          disabled={startMutation.isPending}
          className="w-full min-h-[52px] rounded-lg bg-emerald-600 hover:bg-emerald-700 disabled:bg-zinc-300 dark:disabled:bg-zinc-700 text-white font-semibold text-base transition-colors focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 inline-flex items-center justify-center gap-2"
        >
          <Play className="w-5 h-5" />
          {startMutation.isPending ? 'Starting...' : 'Start Work'}
        </button>
      )}

      {/* Parts Used section */}
      {!isTerminal && (
        <div className="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4">
          <div className="flex items-center justify-between mb-3">
            <h2 className="text-base font-semibold">Parts Used</h2>
            <button
              type="button"
              onClick={() => setShowPartSheet(true)}
              className="inline-flex items-center gap-1 text-sm text-indigo-600 dark:text-indigo-400 min-h-[44px] px-3 rounded focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
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
                  className="flex items-center justify-between p-2 rounded bg-zinc-50 dark:bg-zinc-800/50 text-sm"
                >
                  <div>
                    <div className="font-medium">{sp.item?.name ?? 'Unknown'}</div>
                    <div className="text-xs text-zinc-500">
                      {sp.item?.code} &middot; Qty: <span className="font-mono tabular-nums">{sp.quantity}</span>
                    </div>
                  </div>
                  <div className="font-mono tabular-nums text-xs text-zinc-500">
                    ₱{parseFloat(sp.total_cost).toLocaleString()}
                  </div>
                </div>
              ))}
              <div className="text-right text-xs text-zinc-500 pt-1 border-t border-zinc-100 dark:border-zinc-800">
                Total cost:{' '}
                <span className="font-mono tabular-nums font-medium text-zinc-900 dark:text-zinc-100">
                  ₱{parseFloat(wo.cost).toLocaleString()}
                </span>
              </div>
            </div>
          ) : (
            <p className="text-sm text-zinc-500">No parts recorded yet.</p>
          )}
        </div>
      )}

      {/* Read-only parts for terminal states */}
      {isTerminal && wo.spare_parts && wo.spare_parts.length > 0 && (
        <div className="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4">
          <h2 className="text-base font-semibold mb-3">Parts Used</h2>
          <div className="space-y-2">
            {wo.spare_parts.map((sp: SparePartUsage) => (
              <div
                key={sp.id}
                className="flex items-center justify-between p-2 rounded bg-zinc-50 dark:bg-zinc-800/50 text-sm"
              >
                <div>
                  <div className="font-medium">{sp.item?.name ?? 'Unknown'}</div>
                  <div className="text-xs text-zinc-500">
                    Qty: <span className="font-mono tabular-nums">{sp.quantity}</span>
                  </div>
                </div>
                <div className="font-mono tabular-nums text-xs text-zinc-500">
                  ₱{parseFloat(sp.total_cost).toLocaleString()}
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
          className="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 space-y-4"
        >
          <h2 className="text-base font-semibold">Complete Work Order</h2>

          <div>
            <label htmlFor="remarks" className="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
              Work Performed
            </label>
            <textarea
              id="remarks"
              value={remarks}
              onChange={e => setRemarks(e.target.value)}
              rows={3}
              placeholder="Describe what was done..."
              className="w-full rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 resize-none"
            />
          </div>

          <div>
            <label htmlFor="downtime" className="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
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
              className="w-full rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-4 text-2xl font-mono tabular-nums text-center focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>

          <button
            type="submit"
            disabled={completeMutation.isPending}
            className="w-full min-h-[52px] rounded-lg bg-indigo-600 hover:bg-indigo-700 disabled:bg-zinc-300 dark:disabled:bg-zinc-700 text-white font-semibold text-base transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 inline-flex items-center justify-center gap-2"
          >
            <CheckCircle2 className="w-5 h-5" />
            {completeMutation.isPending ? 'Completing...' : 'Complete Work Order'}
          </button>
        </form>
      )}

      {/* Activity log */}
      {wo.logs && wo.logs.length > 0 && (
        <div className="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4">
          <h2 className="text-base font-semibold mb-3">Activity Log</h2>
          <div className="space-y-2">
            {wo.logs.map(log => (
              <div key={log.id} className="text-sm">
                <div className="text-zinc-700 dark:text-zinc-300">{log.description}</div>
                <div className="text-xs text-zinc-400 mt-0.5 font-mono tabular-nums">
                  {log.logger?.name ?? 'System'}
                  {log.created_at && ` — ${new Date(log.created_at).toLocaleString()}`}
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
                <label htmlFor="part_search" className="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                  Search spare parts
                </label>
                <input
                  id="part_search"
                  type="text"
                  value={partSearch}
                  onChange={e => setPartSearch(e.target.value)}
                  placeholder="Type to search..."
                  autoFocus
                  className="w-full rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                />
              </div>

              {itemsData?.data && itemsData.data.length > 0 && (
                <div className="space-y-1 max-h-[40vh] overflow-y-auto">
                  {itemsData.data.map((item: Item) => (
                    <button
                      key={item.id}
                      type="button"
                      onClick={() => setSelectedItem(item)}
                      className="w-full text-left p-3 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 active:bg-zinc-100 dark:active:bg-zinc-700 min-h-[44px] focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                      <div className="text-sm font-medium">{item.name}</div>
                      <div className="text-xs text-zinc-500">{item.code} &middot; {item.unit_of_measure}</div>
                    </button>
                  ))}
                </div>
              )}

              {partSearch.length >= 2 && itemsData?.data?.length === 0 && (
                <p className="text-sm text-zinc-500 text-center py-4">No spare parts found.</p>
              )}
            </>
          ) : (
            <>
              {/* Selected item summary */}
              <div className="flex items-center justify-between p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800/50">
                <div>
                  <div className="text-sm font-medium">{selectedItem.name}</div>
                  <div className="text-xs text-zinc-500">{selectedItem.code}</div>
                </div>
                <button
                  type="button"
                  onClick={() => {
                    setSelectedItem(null);
                    setPartLocationId('');
                    setPartQty('');
                  }}
                  className="text-zinc-400 hover:text-red-500 min-h-[44px] min-w-[44px] flex items-center justify-center rounded focus:outline-none focus:ring-2 focus:ring-red-500"
                >
                  <Trash2 className="w-4 h-4" />
                </button>
              </div>

              {/* Location picker */}
              <div>
                <label htmlFor="part_location" className="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                  Source Location
                </label>
                <select
                  id="part_location"
                  value={partLocationId}
                  onChange={e => setPartLocationId(e.target.value)}
                  className="w-full rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 min-h-[44px]"
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
                <label htmlFor="part_qty" className="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
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
                  className="w-full rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-4 text-xl font-mono tabular-nums text-center focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
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
                className="w-full min-h-[52px] rounded-lg bg-indigo-600 hover:bg-indigo-700 disabled:bg-zinc-300 dark:disabled:bg-zinc-700 text-white font-semibold text-base transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
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
