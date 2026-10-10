import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { workOrdersApi, type MaintenancePartOption } from '@/api/maintenance/workOrders';
import { client } from '@/api/client';
import { BottomSheet } from '@/components/ui/BottomSheet';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SearchableSelect } from '@/components/ui/SearchableSelect';
import { LuTrash2 } from '@/lib/icons';
import { createRequestKey } from '@/lib/requestKey';
import { reportMutationError } from '@/lib/formErrors';
import type { ApiSuccess } from '@/types';
import type { MaintenanceWorkOrderLog, SparePartUsage } from '@/types/maintenance';

export function SparePartSheet({
  workOrderId,
  isOpen,
  onClose,
  desktop,
  logs = [],
}: {
  workOrderId: string;
  isOpen: boolean;
  onClose: () => void;
  desktop: boolean;
  logs?: MaintenanceWorkOrderLog[];
}) {
  const queryClient = useQueryClient();
  const [partSearch, setPartSearch] = useState('');
  const [selectedItem, setSelectedItem] = useState<MaintenancePartOption | null>(null);
  const [partQty, setPartQty] = useState('');
  const [partOperationKey, setPartOperationKey] = useState(() => createRequestKey());
  const [partLocationId, setPartLocationId] = useState('');
  const structuredLogs = logs.filter((log) => log.finding && log.action_taken);
  const latestStructuredLog = structuredLogs.reduce<MaintenanceWorkOrderLog | null>(
    (latest, log) =>
      !latest ||
      (Date.parse(log.created_at ?? '') || 0) >= (Date.parse(latest.created_at ?? '') || 0)
        ? log
        : latest,
    null,
  );
  const [workRecordId, setWorkRecordId] = useState(() => latestStructuredLog?.id ?? '');

  useEffect(() => {
    if (isOpen) setWorkRecordId(latestStructuredLog?.id ?? '');
  }, [isOpen, latestStructuredLog?.id]);

  const {
    data: itemsData,
    isError: partsError,
    isFetching: partsFetching,
    refetch: retryParts,
  } = useQuery({
    queryKey: ['maintenance', 'spare_parts', workOrderId, partSearch],
    queryFn: () => workOrdersApi.spareParts(partSearch, workOrderId),
    enabled: isOpen,
  });
  const {
    data: stockData,
    isError: stockError,
    refetch: retryStock,
  } = useQuery({
    queryKey: ['maintenance', 'part-stock', workOrderId, selectedItem?.id],
    queryFn: () => workOrdersApi.sparePartStock(selectedItem!.id, workOrderId),
    enabled: isOpen && !!selectedItem,
  });

  function close() {
    setPartSearch('');
    setSelectedItem(null);
    setPartQty('');
    setPartLocationId('');
    setPartOperationKey(createRequestKey());
    onClose();
  }

  const sparePartMutation = useMutation({
    mutationFn: (data: {
      item_id: string;
      location_id: string;
      quantity: string;
      operation_key: string;
      work_record_id: string;
    }) =>
      client
        .post<
          ApiSuccess<SparePartUsage>
        >(`/maintenance/work-orders/${workOrderId}/spare-parts`, data)
        .then((response) => response.data.data),
    onSuccess: () => {
      toast.success('Spare parts requested. Awaiting Warehouse handover.');
      close();
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'work-order', workOrderId] });
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'work-orders'] });
      queryClient.invalidateQueries({ queryKey: ['inventory', 'material-handovers'] });
    },
    onError: (error) => reportMutationError(error, 'Could not request spare parts.'),
  });

  const selectedStock = stockData?.data.find((row) => row.location.id === partLocationId);
  const requiresWholeQuantity = [
    'PC',
    'PCS',
    'PIECE',
    'PIECES',
    'EA',
    'EACH',
    'UNIT',
    'UNITS',
    'SET',
    'SETS',
  ].includes(selectedItem?.unit_of_measure.trim().toUpperCase() ?? '');
  const canAddPart =
    selectedItem &&
    selectedStock &&
    /^\d+(\.\d{1,3})?$/.test(partQty) &&
    Number(partQty) > 0 &&
    (!requiresWholeQuantity || Number.isInteger(Number(partQty))) &&
    Number(partQty) <= Number(selectedStock.available) &&
    !!workRecordId;
  const selectedWorkRecord = structuredLogs.find((log) => log.id === workRecordId);

  const fields = (
    <div className="space-y-4">
      <p className="text-sm text-muted">Stock changes only when Warehouse confirms physical handover. Inspection notes remain available while waiting.</p>
      {!selectedItem ? (
        <>
          <SearchableSelect
            id="part_search"
            label="Search spare parts"
            fieldSize="lg"
            value=""
            options={(itemsData?.data ?? []).map((item) => ({
              value: item.id,
              label: `${item.code} — ${item.name}${item.stock_status === 'no_usable_stock' ? ' · No usable stock' : ''}`,
            }))}
            onSearch={setPartSearch}
            onChange={(id) => {
              const item = itemsData?.data.find((entry) => entry.id === id);
              if (item) setSelectedItem(item);
            }}
            loading={partsFetching}
            placeholder="Search code or name…"
          />
          {partsError && (
            <p role="alert" className="text-sm text-danger-fg">
              Spare parts could not load.{' '}
              <button type="button" className="underline" onClick={() => void retryParts()}>
                Retry
              </button>
            </p>
          )}
          {!partsError && !partsFetching && !partSearch && itemsData?.data?.length === 0 && (
            <p className="py-4 text-sm text-muted">
              No active spare parts in inventory. Ask inventory staff to create a spare-part item
              and receive stock.
            </p>
          )}
        </>
      ) : (
        <>
          <div className="flex items-center justify-between rounded-md bg-surface p-3">
            <div>
              <div className="text-sm font-medium">{selectedItem.name}</div>
              <div className="text-xs text-muted">{selectedItem.code}</div>
            </div>
            <Button
              type="button"
              variant="ghost"
              size="lg"
              iconOnly
              icon={<LuTrash2 className="h-4 w-4" />}
              aria-label="Clear selected part"
              onClick={() => {
                setSelectedItem(null);
                setPartSearch('');
                setPartLocationId('');
                setPartQty('');
              }}
              className="text-muted hover:text-danger-fg"
            />
          </div>
          <Select
            id="part_location"
            label="Source location"
            fieldSize="lg"
            value={partLocationId}
            onChange={(event) => setPartLocationId(event.target.value)}
          >
            <option value="">Select location…</option>
            {stockData?.data?.map((stock) => (
              <option key={stock.location.id} value={stock.location.id}>
                {stock.location.code} (Available: {stock.available})
              </option>
            ))}
          </Select>
          {stockError && (
            <p role="alert" className="text-sm text-danger-fg">
              Stock could not load.{' '}
              <button type="button" className="underline" onClick={() => void retryStock()}>
                Retry
              </button>
            </p>
          )}
          {!stockError && stockData?.data.length === 0 && (
            <p className="text-sm text-muted">No usable stock available for this part.</p>
          )}
          {structuredLogs.length > 0 ? (
            <>
              <Select
                id="part_work_record"
                label="Work record"
                fieldSize="lg"
                value={workRecordId}
                onChange={(event) => setWorkRecordId(event.target.value)}
                required
              >
                {structuredLogs.map((log) => (
                  <option key={log.id} value={log.id}>
                    {log.finding}
                  </option>
                ))}
              </Select>
              {selectedWorkRecord && (
                <div className="rounded-md bg-surface p-3 text-sm">
                  <p>
                    <span className="text-muted">Finding:</span> {selectedWorkRecord.finding}
                  </p>
                  <p className="mt-1">
                    <span className="text-muted">Action taken:</span> {selectedWorkRecord.action_taken}
                  </p>
                </div>
              )}
            </>
          ) : (
            <div className="rounded-md bg-surface p-3 text-sm">
              <p>No structured work record. Use Record work at the top of this order before requesting parts.</p>
              <Button
                type="button"
                variant="secondary"
                size="lg"
                className="mt-2"
                onClick={close}
              >
                Close to record work
              </Button>
            </div>
          )}
          <Input
            id="part_qty"
            label={`Quantity (${selectedItem.unit_of_measure})`}
            fieldSize="xl"
            type="number"
            inputMode="decimal"
            min="0"
            step={requiresWholeQuantity ? '1' : '0.001'}
            value={partQty}
            onChange={(event) => setPartQty(event.target.value)}
            placeholder="0"
            className="text-center font-mono tabular-nums"
          />
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
                operation_key: partOperationKey,
                work_record_id: workRecordId,
              });
            }}
          >
            {sparePartMutation.isPending ? 'Recording…' : 'Request spare parts'}
          </Button>
        </>
      )}
    </div>
  );

  return desktop ? (
    <Modal isOpen={isOpen} onClose={close} title="Add spare part" size="md">
      {fields}
    </Modal>
  ) : (
    <BottomSheet isOpen={isOpen} onClose={close} title="Add spare part">
      {fields}
    </BottomSheet>
  );
}
