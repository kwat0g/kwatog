import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import { itemsApi } from '@/api/inventory/items';
import toast from 'react-hot-toast';
import { ArrowLeft, Plus, Trash2, Play, CheckCircle2, AlertTriangle } from 'lucide-react';
import { BottomSheet } from '@/components/ui/BottomSheet';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import {
  TouchCardSkeleton,
  TouchConfirmSheet,
  useTouchSubmitLabel,
} from '@/components/layout/TouchShell';
import { maintenancePriorityVariant } from '@/lib/statusVariants';
import type { SparePartUsage } from '@/types/maintenance';
import type { Item } from '@/types/inventory';
import { client } from '@/api/client';
import type { ApiSuccess, PaginatedResponse } from '@/types';
import { formatDateTime } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { focusRingInset } from '@/lib/focus';
import { cn } from '@/lib/cn';
import { focusRing } from '@/lib/focus';

export default function MobileWorkOrderDetail() {
  const { mwoId } = useParams<{ mwoId: string }>();
  const queryClient = useQueryClient();

  // ── Fetch MWO details ──────────────────────────────────
  const {
    data: wo,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ['maintenance', 'mwo', mwoId],
    queryFn: () => workOrdersApi.show(mwoId!),
    enabled: !!mwoId,
  });

  // ── Form state ─────────────────────────────────────────
  const [remarks, setRemarks] = useState('');
  const [downtimeMinutes, setDowntimeMinutes] = useState('');
  const [showPartSheet, setShowPartSheet] = useState(false);
  const [confirmingComplete, setConfirmingComplete] = useState(false);

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
      setConfirmingComplete(false);
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'mwo', mwoId] });
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'mobile-mwos'] });
    },
    onError: () => {
      toast.error('Failed to complete work order.');
      setConfirmingComplete(false);
    },
  });

  // A tech in a plant basement is a likely offline case; say the commit is
  // queued rather than letting the button spin indefinitely.
  const completeLabel = useTouchSubmitLabel(completeMutation.isPending, '', 'Completing…');

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
        .get<
          PaginatedResponse<{
            id: string;
            location: { id: string; code: string };
            quantity_on_hand: string;
          }>
        >('/inventory/stock-levels', {
          params: { item_id: selectedItem?.id, per_page: 50 },
        })
        .then((r) => r.data),
    enabled: !!selectedItem,
  });

  const sparePartMutation = useMutation({
    mutationFn: (data: { item_id: string; location_id: string; quantity: string }) =>
      client
        .post<ApiSuccess<SparePartUsage>>(`/maintenance/work-orders/${mwoId}/spare-parts`, data)
        .then((r) => r.data.data),
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
    return <TouchCardSkeleton count={2} label="Loading work order" cardClassName="h-48" />;
  }

  if (error || !wo) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Could not load work order"
        description="Check your connection and try again."
        action={
          <Button
            type="button"
            variant="secondary"
            size="lg"
            className="min-h-[44px]"
            onClick={() => refetch()}
          >
            Try again
          </Button>
        }
      />
    );
  }

  const actions = wo.available_actions ?? [];
  const isTerminal = actions.length === 0;
  const canStart = actions.includes('start');
  const canComplete = actions.includes('complete');

  return (
    <div className="space-y-4">
      {/* Back link */}
      <Link
        to="/maintenance/mobile"
        className={cn(
          'inline-flex items-center gap-1.5 text-sm text-secondary min-h-[44px] rounded',
          focusRing,
        )}
      >
        <ArrowLeft className="w-4 h-4" />
        Back to list
      </Link>

      {/* MWO Summary card */}
      <div className="rounded-md border border-default bg-canvas p-4">
        <div className="flex items-center justify-between">
          <span className="font-mono tabular-nums text-sm font-medium">{wo.mwo_number}</span>
          <Chip variant={maintenancePriorityVariant[wo.priority]} className="gap-1">
            {wo.priority === 'critical' && <AlertTriangle className="w-3 h-3" aria-hidden />}
            {wo.priority_label ?? wo.priority}
          </Chip>
        </div>

        <div className="mt-2 text-sm font-medium">{wo.maintainable?.name ?? '—'}</div>
        <div className="text-xs text-muted mt-0.5">
          {wo.maintainable?.code ? `(${wo.maintainable.code})` : ''} &middot;{' '}
          <span>{wo.type_label ?? wo.type}</span> &middot;{' '}
          <span>{wo.status_label ?? wo.status}</span>
        </div>

        {wo.description && (
          <p className="mt-3 text-sm text-secondary whitespace-pre-wrap">{wo.description}</p>
        )}

        {wo.assignee && (
          <div className="mt-3 text-xs text-muted">
            Assigned to: <span className="font-medium text-secondary">{wo.assignee.name}</span>
          </div>
        )}
      </div>

      {/* Start button */}
      {canStart && !isTerminal && (
        <Button
          type="button"
          variant="primary"
          size="lg"
          className="w-full"
          icon={<Play className="w-5 h-5" />}
          onClick={() => startMutation.mutate()}
          loading={startMutation.isPending}
        >
          {startMutation.isPending ? 'Starting…' : 'Start work'}
        </Button>
      )}

      {/* Parts Used section */}
      {!isTerminal && (
        <div className="rounded-md border border-default bg-canvas p-4">
          <div className="flex items-center justify-between mb-3">
            <h2 className="text-base font-medium">Parts used</h2>
            <Button
              type="button"
              variant="ghost"
              size="lg"
              icon={<Plus className="w-4 h-4" />}
              onClick={() => setShowPartSheet(true)}
              className="text-accent"
            >
              Add
            </Button>
          </div>

          {wo.spare_parts && wo.spare_parts.length > 0 ? (
            <div className="space-y-2">
              {wo.spare_parts.map((sp: SparePartUsage) => (
                <div
                  key={sp.id}
                  className="flex items-center justify-between p-2 rounded bg-surface text-sm"
                >
                  <div>
                    <div className="font-medium">{sp.item?.name ?? '—'}</div>
                    <div className="text-xs text-muted">
                      {sp.item?.code} &middot; Qty:{' '}
                      <span className="font-mono tabular-nums">{sp.quantity}</span>
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
          <h2 className="text-base font-medium mb-3">Parts used</h2>
          <div className="space-y-2">
            {wo.spare_parts.map((sp: SparePartUsage) => (
              <div
                key={sp.id}
                className="flex items-center justify-between p-2 rounded bg-surface text-sm"
              >
                <div>
                  <div className="font-medium">{sp.item?.name ?? '—'}</div>
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
          onSubmit={(e) => {
            e.preventDefault();
            // Completion writes downtime minutes that feed OEE and closes the WO.
            // A tech taps this with the machine in front of him and one glove on;
            // it was a single tap with no way back.
            setConfirmingComplete(true);
          }}
          className="rounded-md border border-default bg-canvas p-4 space-y-4"
        >
          <h2 className="text-base font-medium">Complete work order</h2>

          <Textarea
            id="remarks"
            label="Work performed"
            value={remarks}
            onChange={(e) => setRemarks(e.target.value)}
            rows={3}
            placeholder="Describe what was done…"
            className="resize-none"
          />

          <Input
            id="downtime"
            label="Downtime (minutes)"
            fieldSize="xl"
            type="number"
            inputMode="numeric"
            min="0"
            value={downtimeMinutes}
            onChange={(e) => setDowntimeMinutes(e.target.value)}
            placeholder="0"
            className="text-center font-mono tabular-nums"
          />

          <Button
            type="submit"
            variant="primary"
            size="touch"
            className="w-full"
            icon={<CheckCircle2 className="w-5 h-5" />}
            loading={completeMutation.isPending}
          >
            {completeLabel || 'Complete work order'}
          </Button>
        </form>
      )}

      <TouchConfirmSheet
        isOpen={confirmingComplete}
        onClose={() => setConfirmingComplete(false)}
        onConfirm={() => completeMutation.mutate()}
        title="Complete this work order?"
        confirmLabel={completeLabel || 'Yes, complete it'}
        variant="primary"
        pending={completeMutation.isPending}
      >
        <p>
          Closes{' '}
          <span className="font-mono tabular-nums font-medium text-primary">{wo.mwo_number}</span>
          {wo.maintainable?.name ? ` on ${wo.maintainable.name}` : ''}.
        </p>
        <p>
          Booking{' '}
          <span className="font-mono tabular-nums font-medium text-primary">
            {downtimeMinutes || '0'}
          </span>{' '}
          minutes of downtime against OEE.
        </p>
        {remarks.trim().length === 0 && (
          <p className="text-muted">No work performed was recorded.</p>
        )}
      </TouchConfirmSheet>

      {/* Activity log */}
      {wo.logs && wo.logs.length > 0 && (
        <div className="rounded-md border border-default bg-canvas p-4">
          <h2 className="text-base font-medium mb-3">Activity log</h2>
          <div className="space-y-2">
            {wo.logs.map((log) => (
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
        title="Add spare part"
      >
        <div className="space-y-4">
          {/* Item search */}
          {!selectedItem ? (
            <>
              <Input
                id="part_search"
                label="Search spare parts"
                fieldSize="lg"
                type="text"
                value={partSearch}
                onChange={(e) => setPartSearch(e.target.value)}
                placeholder="Search items…"
                autoFocus
              />

              {itemsData?.data && itemsData.data.length > 0 && (
                <div className="space-y-1 max-h-[40vh] overflow-y-auto">
                  {itemsData.data.map((item: Item) => (
                    <button
                      key={item.id}
                      type="button"
                      onClick={() => setSelectedItem(item)}
                      className={cn(
                        'w-full text-left p-3 rounded-md hover:bg-surface active:bg-elevated min-h-[44px] cursor-pointer',
                        focusRingInset,
                      )}
                    >
                      <div className="text-sm font-medium">{item.name}</div>
                      <div className="text-xs text-muted">
                        {item.code} &middot; {item.unit_of_measure}
                      </div>
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
                <Button
                  type="button"
                  variant="ghost"
                  size="lg"
                  iconOnly
                  icon={<Trash2 className="w-4 h-4" />}
                  aria-label="Clear selected part"
                  onClick={() => {
                    setSelectedItem(null);
                    setPartLocationId('');
                    setPartQty('');
                  }}
                  className="text-muted hover:text-danger-fg"
                />
              </div>

              {/* Location picker */}
              <Select
                id="part_location"
                label="Source location"
                fieldSize="lg"
                value={partLocationId}
                onChange={(e) => setPartLocationId(e.target.value)}
              >
                <option value="">Select location…</option>
                {stockData?.data?.map(
                  (sl: {
                    id: string;
                    location: { id: string; code: string };
                    quantity_on_hand: string;
                  }) => (
                    <option key={sl.location.id} value={sl.location.id}>
                      {sl.location.code} (Qty: {sl.quantity_on_hand})
                    </option>
                  ),
                )}
              </Select>

              {/* Quantity */}
              <Input
                id="part_qty"
                label={`Quantity (${selectedItem.unit_of_measure})`}
                fieldSize="xl"
                type="number"
                inputMode="decimal"
                min="0"
                step="0.01"
                value={partQty}
                onChange={(e) => setPartQty(e.target.value)}
                placeholder="0"
                className="text-center font-mono tabular-nums"
              />

              {/* Submit */}
              <Button
                type="button"
                variant="primary"
                size="lg"
                className="w-full"
                disabled={!canAddPart}
                loading={sparePartMutation.isPending}
                onClick={() => {
                  if (!canAddPart) return;
                  sparePartMutation.mutate({
                    item_id: selectedItem.id,
                    location_id: partLocationId,
                    quantity: partQty,
                  });
                }}
              >
                {sparePartMutation.isPending ? 'Recording…' : 'Add part'}
              </Button>
            </>
          )}
        </div>
      </BottomSheet>
    </div>
  );
}
