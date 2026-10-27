import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { AxiosError } from 'axios';
import { LuRefreshCw } from '@/lib/icons';
import toast from 'react-hot-toast';
import { stockMovementsApi } from '@/api/inventory/stock';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { formatDateTime } from '@/lib/formatDate';
import { formatPeso, formatQuantity, formatUnitCost } from '@/lib/formatNumber';
import { productionDateTime } from '@/components/production/scheduleDates';
import type { ListParams } from '@/types';
import type { StockMovement } from '@/types/inventory';
import { usePermission } from '@/hooks/usePermission';
import { useAuthStore } from '@/stores/authStore';

import { useUrlFilters } from '@/hooks/useUrlFilters';
const chip = (t: string): 'success' | 'info' | 'warning' | 'danger' | 'neutral' => {
  if (['grn_receipt', 'material_return', 'production_receipt', 'adjustment_in'].includes(t))
    return 'success';
  if (['material_issue', 'delivery'].includes(t)) return 'info';
  if (['adjustment_out', 'transfer', 'cycle_count'].includes(t)) return 'warning';
  if (['scrap', 'return_to_vendor'].includes(t)) return 'danger';
  return 'neutral';
};

interface StockMovementListParams extends ListParams {
  item_id?: string;
  movement_id?: string;
  movement_type?: string;
  type?: string;
  pending?: boolean | string;
  from?: string;
  to?: string;
  reference_type?: string;
  work_order_id?: string;
}

const DEFAULT_FILTERS: StockMovementListParams = {
  page: 1,
  per_page: 50,
};

const newMaterialReturnKey = (): string => {
  const token =
    typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
  return `material-return-${token}`;
};

interface PendingMaterialReturn {
  sourceId: string;
  quantity: string;
  expectedReturned: string;
  reason: string;
  idempotencyKey: string;
}

function readPendingMaterialReturn(storageKey: string): PendingMaterialReturn | null {
  try {
    const value = JSON.parse(
      localStorage.getItem(storageKey) ?? 'null',
    ) as Partial<PendingMaterialReturn> | null;
    if (
      !value ||
      typeof value.sourceId !== 'string' ||
      typeof value.quantity !== 'string' ||
      typeof value.expectedReturned !== 'string' ||
      typeof value.reason !== 'string' ||
      typeof value.idempotencyKey !== 'string' ||
      !/^material-return-[A-Za-z0-9-]{12,100}$/.test(value.idempotencyKey)
    )
      return null;
    return {
      sourceId: value.sourceId,
      quantity: value.quantity,
      expectedReturned: value.expectedReturned,
      reason: value.reason,
      idempotencyKey: value.idempotencyKey,
    };
  } catch {
    return null;
  }
}

