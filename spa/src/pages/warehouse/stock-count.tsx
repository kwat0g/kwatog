import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import { LuCircleCheck, LuPlay, LuPlus } from '@/lib/icons';
import toast from 'react-hot-toast';
import { stockCountApi } from '@/api/inventory/warehouseWms';
import { warehouseApi } from '@/api/inventory/warehouse';
import { DataTablePagination } from '@/components/ui/DataTablePagination';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { usePermission } from '@/hooks/usePermission';
import { useAuthStore } from '@/stores/authStore';
import { formatDate } from '@/lib/formatDate';
import { numberInputProps } from '@/lib/numberInput';
import { onFormInvalid } from '@/lib/formErrors';
import type { StockCountItem } from '@/types/warehouse';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { focusRingInset } from '@/lib/focus';

const statusVariant: Record<string, 'warning' | 'success' | 'info' | 'danger' | 'neutral'> = {
  draft: 'neutral',
  in_progress: 'warning',
  completed: 'success',
  cancelled: 'danger',
};

const itemStatusVariant: Record<string, 'warning' | 'success' | 'info' | 'neutral'> = {
  pending: 'neutral',
  counted: 'info',
  verified: 'warning',
  adjusted: 'success',
};

const wholeQuantityUnits = new Set(['pc', 'pcs', 'piece', 'pieces', 'ea', 'each', 'unit', 'units', 'set', 'sets']);

const sessionSchema = z.object({
  title: z.string().trim().min(2, 'Title is required.').max(200),
  scope: z.string().min(1, 'Scope is required.'),
  warehouse_id: z.string().optional(),
  zone_id: z.string().optional(),
});

const countSchema = z.object({
  counted_quantity: z.string().regex(/^\d+(\.\d{1,3})?$/, 'Valid number required'),
  lot_number: z.string().max(50).optional().or(z.literal('')),
  variance_allocations: z
    .array(z.object({ lot_number: z.string().nullable(), quantity: z.string() }))
    .optional(),
  notes: z.string().max(500).optional().or(z.literal('')),
});

/**
 * Stock-count workflow manager — rendered inside the Warehouse Map page's
 * Map | Stock Count toggle (2026-08-08 merge). Compact toolbar instead of a
 * PageHeader because it lives inside the merged page. Sessions, counting,
 * variance approval, and completion are unchanged.
 */
