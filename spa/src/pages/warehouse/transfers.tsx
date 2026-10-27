import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm, useWatch } from 'react-hook-form';
import { useNavigate } from 'react-router-dom';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { LuCircleCheck, LuPlus, LuCircleX } from '@/lib/icons';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { transferOrderApi } from '@/api/inventory/warehouseWms';
import { warehouseApi } from '@/api/inventory/warehouse';
import { itemsApi } from '@/api/inventory/items';
import { stockLevelsApi } from '@/api/inventory/stock';
import { DataTablePagination } from '@/components/ui/DataTablePagination';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { SearchableSelect } from '@/components/ui/SearchableSelect';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { PageShell } from '@/components/layout/PageShell';
import { usePermission } from '@/hooks/usePermission';
import { useAuthStore } from '@/stores/authStore';
import { formatDate } from '@/lib/formatDate';
import { formatQuantity } from '@/lib/formatNumber';
import { numberInputProps } from '@/lib/numberInput';
import { onFormInvalid } from '@/lib/formErrors';
import { focusRingInset } from '@/lib/focus';
import { showActionToast } from '@/lib/actionToast';
import type { TransferOrder } from '@/types/warehouse';
import type { WarehouseLocation } from '@/types/inventory';

const statusVariant: Record<string, 'warning' | 'success' | 'info' | 'danger' | 'neutral'> = {
  pending: 'warning',
  transferred: 'success',
  cancelled: 'danger',
};

const createSchema = z.object({
  from_location_id: z.string().min(1, 'Source location required'),
  to_location_id: z.string().min(1, 'Destination location required'),
  item_id: z.string().min(1, 'Item required'),
  quantity: z
    .string()
    .regex(/^\d+(\.\d{1,3})?$/, 'Use up to 3 decimal places.')
    .refine((value) => Number(value) > 0, 'Quantity must be greater than zero.'),
  reason: z.string().max(200).optional().or(z.literal('')),
});

async function listItemStock(itemId: string) {
  const firstPage = await stockLevelsApi.list({ item_id: itemId, page: 1, per_page: 100 });
  if (firstPage.meta.last_page <= 1) return firstPage.data;
  const remainingPages = await Promise.all(
    Array.from({ length: firstPage.meta.last_page - 1 }, (_, index) =>
      stockLevelsApi.list({ item_id: itemId, page: index + 2, per_page: 100 }),
    ),
  );
  return [...firstPage.data, ...remainingPages.flatMap((page) => page.data)];
}