export function StockMovementsTab({
  initialItemId,
  initialMovementType,
  initialMovementId,
}: {
  initialItemId?: string;
  initialMovementType?: string;
  initialMovementId?: string;
}) {
  const qc = useQueryClient();
  const { can } = usePermission();
  const userId = useAuthStore((state) => state.user?.id);
  const pendingStorageKey = `ogami:inventory:material-return:${userId ?? 'unknown'}`;
  const [pendingReturn, setPendingReturn] = useState<PendingMaterialReturn | null>(() =>
    readPendingMaterialReturn(pendingStorageKey),
  );
  const [filters, setFilters] = useUrlFilters<StockMovementListParams>({
    ...DEFAULT_FILTERS,
    item_id: initialItemId || undefined,
    movement_type: initialMovementType || undefined,
    movement_id: initialMovementId || undefined,
  });

  const { data, isLoading, isError, isFetching, refetch } = useQuery({
    queryKey: ['inventory', 'movements', filters],
    queryFn: () => stockMovementsApi.list(filters),
    placeholderData: (prev) => prev,
  });
  const { data: movementOptions } = useQuery({
    queryKey: ['inventory', 'movements', 'options'],
    queryFn: stockMovementsApi.options,
    staleTime: 5 * 60 * 1000,
  });
  const labels = new Map(
    (movementOptions?.movement_types ?? []).map((option) => [option.value, option.label]),
  );
  const [returnSource, setReturnSource] = useState<StockMovement | null>(null);
  const [returnQuantity, setReturnQuantity] = useState('');
  const [returnReason, setReturnReason] = useState('');
  const [returnQuantityError, setReturnQuantityError] = useState('');
  const [returnReasonError, setReturnReasonError] = useState('');
  const [returnSubmitError, setReturnSubmitError] = useState('');
  const returnSourceId = returnSource?.id;
  const returnOptionsQuery = useQuery({
    queryKey: ['inventory', 'material-return-options', returnSourceId],
    queryFn: () => stockMovementsApi.returnOptions(returnSourceId!),
    enabled: !!returnSourceId,
    staleTime: 0,
  });
  useEffect(() => {
    if (!returnSourceId) return;
    setReturnQuantity('');
    setReturnReason('');
    setReturnQuantityError('');
    setReturnReasonError('');
    setReturnSubmitError('');
    const saved = readPendingMaterialReturn(pendingStorageKey);
    if (saved) {
      setPendingReturn(saved);
      if (saved.sourceId === returnSourceId) {
        setReturnQuantity(saved.quantity);
        setReturnReason(saved.reason);
      }
    }
  }, [returnSourceId, pendingStorageKey]);
  useEffect(() => {
    const saved = readPendingMaterialReturn(pendingStorageKey);
    setPendingReturn(saved);
  }, [pendingStorageKey]);
  const rememberPendingReturn = (value: PendingMaterialReturn | null) => {
    setPendingReturn(value);
    try {
      if (value) localStorage.setItem(pendingStorageKey, JSON.stringify(value));
      else localStorage.removeItem(pendingStorageKey);
    } catch {
      /* An in-memory retry remains available if browser storage is disabled. */
    }
  };
  const retryGl = useMutation({
    mutationFn: (movementId: string) => stockMovementsApi.retryGlHandoff(movementId),
    onSuccess: (movement) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'movements'] });
      toast.success(
        movement.gl_handoff.status === 'generated'
          ? 'Journal entry posted.'
          : 'GL handoff still needs Accounting setup.',
      );
    },
    onError: (error: AxiosError<{ message?: string }>) => {
      toast.error(
        error.response?.data?.message ??
          'The stock movement could not be posted to the General Ledger.',
      );
    },
  });
  const returnMutation = useMutation({
    mutationFn: (request: PendingMaterialReturn) =>
      stockMovementsApi.returnUnused(
        request.sourceId,
        {
          quantity_returned: request.quantity,
          expected_returned_quantity: request.expectedReturned,
          reason: request.reason,
        },
        request.idempotencyKey,
      ),
    onSuccess: () => {
      rememberPendingReturn(null);
      qc.invalidateQueries({ queryKey: ['inventory', 'movements'] });
      qc.invalidateQueries({ queryKey: ['inventory', 'stock-levels'] });
      qc.invalidateQueries({ queryKey: ['production', 'work-orders'] });
      toast.success('Unused material returned to its source location.');
      setReturnSource(null);
    },
    onError: async (error: AxiosError<{ message?: string }>) => {
      if (error.response?.status === 409) {
        rememberPendingReturn(null);
        setReturnSubmitError(
          'A newer return changed this movement. The latest totals are refreshed below; review the returnable quantity before submitting again.',
        );
        qc.invalidateQueries({ queryKey: ['inventory', 'movements'] });
        await returnOptionsQuery.refetch();
        return;
      }
      if (error.response && error.response.status < 500) {
        rememberPendingReturn(null);
        setReturnSubmitError(
          error.response.data?.message ??
            'The return could not be posted. Check the entered details and try again.',
        );
        return;
      }
      setReturnSubmitError(
        'We could not confirm the return. Your request is saved with its original details. Retry the last return to safely recover without posting twice.',
      );
    },
  });

  const closeReturn = () => {
    if (returnMutation.isPending) return;
    setReturnSource(null);
    setReturnQuantity('');
    setReturnReason('');
    setReturnQuantityError('');
    setReturnReasonError('');
    setReturnSubmitError('');
  };

  const retryPendingReturn = () => {
    if (!pendingReturn || pendingReturn.sourceId !== returnSourceId || returnMutation.isPending)
      return;
    setReturnSubmitError('');
    returnMutation.mutate(pendingReturn);
  };

  const submitReturn = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const summary = returnOptionsQuery.data;
    if (!returnSource || !summary) return;
    if (pendingReturn?.sourceId === returnSource.id) {
      retryPendingReturn();
      return;
    }
    if (pendingReturn) {
      setReturnSubmitError(
        'Resolve the saved return for another issue first. Open that issue row and retry its original request.',
      );
      return;
    }

    const amount = Number(returnQuantity);
    const returnable = Number(summary.returnable_quantity);
    const quantityValid =
      /^\d+(?:\.\d{1,3})?$/.test(returnQuantity) &&
      Number.isFinite(amount) &&
      amount > 0 &&
      amount <= returnable + 0.0000001;
    const cleanReason = returnReason.trim();
    setReturnQuantityError(
      quantityValid ? '' : `Enter a quantity from 0.001 up to ${summary.returnable_quantity}.`,
    );
    setReturnReasonError(
      cleanReason.length >= 10 && cleanReason.length <= 500
        ? ''
        : 'Give a reason of 10 to 500 characters for the audit trail.',
    );
    if (!quantityValid || cleanReason.length < 10 || cleanReason.length > 500) return;

    setReturnSubmitError('');
    const pending: PendingMaterialReturn = {
      sourceId: returnSource.id,
      quantity: returnQuantity,
      expectedReturned: summary.returned_quantity,
      reason: cleanReason,
      idempotencyKey: newMaterialReturnKey(),
    };
    rememberPendingReturn(pending);
    returnMutation.mutate(pending);
  };

  const glChip = (movement: StockMovement) => {
    const handoff = movement.gl_handoff;
    if (!handoff) return <span className="text-muted">—</span>;
    const variant =
      handoff.status === 'generated' || handoff.status === 'not_required'
        ? 'success'
        : handoff.status === 'manual_required'
          ? 'warning'
          : 'neutral';
    return (
      <div className="flex items-center gap-2">
        <span title={handoff.message ?? undefined}>
          <Chip variant={variant}>{handoff.status_label ?? handoff.status.replace('_', ' ')}</Chip>
        </span>
        {can('accounting.journal.post') && handoff.status === 'manual_required' && (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            icon={<LuRefreshCw size={13} className={retryGl.isPending ? 'animate-spin' : ''} />}
            disabled={retryGl.isPending}
            onClick={() => retryGl.mutate(movement.id)}
          >
            Retry
          </Button>
        )}
      </div>
    );
  };

  const columns: Column<StockMovement>[] = [
    {
      key: 'created_at',
      header: 'When',
      className: 'min-w-44',
      cell: (r) => <span className="whitespace-nowrap tabular-nums">{formatDateTime(r.created_at)}</span>,
    },
    {
      key: 'type',
      header: 'Type',
      cell: (r) => (
        <Chip variant={chip(r.movement_type)}>
          {r.movement_type_label ??
            labels.get(r.movement_type) ??
            r.movement_type.replace(/_/g, ' ')}
        </Chip>
      ),
    },
    {
      key: 'item',
      header: 'Item',
      className: 'min-w-44',
      cell: (r) => (
        <div>
          <span className="font-mono">{r.item?.code}</span>
          <div className="text-xs text-muted">{r.item?.name}</div>
        </div>
      ),
    },
    {
      key: 'from',
      defaultHidden: true,
      header: 'From',
      cell: (r) => <span className="font-mono">{r.from_location?.code ?? '—'}</span>,
    },
    {
      key: 'to',
      defaultHidden: true,
      header: 'To',
      cell: (r) => <span className="font-mono">{r.to_location?.code ?? '—'}</span>,
    },
    {
      key: 'qty',
      header: 'Qty',
      className: 'min-w-24',
      align: 'right',
      cell: (r) => (
        <NumCell>
          {r.movement_type === 'delivery' ? '-' : ''}{formatQuantity(r.quantity)} {r.item?.unit_of_measure}
        </NumCell>
      ),
    },
    {
      key: 'cost',
      defaultHidden: true,
      header: 'Unit cost',
      align: 'right',
      cell: (r) => <NumCell>{formatUnitCost(r.unit_cost)}</NumCell>,
    },
    {
      key: 'total',
      defaultHidden: true,
      header: 'Total cost',
      align: 'right',
      cell: (r) => <NumCell className="font-medium">{formatPeso(r.total_cost)}</NumCell>,
    },
    { key: 'gl', header: 'GL', defaultHidden: true, cell: glChip },
    {
      key: 'ref',
      header: 'Reference',
      cell: (r) => {
        const label =
          r.reference_label ??
          (r.reference_type
            ? `${r.reference_type.replaceAll('_', ' ')}${r.reference_id ? ` #${r.reference_id}` : ''}`
            : '—');
        const canOpen = r.reference_url?.startsWith('/inventory/grn/')
          ? can('inventory.view')
          : r.reference_url?.startsWith('/supply-chain/deliveries/')
            ? can('supply_chain.view') || can('supply_chain.deliveries.view')
            : r.reference_url?.startsWith('/inventory/material-issues/')
              ? can('inventory.issue.create')
              : can('production.view') && can('production.work_orders.view');
        return (
          <div className="min-w-36 text-xs">
            {r.reference_url && canOpen ? (
              <Link className="font-mono text-link hover:underline" to={r.reference_url}>
                {label}
              </Link>
            ) : (
              <span className={r.reference_label ? 'font-mono' : 'capitalize'}>{label}</span>
            )}
            {r.reference_type === 'work_order_output' && (
              <p className="text-muted">Production output</p>
            )}
          </div>
        );
      },
    },
    {
      key: 'action',
      header: 'Warehouse status',
      cell: (r) =>
        r.movement_type === 'material_issue' ? (
          <Chip variant={r.handover_remaining_quantity != null && Number(r.handover_remaining_quantity) <= 0 ? 'neutral' : r.handover ? 'success' : 'warning'}>
            {r.handover_remaining_quantity != null && Number(r.handover_remaining_quantity) <= 0 ? 'Fully returned' : r.handover ? 'Handed over' : 'Pending handover'}
          </Chip>
        ) : (
          glChip(r)
        ),
    },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'movement_type',
      label: 'Type',
      type: 'select',
      options: [{ value: '', label: 'All' }, ...(movementOptions?.movement_types ?? [])],
    },
  ];

  return (
    <div className="min-h-0 flex flex-1 flex-col gap-3">
      {data && (
        <div className="text-xs text-muted">
          {data.meta.total} {data.meta.total === 1 ? 'movement' : 'movements'}
        </div>
      )}
      {pendingReturn && (
        <div
          role="status"
          className="rounded-md border border-warning px-3 py-2 text-sm text-warning-fg"
        >
          <p>
            A material return for issue <span className="font-mono">{pendingReturn.sourceId}</span>{' '}
            is awaiting confirmation. Retry the saved request to recover its result.
          </p>
          <Button
            type="button"
            variant="secondary"
            size="sm"
            className="mt-2"
            onClick={() =>
              setFilters({
                ...DEFAULT_FILTERS,
                per_page: filters.per_page,
                movement_id: pendingReturn.sourceId,
              })
            }
          >
            Open pending issue
          </Button>
        </div>
      )}
      <FilterBar
        inset={false}
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        searchPlaceholder="Search item, WO number, lot, or note…"
        dateRange={{ fromKey: 'from', toKey: 'to' }}
        onFilter={(k, v) => setFilters((f) => ({ ...f, [k]: v, page: 1 }))}
      />
      {isLoading && !data && <div className="min-h-0 flex-1 overflow-auto"><SkeletonTable columns={11} rows={10} /></div>}
      {isError && (
        <div className="min-h-0 flex-1 flex items-center justify-center">
          <EmptyState
            icon="alert-circle"
            title="Failed to load movements"
            action={<Button onClick={() => refetch()}>Retry</Button>}
          />
        </div>
      )}
      {data && data.data.length === 0 && (
        <div className="min-h-0 flex-1 flex items-center justify-center">
          <EmptyState
            icon="inbox"
            title={
              filters.search ||
              filters.movement_type ||
              filters.from ||
              filters.to ||
              filters.movement_id ||
              filters.work_order_id
                ? 'No matching movements'
                : 'No movements yet'
            }
            description="Check the filters or select a wider date range."
          />
        </div>
      )}
      {data && data.data.length > 0 && (
        <div className="min-h-0 flex-1 flex flex-col">
          <DataTable
            tableKey="stock-movements"
            fillHeight
            columns={columns}
            renderExpanded={(r) => (
              <div className="p-3 space-y-3 text-sm">
                <div className="flex flex-wrap gap-x-6 gap-y-2">
                  <span>From: {r.from_location?.code ?? '—'}</span>
                  <span>To: {r.to_location?.code ?? '—'}</span>
                  <span>Lot: {r.lot_number ?? 'Not recorded'}</span>
                  <span>Unit cost: {formatUnitCost(r.unit_cost)}</span>
                  <span>Total cost: {formatPeso(r.total_cost)}</span>
                  {glChip(r)}
                </div>
                {r.movement_type === 'material_issue' && (
                  <div className="flex flex-wrap items-center gap-3">
                    {r.handover && <div className="text-xs"><p>Received by {r.handover.recipient_name} · {productionDateTime(r.handover.handed_over_at)}</p><p className="text-muted">Quantity {r.handover.quantity} {r.item?.unit_of_measure} · Recorded by {r.handover.recorded_by ?? 'Warehouse'}</p></div>}
                    {can('inventory.picking.view') && (r.reference_work_order_id || (r.reference_type === 'material_issue_slip' && r.reference_id)) && <Link className="text-link underline" to={`/inventory/picking?${r.reference_work_order_id ? `work_order_id=${r.reference_work_order_id}` : `material_issue_id=${r.reference_id}`}`}>Review handover</Link>}
                    {can('inventory.issue.create') && (
                      <Button
                        variant="secondary"
                        size="sm"
                        disabled={isError || isFetching}
                        onClick={() => setReturnSource(r)}
                      >
                        Receive unused return
                      </Button>
                    )}
                  </div>
                )}
              </div>
            )}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
            onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
          />
        </div>
      )}
      <Modal
        isOpen={!!returnSource}
        onClose={closeReturn}
        closeOnOverlayClick={!returnMutation.isPending}
        title="Return unused material"
        size="md"
      >
        <form onSubmit={submitReturn} className="space-y-4 py-4" noValidate>
          {returnSource && (
            <p className="text-sm text-secondary">
              Return material for{' '}
              <span className="font-mono">
                {returnSource.reference_label ?? 'this material issue'}
              </span>{' '}
              · <span className="font-mono">{returnSource.item?.code ?? 'this item'}</span>
              {returnSource.item?.name ? ` · ${returnSource.item.name}` : ''}.
            </p>
          )}

          {returnOptionsQuery.isLoading && (
            <p role="status" className="text-sm text-muted">
              Loading issue balance…
            </p>
          )}
          {returnOptionsQuery.isError && (
            <div className="space-y-2" role="alert">
              <p className="text-sm text-danger-fg">
                Could not load this issue’s returnable balance.
              </p>
              <Button
                type="button"
                variant="secondary"
                onClick={() => void returnOptionsQuery.refetch()}
              >
                Retry
              </Button>
            </div>
          )}

          {returnOptionsQuery.data && (
            <>
              <dl className="grid grid-cols-1 gap-x-4 gap-y-2 border-y border-default py-3 text-sm sm:grid-cols-2">
                <div>
                  <dt className="text-xs text-muted">Originally issued</dt>
                  <dd className="font-mono tabular-nums">
                    {returnOptionsQuery.data.issued_quantity}{' '}
                    {returnOptionsQuery.data.unit_of_measure}
                  </dd>
                </div>
                <div>
                  <dt className="text-xs text-muted">Already returned</dt>
                  <dd className="font-mono tabular-nums">
                    {returnOptionsQuery.data.returned_quantity}{' '}
                    {returnOptionsQuery.data.unit_of_measure} ·{' '}
                    {returnOptionsQuery.data.returned_cost}
                  </dd>
                </div>
                <div>
                  <dt className="text-xs text-muted">Returnable now</dt>
                  <dd className="font-mono tabular-nums">
                    {returnOptionsQuery.data.returnable_quantity}{' '}
                    {returnOptionsQuery.data.unit_of_measure}
                  </dd>
                </div>
                <div>
                  <dt className="text-xs text-muted">Original unit cost</dt>
                  <dd className="font-mono tabular-nums">{returnOptionsQuery.data.unit_cost}</dd>
                </div>
                <div>
                  <dt className="text-xs text-muted">Source location</dt>
                  <dd className="font-mono break-words">
                    {returnOptionsQuery.data.location ?? returnSource?.from_location?.code ?? '—'}
                  </dd>
                </div>
                {returnOptionsQuery.data.lot_number && (
                  <div className="sm:col-span-2">
                    <dt className="text-xs text-muted">Lot</dt>
                    <dd className="font-mono break-all">{returnOptionsQuery.data.lot_number}</dd>
                  </div>
                )}
              </dl>

              {returnOptionsQuery.data.consumption_basis === 'saved_bom_norm' && (
                <p className="text-xs text-muted">
                  The recorded-production floor uses the saved BOM norm for cumulative good and
                  rejected output; it is a planned allowance, not measured physical usage.
                </p>
              )}
              {['fixed_saved_plan', 'no_recipe'].includes(
                returnOptionsQuery.data.consumption_basis ?? '',
              ) &&
                Number(returnOptionsQuery.data.gross_production_units ?? 0) > 0 && (
                  <p className="text-xs text-muted">
                    No recipe is available for recorded output, so the saved plan quantity stays as
                    a conservative material floor.
                  </p>
                )}
              {returnOptionsQuery.data.message && (
                <p
                  role="alert"
                  className="rounded-md border border-warning px-3 py-2 text-sm text-warning-fg"
                >
                  {returnOptionsQuery.data.message}
                </p>
              )}

              <Input
                label={`Quantity to return${returnSource?.item?.code ? ` (${returnSource.item.code})` : ''}`}
                type="number"
                min="0.001"
                max={returnOptionsQuery.data.returnable_quantity}
                step="0.001"
                fieldSize="lg"
                inputMode="decimal"
                value={returnQuantity}
                onChange={(event) => {
                  setReturnQuantity(event.target.value);
                  setReturnQuantityError('');
                  setReturnSubmitError('');
                }}
                helper={`Up to ${returnOptionsQuery.data.returnable_quantity} ${returnOptionsQuery.data.unit_of_measure ?? ''} may be returned to the original location.`}
                error={returnQuantityError}
                disabled={
                  !returnOptionsQuery.data.eligible ||
                  returnMutation.isPending ||
                  returnOptionsQuery.isFetching ||
                  !!pendingReturn
                }
              />
              <Select
                label="Return reason"
                value={returnReason}
                disabled={
                  !returnOptionsQuery.data.eligible || !!pendingReturn || returnMutation.isPending
                }
                onChange={(event) => {
                  setReturnReason(event.target.value);
                  setReturnReasonError('');
                  setReturnSubmitError('');
                }}
              >
                <option value="">Choose a reason or enter details below</option>
                <option value="Unused material physically returned after production.">
                  Production finished
                </option>
                <option value="Excess material physically returned to Warehouse.">
                  Excess issued material
                </option>
                <option value="Unopened material physically returned after production was stopped.">
                  Production stopped
                </option>
                {returnReason &&
                  ![
                    'Unused material physically returned after production.',
                    'Excess material physically returned to Warehouse.',
                    'Unopened material physically returned after production was stopped.',
                  ].includes(returnReason) && <option value={returnReason}>Custom reason</option>}
              </Select>
              <Textarea
                label="Reason details"
                rows={3}
                maxLength={500}
                required
                value={returnReason}
                onChange={(event) => {
                  setReturnReason(event.target.value);
                  setReturnReasonError('');
                  setReturnSubmitError('');
                }}
                className="text-base"
                placeholder="For example: unopened resin left after this production run"
                helper="10 to 500 characters; recorded with the stock return."
                error={returnReasonError}
                disabled={
                  !returnOptionsQuery.data.eligible ||
                  returnMutation.isPending ||
                  returnOptionsQuery.isFetching ||
                  !!pendingReturn
                }
              />
            </>
          )}

          {returnSubmitError && (
            <p role="alert" className="text-sm text-danger-fg">
              {returnSubmitError}
            </p>
          )}

          <ModalFooter>
            <Button
              type="button"
              variant="secondary"
              size="touch"
              onClick={closeReturn}
              disabled={returnMutation.isPending}
            >
              Close
            </Button>
            {pendingReturn?.sourceId === returnSourceId && (
              <Button
                type="button"
                variant="secondary"
                size="touch"
                onClick={retryPendingReturn}
                disabled={returnMutation.isPending || returnOptionsQuery.isFetching}
              >
                Retry last return
              </Button>
            )}
            <Button
              type="submit"
              variant="primary"
              size="touch"
              loading={returnMutation.isPending}
              disabled={
                !returnOptionsQuery.data?.eligible ||
                !returnQuantity ||
                !returnReason.trim() ||
                returnMutation.isPending ||
                returnOptionsQuery.isFetching ||
                !!pendingReturn
              }
            >
              Post return
            </Button>
          </ModalFooter>
        </form>
      </Modal>
    </div>
  );
}