export function StockCountManager() {
  const qc = useQueryClient();
  const { can } = usePermission();
  const canManage = can('inventory.stock_count.manage');
  const actorId = useAuthStore((state) => state.user?.id);
  const [searchParams, setSearchParams] = useSearchParams();
  const linkedSessionId = searchParams.get('session_id');
  const linkedItemId = searchParams.get('count_item_id');

  const [activeSessionId, setActiveSessionId] = useState<string | null>(linkedSessionId);
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [confirmAction, setConfirmAction] = useState<string | null>(null);
  const [countModalItem, setCountModalItem] = useState<StockCountItem | null>(null);
  const [approveTarget, setApproveTarget] = useState<StockCountItem | null>(null);

  const [sessionPage, setSessionPage] = useState(1);
  const {
    data: sessionResponse,
    isLoading,
    isError,
    refetch,
  } = useQuery({
    queryKey: ['warehouse', 'stock-counts', sessionPage],
    queryFn: () => stockCountApi.page(sessionPage),
  });

  const sessions = sessionResponse?.data;
  const sessionQuery = useQuery({
    queryKey: ['warehouse', 'stock-count', activeSessionId],
    queryFn: () => stockCountApi.get(activeSessionId!),
    enabled: !!activeSessionId,
  });
  const { data: activeSession, refetch: refetchSession } = sessionQuery;
  const sessionUnavailable = sessionQuery.isError || sessionQuery.isFetching;

  const warehousesQuery = useQuery({
    queryKey: ['inventory', 'warehouse', 'tree'],
    queryFn: () => warehouseApi.tree(),
  });
  const optionsQuery = useQuery({
    queryKey: ['warehouse', 'stock-counts', 'options'],
    queryFn: () => stockCountApi.options(),
    staleTime: 300_000,
  });
  const warehouses = warehousesQuery.data;
  const stockCountOptions = optionsQuery.data;
  const lookups = [warehousesQuery, optionsQuery];
  const lookupFailed = lookups.some((query) => query.isError);
  const lookupPending = lookups.some((query) => query.isFetching);

  const inv = () => {
    qc.invalidateQueries({ queryKey: ['warehouse', 'stock-counts'] });
  };

  const refreshAfterRejectedAction = (error: AxiosError) => {
    const status = error.response?.status ?? 0;
    if (status < 400 || status >= 500) return false;
    void refetchSession();
    inv();
    return true;
  };

  // ── Create session ──
  const createForm = useForm<z.infer<typeof sessionSchema>>({
    resolver: zodResolver(sessionSchema),
    defaultValues: { scope: '' },
  });
  const watchScope = createForm.watch('scope');

  const createSession = useMutation({
    mutationFn: (d: z.infer<typeof sessionSchema>) => {
      const payload: { title: string; scope: string; warehouse_id?: string; zone_id?: string } = {
        title: d.title,
        scope: d.scope,
      };
      if (d.scope === 'warehouse' && d.warehouse_id) payload.warehouse_id = d.warehouse_id;
      if (d.scope === 'zone' && d.zone_id) payload.zone_id = d.zone_id;
      return stockCountApi.create(payload);
    },
    onSuccess: (s) => {
      toast.success('Count session created.');
      setShowCreateModal(false);
      inv();
      setActiveSessionId(s.id);
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to create session.'),
  });

  // ── Start session ──
  const startSession = useMutation({
    mutationFn: (id: string) => stockCountApi.start(id),
    onSuccess: () => {
      toast.success('Count session started.');
      refetchSession();
      inv();
      setConfirmAction(null);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to start.');
      if (refreshAfterRejectedAction(e)) setConfirmAction(null);
    },
  });

  // ── Record count ──
  const countForm = useForm<z.infer<typeof countSchema>>({ resolver: zodResolver(countSchema) });
  const wholeUnit = wholeQuantityUnits.has(countModalItem?.item?.unit_of_measure.trim().toLowerCase() ?? '');
  const resetCount = countForm.reset;
  useEffect(() => {
    if (linkedSessionId) setActiveSessionId(linkedSessionId);
  }, [linkedSessionId]);
  useEffect(() => {
    if (!linkedItemId || !activeSession || activeSession.id !== linkedSessionId) return;
    const item = activeSession.items?.find((line) => line.id === linkedItemId);
    if (
      item &&
      canManage &&
      activeSession.status === 'in_progress' &&
      ['pending', 'counted', 'verified'].includes(item.status)
    ) {
      resetCount({
        counted_quantity: item.counted_quantity ?? '',
        lot_number: item.lot_number ?? '',
        variance_allocations: item.variance_allocations ?? undefined,
        notes: item.notes ?? '',
      });
      setCountModalItem(item);
    } else {
      toast.error('This count line is unavailable or cannot be counted in the current session.');
    }
    setSearchParams(
      (params) => {
        params.delete('count_item_id');
        return params;
      },
      { replace: true },
    );
  }, [activeSession, canManage, linkedItemId, linkedSessionId, resetCount, setSearchParams]);
  const recordCount = useMutation({
    mutationFn: (d: z.infer<typeof countSchema>) =>
      stockCountApi.recordCount(countModalItem!.id, {
        ...d,
        variance_allocations: d.variance_allocations?.filter((line) => Number(line.quantity) > 0),
      }),
    onSuccess: () => {
      toast.success('Count recorded.');
      refetchSession();
      setCountModalItem(null);
      countForm.reset();
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to record count.');
      refreshAfterRejectedAction(e);
    },
  });

  // ── Approve variance ──
  const approveVariance = useMutation({
    mutationFn: (id: string) => stockCountApi.approveVariance(id),
    onSuccess: () => {
      toast.success('Variance approved.');
      refetchSession();
      setApproveTarget(null);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to approve.');
      if (refreshAfterRejectedAction(e)) setApproveTarget(null);
    },
  });

  // ── Complete session ──
  const completeSession = useMutation({
    mutationFn: (id: string) => stockCountApi.complete(id),
    onSuccess: () => {
      toast.success('Session completed! Adjustments created.');
      refetchSession();
      inv();
      setConfirmAction(null);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to complete session.');
      if (refreshAfterRejectedAction(e)) setConfirmAction(null);
    },
  });

  // ── Cancel session ──
  const cancelSession = useMutation({
    mutationFn: (id: string) => stockCountApi.cancel(id),
    onSuccess: () => {
      inv();
      refetchSession();
      setConfirmAction(null);
      toast.success('Session cancelled.');
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to cancel.');
      if (refreshAfterRejectedAction(e)) setConfirmAction(null);
    },
  });

  const items = activeSession?.items ?? [];

  const pendingItems = items.filter((i) => i.status === 'pending');
  const countedItems = items.filter((i) => i.status !== 'pending');
  const varianceItems = items.filter(
    (i) => i.counted_quantity !== null && Number(i.variance) !== 0,
  );
  const isSessionCreator = activeSession?.created_by?.id === actorId;
  const isLineActor =
    !!actorId &&
    items.some((item) => item.counted_by?.id === actorId || item.verified_by?.id === actorId);
  const cannotComplete = isSessionCreator || isLineActor;

  return (
    <div>
      {lookupFailed && (
        <div role="alert" className="mb-3 text-sm text-danger-fg">
          Stock count options could not be loaded. Approval controls require the current variance
          policy.
          <Button
            type="button"
            variant="secondary"
            size="sm"
            onClick={() => {
              void warehousesQuery.refetch();
              void optionsQuery.refetch();
            }}
          >
            Retry count options
          </Button>
        </div>
      )}
      {/* Compact toolbar — Stock Count lives inside the Warehouse Map page's
 Map | Stock Count toggle (2026-08-08 merge). */}
      <div className="flex items-center justify-between gap-3 pb-3">
        <div className="text-sm text-muted">
          {sessions
            ? `${sessions.length} ${sessions.length === 1 ? 'session' : 'sessions'}`
            : 'Count sessions'}
        </div>
        <div className="flex items-center gap-2">
          {canManage && (
            <Button
              variant="primary"
              size="xs"
              icon={<LuPlus size={14} />}
              onClick={() => setShowCreateModal(true)}
            >
              New session
            </Button>
          )}
        </div>
      </div>

      <div>
        {isLoading && !sessions && <SkeletonTable rows={4} columns={4} />}
        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load count sessions"
            action={<Button onClick={() => refetch()}>Retry</Button>}
          />
        )}
        {sessions && sessions.length === 0 && (
          <EmptyState
            icon="inbox"
            title="No count sessions"
            description="Create your first physical stock count session."
          />
        )}
        {((sessions && sessions.length > 0) || activeSessionId) && (
          <div className="grid grid-cols-1 md:grid-cols-12 gap-4">
            {/* Sessions list */}
            <div className="md:col-span-3 space-y-1">
              <div className="text-2xs uppercase tracking-wider text-muted font-medium px-1 mb-1">
                Sessions {(sessions?.length ?? 0) > 0 && `(${sessions?.length})`}
              </div>
              {sessionResponse?.meta && (
                <DataTablePagination meta={sessionResponse.meta} onPageChange={setSessionPage} />
              )}
              {(sessions ?? []).map((s) => (
                <button
                  key={s.id}
                  type="button"
                  onClick={() => setActiveSessionId(s.id)}
                  className={`w-full text-left px-2 py-1.5 text-xs rounded-md transition-colors cursor-pointer ${focusRingInset} ${
                    activeSessionId === s.id
                      ? 'bg-accent/10 text-accent border border-accent/30 shadow-sm'
                      : 'bg-surface border border-default text-secondary hover:border-subtle hover:text-primary hover:bg-elevated'
                  }`}
                >
                  <div className="font-mono">{s.session_number}</div>
                  <div className="truncate">{s.title}</div>
                  <div className="flex items-center gap-1 mt-0.5">
                    <Chip variant={statusVariant[s.status]}>{s.status_label ?? s.status}</Chip>
                    <span className="text-2xs text-muted">{formatDate(s.created_at)}</span>
                  </div>
                </button>
              ))}
            </div>

            {/* Session detail */}
            <div className="md:col-span-9 space-y-3">
              {sessionQuery.isError && (
                <div role="alert" className="text-sm text-danger-fg">
                  Count session could not be loaded.{' '}
                  <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    onClick={() => void refetchSession()}
                  >
                    Retry session
                  </Button>
                </div>
              )}
              {sessionQuery.isFetching && !activeSession && <SkeletonTable rows={4} columns={4} />}
              {activeSession ? (
                <>
                  {/* Session header */}
                  <Panel
                    title={`${activeSession.session_number} — ${activeSession.title}`}
                    actions={
                      canManage && activeSession.status === 'draft' ? (
                        <div className="flex gap-1">
                          <Button
                            size="sm"
                            variant="primary"
                            icon={<LuPlay size={12} />}
                            onClick={() => setConfirmAction('start')}
                            disabled={sessionUnavailable || items.length === 0}
                          >
                            Start count
                          </Button>
                          <Button
                            disabled={sessionUnavailable}
                            size="sm"
                            variant="danger"
                            onClick={() => setConfirmAction('cancel')}
                          >
                            Cancel
                          </Button>
                        </div>
                      ) : canManage && activeSession.status === 'in_progress' ? (
                        <div className="flex gap-1">
                          {!cannotComplete && (
                            <Button
                              disabled={sessionUnavailable}
                              size="sm"
                              variant="primary"
                              onClick={() => setConfirmAction('complete')}
                            >
                              Complete session
                            </Button>
                          )}
                          <Button
                            disabled={sessionUnavailable}
                            size="sm"
                            variant="danger"
                            onClick={() => setConfirmAction('cancel')}
                          >
                            Cancel
                          </Button>
                        </div>
                      ) : null
                    }
                  >
                    <div className="text-xs space-y-1">
                      {activeSession.status === 'draft' && canManage && items.length === 0 && (
                        <p className="text-warning-fg">
                          No stock items are in this scope. Cancel and choose a scope with stock.
                        </p>
                      )}
                      {activeSession.status === 'in_progress' && canManage && cannotComplete && (
                        <p className="text-warning-fg">
                          {isSessionCreator
                            ? 'A different user must complete this count.'
                            : 'A user who counted or approved a line cannot complete this count.'}
                        </p>
                      )}
                      <div className="flex gap-4">
                        <span className="text-muted">
                          Scope:{' '}
                          <span className="font-medium text-primary">
                            {activeSession.scope_label ?? activeSession.scope}
                          </span>
                        </span>
                        <span className="text-muted">
                          Status:{' '}
                          <Chip variant={statusVariant[activeSession.status] || 'neutral'}>
                            {activeSession.status_label ?? activeSession.status.replace(/_/g, ' ')}
                          </Chip>
                        </span>
                        {activeSession.warehouse && (
                          <span className="text-muted">
                            Warehouse:{' '}
                            <span className="font-medium">{activeSession.warehouse.name}</span>
                          </span>
                        )}
                        {activeSession.zone && (
                          <span className="text-muted">
                            Zone: <span className="font-medium">{activeSession.zone.name}</span>
                          </span>
                        )}
                      </div>
                    </div>
                  </Panel>

                  {/* Progress stats */}
                  <div className="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-2">
                    <StatBox label="Total lines" value={items.length.toString()} />
                    <StatBox
                      label="Counted lines"
                      value={countedItems.length.toString()}
                      icon={<LuCircleCheck size={14} />}
                    />
                    <StatBox label="Pending lines" value={pendingItems.length.toString()} />
                    <StatBox label="Variance lines" value={varianceItems.length.toString()} />
                    <StatBox
                      label="Completed"
                      value={
                        activeSession.completed_at ? formatDate(activeSession.completed_at) : '—'
                      }
                    />
                  </div>

                  {/* Count items table */}
                  {items.length > 0 && (
                    <Panel title={`Items (${items.length})`}>
                      <table className={tableCls}>
                        <thead>
                          <tr className={theadTrCls}>
                            <Th className="w-32">Location</Th>
                            <Th>Item</Th>
                            <Th align="right">System</Th>
                            <Th align="right">Counted</Th>
                            <Th align="right">Variance</Th>
                            <Th align="right">%</Th>
                            <Th>Status</Th>
                            <Th />
                          </tr>
                        </thead>
                        <tbody>
                          {items.map((item) => (
                            <tr key={item.id} className={trCls}>
                              <Td mono>{item.location?.full_code ?? '—'}</Td>
                              <Td>
                                {item.item ? (
                                  <>
                                    <span className="font-mono">{item.item.code}</span>{' '}
                                    <span className="text-muted">{item.item.name}</span>{' '}
                                    <span className="text-2xs text-muted">
                                      ({item.item.unit_of_measure})
                                    </span>
                                  </>
                                ) : (
                                  <span className="text-muted">—</span>
                                )}
                              </Td>
                              <Td align="right" mono>
                                {Number(item.system_quantity).toFixed(3)}
                              </Td>
                              <Td align="right" mono>
                                {item.counted_quantity !== null ? (
                                  Number(item.counted_quantity).toFixed(3)
                                ) : (
                                  <span className="text-muted">—</span>
                                )}
                              </Td>
                              <Td
                                align="right"
                                mono
                                className={
                                  Math.abs(Number(item.variance)) > 0.001 ? 'text-warning-fg' : ''
                                }
                              >
                                {item.counted_quantity !== null
                                  ? (Number(item.variance) > 0 ? '+' : '') +
                                    Number(item.variance).toFixed(3)
                                  : '—'}
                              </Td>
                              <Td
                                align="right"
                                mono
                                className={
                                  stockCountOptions?.variance_tolerance_pct != null &&
                                  Math.abs(Number(item.variance_percent)) >
                                    stockCountOptions.variance_tolerance_pct
                                    ? 'text-danger-fg'
                                    : ''
                                }
                              >
                                {item.counted_quantity !== null
                                  ? `${Number(item.variance_percent).toFixed(1)}%`
                                  : ''}
                              </Td>
                              <Td>
                                <Chip variant={itemStatusVariant[item.status]}>
                                  {item.status_label ?? item.status}
                                </Chip>
                              </Td>
                              <Td align="right" mono>
                                {activeSession.status === 'in_progress' &&
                                  ['pending', 'counted', 'verified'].includes(item.status) &&
                                  canManage && (
                                    <Button
                                      size="sm"
                                      variant="secondary"
                                      disabled={sessionUnavailable}
                                      onClick={() => {
                                        countForm.reset({
                                          counted_quantity: item.counted_quantity ?? '',
                                          lot_number: item.lot_number ?? '',
                                          variance_allocations:
                                            item.variance_allocations ?? undefined,
                                          notes: item.notes ?? '',
                                        });
                                        setCountModalItem(item);
                                      }}
                                    >
                                      {item.status === 'pending' ? 'Count' : 'Recount'}
                                    </Button>
                                  )}
                                {activeSession.status === 'in_progress' &&
                                  item.status === 'counted' &&
                                  stockCountOptions?.variance_tolerance_pct != null &&
                                  Math.abs(Number(item.variance_percent)) >
                                    stockCountOptions.variance_tolerance_pct &&
                                  canManage &&
                                  !isSessionCreator &&
                                  item.counted_by?.id !== actorId && (
                                    <Button
                                      disabled={sessionUnavailable}
                                      size="xs"
                                      variant="danger"
                                      onClick={() => setApproveTarget(item)}
                                    >
                                      Approve
                                    </Button>
                                  )}
                                {activeSession.status === 'in_progress' &&
                                  item.status === 'counted' &&
                                  stockCountOptions?.variance_tolerance_pct != null &&
                                  Math.abs(Number(item.variance_percent)) >
                                    stockCountOptions.variance_tolerance_pct &&
                                  canManage &&
                                  (isSessionCreator || item.counted_by?.id === actorId) && (
                                    <span className="text-2xs text-muted">
                                      A different user must approve.
                                    </span>
                                  )}
                                {item.status === 'counted' &&
                                  stockCountOptions?.variance_tolerance_pct != null &&
                                  Math.abs(Number(item.variance_percent)) <=
                                    stockCountOptions.variance_tolerance_pct && (
                                    <span className="text-2xs text-muted">Auto</span>
                                  )}
                              </Td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </Panel>
                  )}
                </>
              ) : (
                <div className="text-sm text-muted text-center py-5">
                  Select a session to view details.
                </div>
              )}
            </div>
          </div>
        )}
      </div>

      {/* Create modal */}
      <Modal
        isOpen={showCreateModal}
        onClose={() => setShowCreateModal(false)}
        title="New count session"
        size="sm"
      >
        <form
          onSubmit={createForm.handleSubmit((d) => {
            if (!lookupFailed && !lookupPending) createSession.mutate(d);
          }, onFormInvalid())}
          className="py-3 space-y-3"
        >
          <Input
            label="Title"
            required
            autoFocus
            maxLength={200}
            {...createForm.register('title')}
            error={createForm.formState.errors.title?.message}
          />
          <Select label="Scope" required {...createForm.register('scope')}>
            {(stockCountOptions?.scopes ?? []).map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
          {watchScope !== 'full' && (
            <Select label="Warehouse" required {...createForm.register('warehouse_id')}>
              <option value="">Select warehouse…</option>
              {(warehouses ?? []).map((w) => (
                <option key={w.id} value={w.id}>
                  {w.code} — {w.name}
                </option>
              ))}
            </Select>
          )}
          {watchScope === 'zone' && (
            <Select label="Zone" required {...createForm.register('zone_id')}>
              <option value="">Select zone…</option>
              {(warehouses ?? [])
                .flatMap((w) => w.zones ?? [])
                .map((z) => (
                  <option key={z.id} value={z.id}>
                    {z.code} — {z.name}
                  </option>
                ))}
            </Select>
          )}
          <div className="flex justify-end gap-2 pt-2">
            <Button
              type="button"
              variant="secondary"
              onClick={() => setShowCreateModal(false)}
              disabled={createSession.isPending}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              variant="primary"
              disabled={lookupFailed || lookupPending}
              loading={createSession.isPending}
            >
              Create session
            </Button>
          </div>
        </form>
      </Modal>

      {/* Count modal */}
      <Modal
        isOpen={!!countModalItem}
        onClose={() => setCountModalItem(null)}
        title={`Count — ${countModalItem?.location?.full_code ?? ''}`}
        size="sm"
      >
        {countModalItem && (
          <form
            noValidate
            onSubmit={countForm.handleSubmit((d) => {
              if (sessionUnavailable) return;
              if (wholeUnit && !Number.isInteger(Number(d.counted_quantity))) {
                countForm.setError('counted_quantity', { type: 'validate', message: 'Piece quantities must be whole numbers.' });
                return;
              }
              const fractionalAllocation = wholeUnit
                ? d.variance_allocations?.findIndex((line) => line.quantity !== '' && !Number.isInteger(Number(line.quantity))) ?? -1
                : -1;
              if (fractionalAllocation >= 0) {
                countForm.setError(`variance_allocations.${fractionalAllocation}.quantity` as `variance_allocations.${number}.quantity`, {
                  type: 'validate',
                  message: 'Piece quantities must be whole numbers.',
                });
                return;
              }
              recordCount.mutate(d);
            }, onFormInvalid())}
            className="py-3 space-y-3"
          >
            <div className="text-xs text-muted mb-1">
              Item:{' '}
              <span className="font-medium text-primary">{countModalItem.item?.code ?? '—'}</span>
              <br />
              System quantity:{' '}
              <span className="font-mono">
                {Number(countModalItem.system_quantity).toFixed(3)}
              </span>{' '}
              <span>{countModalItem.item?.unit_of_measure ?? '—'}</span>
            </div>
            <Input
              label="Counted quantity"
              required
              autoFocus
              {...countForm.register('counted_quantity')}
              {...numberInputProps()}
              step={wholeUnit ? 1 : 0.001}
              inputMode={wholeUnit ? 'numeric' : 'decimal'}
              suffix={countModalItem.item?.unit_of_measure ?? '—'}
              error={countForm.formState.errors.counted_quantity?.message}
              className="font-mono tabular-nums"
            />
            {Number(countForm.watch('counted_quantity')) !==
              Number(countModalItem.system_quantity) &&
              (countModalItem.source_lots?.length ?? 0) > 0 && (
                <div className="space-y-2">
                  <p className="text-sm font-medium">
                    Allocate count difference:{' '}
                    {Math.abs(
                      Number(countForm.watch('counted_quantity') || 0) -
                        Number(countModalItem.system_quantity),
                    ).toFixed(3)}{' '}
                    {countModalItem.item?.unit_of_measure}
                  </p>
                  <p className="text-xs text-muted">
                    Enter the quantity missing or extra for each affected lot. Total must match the
                    difference.
                  </p>
                  {countModalItem.source_lots!.map((lot, index) => (
                    <Input
                      key={lot.lot_number}
                      label={`Difference for lot ${lot.lot_number}`}
                      value={countForm.watch('variance_allocations')?.[index]?.quantity ?? ''}
                      inputMode={wholeUnit ? 'numeric' : 'decimal'}
                      step={wholeUnit ? 1 : 0.001}
                      placeholder="0.000"
                      error={countForm.formState.errors.variance_allocations?.[index]?.quantity?.message}
                      onChange={(event) => {
                        const current =
                          countForm.getValues('variance_allocations') ??
                          countModalItem.source_lots!.map((source) => ({
                            lot_number: source.lot_number,
                            quantity: '',
                          }));
                        current[index] = {
                          lot_number: lot.lot_number,
                          quantity: event.target.value,
                        };
                        countForm.setValue('variance_allocations', current, { shouldDirty: true, shouldValidate: true });
                      }}
                    />
                  ))}
                </div>
              )}
            <Input label="Notes (optional)" maxLength={500} {...countForm.register('notes')} />
            <div className="flex justify-end gap-2 pt-2">
              <Button
                type="button"
                variant="secondary"
                onClick={() => setCountModalItem(null)}
                disabled={recordCount.isPending}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                variant="primary"
                disabled={sessionUnavailable}
                loading={recordCount.isPending}
              >
                Record
              </Button>
            </div>
          </form>
        )}
      </Modal>

      {/* Approve variance */}
      <ConfirmDialog
        isOpen={!!approveTarget}
        onClose={() => setApproveTarget(null)}
        onConfirm={() => {
          if (!sessionUnavailable && approveTarget) approveVariance.mutate(approveTarget.id);
        }}
        title="Approve variance?"
        description={
          approveTarget ? (
            <>
              Variance of{' '}
              <span className="font-mono font-medium text-warning-fg">
                {Number(approveTarget.variance).toFixed(3)}
              </span>{' '}
              ({approveTarget.variance_percent}%) at{' '}
              <span className="font-mono">{approveTarget.location?.full_code}</span>. This will
              allow it through when counting completes.
            </>
          ) : null
        }
        confirmLabel="Approve"
        variant="warning"
        pending={approveVariance.isPending}
      />

      {/* Confirm dialogs for session actions */}
      <ConfirmDialog
        isOpen={confirmAction === 'start'}
        onClose={() => setConfirmAction(null)}
        onConfirm={() => {
          if (!sessionUnavailable && activeSession) startSession.mutate(activeSession.id);
        }}
        title="Start count session?"
        description="Locations in scope will be frozen for movements during counting."
        confirmLabel="Start counting"
        variant="primary"
        pending={startSession.isPending}
      />
      <ConfirmDialog
        isOpen={confirmAction === 'complete'}
        onClose={() => setConfirmAction(null)}
        onConfirm={() => {
          if (!sessionUnavailable && activeSession) completeSession.mutate(activeSession.id);
        }}
        title="Complete count session?"
        description={`Variances above ${stockCountOptions?.variance_tolerance_pct ?? 'the configured limit'}${stockCountOptions?.variance_tolerance_pct != null ? '%' : ''} must be approved first. Stock adjustments will be created automatically.`}
        confirmLabel="Complete"
        variant="primary"
        pending={completeSession.isPending}
      />
      <ConfirmDialog
        isOpen={confirmAction === 'cancel'}
        onClose={() => setConfirmAction(null)}
        onConfirm={() => {
          if (!sessionUnavailable && activeSession) cancelSession.mutate(activeSession.id);
        }}
        title="Cancel session?"
        description="All count data will be lost."
        confirmLabel="Cancel session"
        variant="danger"
        pending={cancelSession.isPending}
      />
    </div>
  );
}

function StatBox({ label, value, icon }: { label: string; value: string; icon?: React.ReactNode }) {
  return (
    <div className="bg-surface rounded-md border border-subtle px-3 py-2 text-xs">
      <div className="text-2xs text-muted uppercase tracking-wider">{label}</div>
      <div className="flex items-center gap-1 mt-0.5">
        {icon && <span className="text-success-fg">{icon}</span>}
        <span className="font-mono font-medium text-primary">{value}</span>
      </div>
    </div>
  );
}