export default function TransferOrdersPage() {
  const qc = useQueryClient();
  const { can } = usePermission();
  const currentUserId = useAuthStore((state) => state.user?.id);
  const navigate = useNavigate();
  const canManage = can('inventory.adjust');

  const [itemSearch, setItemSearch] = useState('');
  const [selectedItemLabel, setSelectedItemLabel] = useState('');
  const [selectedItemUnit, setSelectedItemUnit] = useState('');
  const [activeId, setActiveId] = useState<string | null>(null);
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [executeTarget, setExecuteTarget] = useState<TransferOrder | null>(null);
  const [cancelTarget, setCancelTarget] = useState<TransferOrder | null>(null);

  const [transferPage, setTransferPage] = useState(1);
  const {
    data: transferResponse,
    isLoading,
    isError,
    refetch,
  } = useQuery({
    queryKey: ['inventory', 'transfer-orders', transferPage],
    queryFn: () => transferOrderApi.page(transferPage),
  });

  const transfers = transferResponse?.data;
  const detailQuery = useQuery({
    queryKey: ['inventory', 'transfer-order', activeId],
    queryFn: () => transferOrderApi.get(activeId!),
    enabled: !!activeId,
  });
  const { data: activeTransfer, refetch: refetchDetail } = detailQuery;

  const locationsQuery = useQuery({
    queryKey: ['inventory', 'warehouse', 'tree'],
    queryFn: () => warehouseApi.tree(),
  });
  const locations = locationsQuery.data;

  const activeLocations: WarehouseLocation[] = useMemo(
    () =>
      (locations ?? [])
        .filter((w) => w.is_active)
        .flatMap((w) =>
          (w.zones ?? []).flatMap((z) =>
            (z.locations ?? []).filter((location) => location.is_active),
          ),
        ),
    [locations],
  );
  const activeLocationById = useMemo(
    () => new Map(activeLocations.map((location) => [location.id, location])),
    [activeLocations],
  );

  const inv = () => {
    qc.invalidateQueries({ queryKey: ['inventory', 'transfer-orders'] });
  };

  const createForm = useForm<z.infer<typeof createSchema>>({
    resolver: zodResolver(createSchema),
  });
  const selectedItemId = useWatch({ control: createForm.control, name: 'item_id' }) ?? '';
  const sourceLocationId =
    useWatch({ control: createForm.control, name: 'from_location_id' }) ?? '';
  const destinationLocationId =
    useWatch({ control: createForm.control, name: 'to_location_id' }) ?? '';
  const {
    data: itemOptions = [],
    isLoading: loadingItems,
    isError: itemOptionsError,
    refetch: retryItems,
  } = useQuery({
    queryKey: ['inventory', 'transfer-orders', 'active-items', itemSearch],
    queryFn: () =>
      itemsApi
        .list({ per_page: 50, is_active: true, search: itemSearch || undefined })
        .then((response) => response.data),
  });
  const sourceStockQuery = useQuery({
    queryKey: ['inventory', 'transfer-orders', 'source-stock', selectedItemId],
    queryFn: () => listItemStock(selectedItemId),
    enabled: !!selectedItemId && showCreateModal,
  });
  const sourceOptions = (sourceStockQuery.data ?? []).flatMap((level) => {
    const location = level.location ? activeLocationById.get(level.location.id) : undefined;
    return location && Number(level.available) > 0
      ? [{ location, available: level.available }]
      : [];
  });
  const destinationOptions = activeLocations.filter(
    (location) => !location.is_blocked && location.id !== sourceLocationId,
  );
  const selectedSource = sourceOptions.find(({ location }) => location.id === sourceLocationId);
  const transferQuantity = Number(createForm.watch('quantity') || 0);
  const sourceQuantityError =
    !!selectedSource && transferQuantity > Number(selectedSource.available);

  useEffect(() => {
    if (
      sourceStockQuery.data &&
      sourceLocationId &&
      !sourceOptions.some(({ location }) => location.id === sourceLocationId)
    ) {
      createForm.setValue('from_location_id', '', { shouldValidate: true });
    }
    if (
      destinationLocationId &&
      (destinationLocationId === sourceLocationId ||
        !destinationOptions.some((location) => location.id === destinationLocationId))
    ) {
      createForm.setValue('to_location_id', '', { shouldValidate: true });
    }
  }, [
    sourceStockQuery.data,
    sourceLocationId,
    destinationLocationId,
    sourceOptions,
    destinationOptions,
    createForm,
  ]);

  const createTransfer = useMutation({
    mutationFn: (d: z.infer<typeof createSchema>) => transferOrderApi.create(d),
    onSuccess: (t) => {
      toast.success('Transfer order created.');
      setShowCreateModal(false);
      inv();
      setActiveId(t.id);
      createForm.reset();
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to create.'),
  });

  const executeTransfer = useMutation({
    mutationFn: (id: string) => transferOrderApi.execute(id),
    onSuccess: () => {
      showActionToast({
        message: 'Transfer executed.',
        actionLabel: 'View movements',
        onAction: () => navigate('/inventory/stock-levels?view=movements&type=transfer'),
      });
      refetch();
      refetchDetail();
      inv();
      setExecuteTarget(null);
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to execute.'),
  });

  const cancelTransfer = useMutation({
    mutationFn: (id: string) => transferOrderApi.cancel(id),
    onSuccess: () => {
      toast.success('Transfer cancelled.');
      refetch();
      refetchDetail();
      inv();
      setCancelTarget(null);
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to cancel.'),
  });

  return (
    <PageShell viewport className="gap-3">
      <PageHeader
        inset={false}
        title="Transfer Orders"
        subtitle={transfers ? `${transfers.length} transfers` : undefined}
        actions={
          canManage ? (
            <Button
              variant="primary"
              size="sm"
              icon={<LuPlus size={14} />}
              onClick={() => setShowCreateModal(true)}
            >
              New transfer
            </Button>
          ) : null
        }
      />

      <div className="min-h-0 flex-1">
        {isLoading && !transfers && (
          <div className="h-full min-h-0 overflow-auto">
            <SkeletonTable rows={4} columns={4} />
          </div>
        )}
        {isError && (
          <div className="flex h-full min-h-0 items-center justify-center">
            <EmptyState
              icon="alert-circle"
              title="Failed to load transfer orders"
              action={<Button onClick={() => refetch()}>Retry</Button>}
            />
          </div>
        )}
        {transfers && transfers.length === 0 && (
          <div className="flex h-full min-h-0 items-center justify-center">
            <EmptyState
              icon="inbox"
              title="No transfer orders"
              description="Create a stock transfer between locations."
            />
          </div>
        )}
        {transfers && transfers.length > 0 && (
          <div className="grid h-full min-h-0 grid-cols-1 gap-3 xl:grid-cols-12">
            <Panel
              title="Transfers"
              meta={`${transfers.length} shown`}
              noPadding
              className="hidden min-h-0 xl:col-span-3 xl:flex xl:flex-col"
              bodyClassName="min-h-0 flex-1"
            >
              <div
                role="region"
                aria-label="Transfer orders"
                tabIndex={0}
                className="table-scroll-container h-full min-h-0 overflow-y-auto p-2"
              >
                <div className="space-y-1.5">
                  {transfers.map((transfer) => (
                    <button
                      key={transfer.id}
                      type="button"
                      onClick={() => setActiveId(transfer.id)}
                      aria-pressed={activeId === transfer.id}
                      className={`w-full rounded-md border px-3 py-2 text-left text-sm transition-colors cursor-pointer ${focusRingInset} ${
                        activeId === transfer.id
                          ? 'bg-accent/10 text-accent border-accent/30 shadow-sm'
                          : 'bg-surface border-default text-secondary hover:border-subtle hover:text-primary hover:bg-elevated'
                      }`}
                    >
                      <div className="truncate font-mono text-label font-medium text-link" title={transfer.transfer_number}>
                        {transfer.transfer_number}
                      </div>
                      <div
                        className="truncate text-caption text-muted"
                        title={`${transfer.from_location?.full_code ?? '?'} to ${transfer.to_location?.full_code ?? '?'}`}
                      >
                        {transfer.from_location?.full_code ?? '?'} to {transfer.to_location?.full_code ?? '?'}
                      </div>
                      <div className="mt-1 flex flex-wrap items-center gap-1.5">
                        <Chip variant={statusVariant[transfer.status] || 'neutral'}>
                          {transfer.status_label ?? transfer.status}
                        </Chip>
                        <span className="text-caption text-muted tabular-nums">{formatDate(transfer.created_at)}</span>
                      </div>
                    </button>
                  ))}
                </div>
              </div>
            </Panel>

            <div className="min-h-0 flex flex-col xl:col-span-9">
              <div className="mb-3 xl:hidden">
                <Select
                  searchable
                  label="Transfer order"
                  value={activeId ?? ''}
                  onChange={(event) => setActiveId(event.target.value || null)}
                >
                  <option value="">Select transfer…</option>
                  {transfers.map((transfer) => (
                    <option key={transfer.id} value={transfer.id}>
                      {transfer.transfer_number} — {transfer.from_location?.full_code ?? '?'} to{' '}
                      {transfer.to_location?.full_code ?? '?'} — {transfer.status_label ?? transfer.status}
                    </option>
                  ))}
                </Select>
              </div>
              <div
                role="region"
                aria-label="Transfer details"
                tabIndex={0}
                className="table-scroll-container min-h-0 flex-1 overflow-y-auto"
              >
                {detailQuery.isError && (
                  <div role="alert" className="mb-3 text-sm text-danger-fg">
                    Transfer details could not be loaded.{' '}
                    <Button
                      type="button"
                      variant="secondary"
                      size="sm"
                      onClick={() => void refetchDetail()}
                    >
                      Retry transfer
                    </Button>
                  </div>
                )}
                {detailQuery.isFetching && !activeTransfer && <SkeletonTable rows={4} columns={4} />}
                {activeTransfer ? (
                  <Panel
                    title={(
                      <span
                        className="block truncate"
                        title={`${activeTransfer.transfer_number} — ${activeTransfer.item?.code ?? '—'}`}
                      >
                        {activeTransfer.transfer_number} — {activeTransfer.item?.code ?? '—'}
                      </span>
                    )}
                    meta={<span className="block truncate" title={activeTransfer.item?.name ?? ''}>{activeTransfer.item?.name ?? ''}</span>}
                  >
                    <div className="space-y-4">
                      {activeTransfer.status === 'pending' && canManage && (
                        <div className="flex flex-wrap justify-end gap-1.5">
                          {activeTransfer.created_by?.id === currentUserId ? (
                            <p role="status" className="text-caption text-muted self-center">
                              Another warehouse user must execute a transfer you created.
                            </p>
                          ) : (
                            <Button
                              size="sm"
                              variant="primary"
                              disabled={
                                detailQuery.isFetching ||
                                detailQuery.isError ||
                                executeTransfer.isPending ||
                                cancelTransfer.isPending
                              }
                              icon={<LuCircleCheck size={14} />}
                              onClick={() => setExecuteTarget(activeTransfer)}
                            >
                              Execute
                            </Button>
                          )}
                          <Button
                            size="sm"
                            variant="danger"
                            disabled={
                              detailQuery.isFetching ||
                              detailQuery.isError ||
                              executeTransfer.isPending ||
                              cancelTransfer.isPending
                            }
                            icon={<LuCircleX size={14} />}
                            onClick={() => setCancelTarget(activeTransfer)}
                          >
                            Cancel
                          </Button>
                        </div>
                      )}
                      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div className="min-w-0">
                          <div className="mb-1 text-label font-medium text-muted">From</div>
                          <div className="truncate font-mono text-sm" title={activeTransfer.from_location?.full_code ?? '—'}>
                            {activeTransfer.from_location?.full_code ?? '—'}
                          </div>
                        </div>
                        <div className="min-w-0">
                          <div className="mb-1 text-label font-medium text-muted">To</div>
                          <div className="truncate font-mono text-sm" title={activeTransfer.to_location?.full_code ?? '—'}>
                            {activeTransfer.to_location?.full_code ?? '—'}
                          </div>
                        </div>
                      </div>
                      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div className="min-w-0">
                          <div className="mb-1 text-label font-medium text-muted">Item</div>
                          <div className="break-words text-sm font-medium text-primary sm:truncate" title={`${activeTransfer.item?.code ?? '—'} — ${activeTransfer.item?.name ?? '—'}`}>
                            {activeTransfer.item?.code ?? '—'} — {activeTransfer.item?.name ?? '—'}
                          </div>
                        </div>
                        <div>
                          <div className="mb-1 text-label font-medium text-muted">Quantity</div>
                          <div className="tabular-nums text-sm font-medium">
                            {formatQuantity(activeTransfer.quantity)} {activeTransfer.item?.unit_of_measure ?? ''}
                          </div>
                        </div>
                        <div>
                          <div className="mb-1 text-label font-medium text-muted">Status</div>
                          <Chip variant={statusVariant[activeTransfer.status] || 'neutral'}>
                            {activeTransfer.status_label ?? activeTransfer.status}
                          </Chip>
                        </div>
                      </div>
                      {activeTransfer.reason && (
                        <div className="break-words text-sm text-muted">Reason: {activeTransfer.reason}</div>
                      )}
                      {activeTransfer.transferred_at && (
                        <div className="text-caption text-muted">
                          Transferred by {activeTransfer.transferred_by?.name ?? '?'} on{' '}
                          {formatDate(activeTransfer.transferred_at)}
                        </div>
                      )}
                    </div>
                  </Panel>
                ) : (
                  <div className="flex min-h-full items-center justify-center text-center text-sm text-muted">
                    Select a transfer to view details.
                  </div>
                )}
              </div>
            </div>
          </div>
        )}
      </div>
      {transferResponse?.meta && (
        <DataTablePagination meta={transferResponse.meta} onPageChange={setTransferPage} />
      )}

      {/* Create modal */}
      <Modal
        isOpen={showCreateModal}
        onClose={() => {
          setShowCreateModal(false);
          createForm.reset();
        }}
        title="New transfer order"
        size="sm"
      >
        <form
          onSubmit={createForm.handleSubmit((d) => {
            const source = sourceOptions.find(({ location }) => location.id === d.from_location_id);
            const destination = destinationOptions.find(
              (location) => location.id === d.to_location_id,
            );
            if (
              locationsQuery.isError ||
              locationsQuery.isFetching ||
              sourceStockQuery.isError ||
              sourceStockQuery.isFetching ||
              itemOptionsError ||
              loadingItems ||
              !source ||
              !destination ||
              d.from_location_id === d.to_location_id ||
              Number(d.quantity) > Number(source.available)
            ) {
              toast.error(
                'Refresh stock options and choose a valid source, destination, and available quantity.',
              );
              return;
            }
            createTransfer.mutate(d);
          }, onFormInvalid())}
          className="py-3 space-y-3"
        >
          {locationsQuery.isError && (
            <div role="alert" className="text-sm text-danger-fg">
              Warehouse locations could not be loaded.{' '}
              <Button
                type="button"
                variant="secondary"
                size="sm"
                onClick={() => void locationsQuery.refetch()}
              >
                Retry locations
              </Button>
            </div>
          )}
          <SearchableSelect
            label="Item"
            required
            value={selectedItemId}
            selectedLabel={selectedItemLabel}
            loading={loadingItems}
            disabled={itemOptionsError}
            onSearch={setItemSearch}
            options={itemOptions.map((item) => ({
              value: item.id,
              label: `${item.code} — ${item.name}`,
            }))}
            onChange={(value) => {
              const selected = itemOptions.find((item) => item.id === value);
              setSelectedItemLabel(selected ? `${selected.code} — ${selected.name}` : '');
              setSelectedItemUnit(selected?.unit_of_measure ?? '');
              createForm.setValue('item_id', value, { shouldValidate: true });
              createForm.setValue('from_location_id', '');
              createForm.setValue('to_location_id', '');
            }}
          />
          {itemOptionsError && (
            <p role="alert" className="text-sm text-danger-fg">
              Items could not be loaded.{' '}
              <Button type="button" variant="secondary" size="sm" onClick={() => void retryItems()}>
                Retry
              </Button>
            </p>
          )}
          <Select
            label="From location"
            required
            disabled={!selectedItemId || sourceStockQuery.isFetching || sourceStockQuery.isError}
            {...createForm.register('from_location_id', {
              onChange: (event) => {
                if (event.target.value === destinationLocationId)
                  createForm.setValue('to_location_id', '', { shouldValidate: true });
              },
            })}
          >
            <option value="">Select source…</option>
            {sourceOptions.map(({ location, available }) => (
              <option key={location.id} value={location.id}>
                {location.full_code || location.code} — {formatQuantity(available)} {selectedItemUnit || 'units'}{' '}
                available{location.is_blocked ? ' (blocked; outbound only)' : ''}
              </option>
            ))}
          </Select>
          {sourceStockQuery.isError && (
            <p role="alert" className="text-xs text-danger-fg">
              Stock options failed to load.{' '}
              <Button
                type="button"
                variant="secondary"
                size="sm"
                onClick={() => void sourceStockQuery.refetch()}
              >
                Retry
              </Button>
            </p>
          )}
          {sourceStockQuery.data && sourceOptions.length === 0 && (
            <p className="text-sm text-muted">
              No active locations have available stock for this item.
            </p>
          )}
          <Select
            label="To location"
            required
            disabled={!selectedItemId || !sourceLocationId}
            {...createForm.register('to_location_id')}
          >
            <option value="">Select destination…</option>
            {destinationOptions.map((l) => (
              <option key={l.id} value={l.id}>
                {l.full_code || l.code}
                {l.zone?.zone_type === 'quarantine'
                  ? ' — Quarantine destination'
                  : l.zone?.zone_type === 'scrap'
                    ? ' — Scrap destination'
                    : ''}
              </option>
            ))}
          </Select>
          <Input
            label="Quantity"
            required
            max={selectedSource?.available}
            {...createForm.register('quantity')}
            {...numberInputProps()}
            step="0.001"
            error={createForm.formState.errors.quantity?.message}
            className="tabular-nums"
          />
          {selectedSource && (
            <p className="text-sm text-muted">
              Available at source: {formatQuantity(selectedSource.available)} {selectedItemUnit || 'units'}.
              {sourceQuantityError ? ' Reduce quantity or choose another source.' : ''}
            </p>
          )}
          <Input label="Reason (optional)" maxLength={200} {...createForm.register('reason')} />
          <div className="flex justify-end gap-2 pt-2">
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                setShowCreateModal(false);
                createForm.reset();
              }}
              disabled={createTransfer.isPending}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              variant="primary"
              disabled={
                createTransfer.isPending ||
                locationsQuery.isError ||
                locationsQuery.isFetching ||
                loadingItems ||
                itemOptionsError ||
                sourceStockQuery.isFetching ||
                sourceStockQuery.isError ||
                !selectedSource ||
                !destinationLocationId ||
                sourceQuantityError
              }
              loading={createTransfer.isPending}
            >
              Create transfer order
            </Button>
          </div>
        </form>
      </Modal>

      {/* Execute confirm */}
      <ConfirmDialog
        isOpen={!!executeTarget}
        onClose={() => setExecuteTarget(null)}
        onConfirm={() => executeTransfer.mutate(executeTarget!.id)}
        title="Execute transfer?"
        description={
          executeTarget ? (
            <>
              Move{' '}
              <span className="font-mono font-medium">
                {formatQuantity(executeTarget.quantity)} {executeTarget.item?.unit_of_measure ?? ''}
              </span>{' '}
              from <span className="font-mono">{executeTarget.from_location?.full_code}</span> to{' '}
              <span className="font-mono">{executeTarget.to_location?.full_code}</span>. A stock
              movement will be recorded.
            </>
          ) : null
        }
        confirmLabel="Execute"
        variant="primary"
        disabled={
          detailQuery.isError ||
          detailQuery.isFetching ||
          !executeTarget ||
          activeTransfer?.id !== executeTarget.id ||
          activeTransfer.status !== 'pending'
        }
        pending={executeTransfer.isPending}
      />

      {/* Cancel confirm */}
      <ConfirmDialog
        isOpen={!!cancelTarget}
        onClose={() => setCancelTarget(null)}
        onConfirm={() => cancelTransfer.mutate(cancelTarget!.id)}
        title="Cancel transfer?"
        description="This will cancel the transfer order. No stock movement will be recorded."
        confirmLabel="Cancel transfer"
        variant="danger"
        disabled={
          detailQuery.isError ||
          detailQuery.isFetching ||
          !cancelTarget ||
          activeTransfer?.id !== cancelTarget.id ||
          activeTransfer.status !== 'pending'
        }
        pending={cancelTransfer.isPending}
      />
    </PageShell>
  );
}
