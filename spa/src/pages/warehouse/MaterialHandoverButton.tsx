import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { stockMovementsApi } from '@/api/inventory/stock';
import { Button } from '@/components/ui/Button';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { SearchableSelect } from '@/components/ui/SearchableSelect';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/formatDate';
import type { MaterialHandover } from '@/types/inventory';

export function MaterialHandoverButton({
  movementId,
  handover,
  disabled = false,
  sources,
}: {
  movementId: string;
  handover?: MaterialHandover | null;
  disabled?: boolean;
  sources?: { movement_id: string; expected_quantity: string }[];
}) {
  const { can } = usePermission();
  const client = useQueryClient();
  const [open, setOpen] = useState(false);
  const [recipient, setRecipient] = useState('');
  const options = useQuery({
    queryKey: ['inventory', 'handover-options', movementId],
    queryFn: () => stockMovementsApi.handoverOptions(movementId),
    enabled: open,
    staleTime: 0,
  });
  useEffect(() => {
    if (open && !recipient && options.data?.default_recipient_id)
      setRecipient(options.data.default_recipient_id);
  }, [open, recipient, options.data]);
  const record = useMutation({
    mutationFn: async (): Promise<unknown> =>
      sources
        ? stockMovementsApi.handoverGroup({ received_by_employee_id: recipient, sources })
        : stockMovementsApi.handover(movementId, {
            received_by_employee_id: recipient,
            expected_quantity: options.data!.quantity,
          }),
    onSuccess: () => {
      toast.success('Material handover recorded.');
      setOpen(false);
      client.invalidateQueries({ queryKey: ['inventory', 'movements'] });
      client.invalidateQueries({ queryKey: ['inventory', 'picking'] });
      client.invalidateQueries({ queryKey: ['inventory', 'material-handovers'] });
      client.invalidateQueries({ queryKey: ['production', 'work-orders'] });
      client.invalidateQueries({ queryKey: ['my-work-orders'] });
    },
  });
  if (handover)
    return (
      <div className="min-w-40 text-xs">
        <span className="font-medium">Handed to {handover.recipient_name}</span>
        <p className="text-muted">{formatDateTime(handover.handed_over_at)}</p>
        <p className="text-muted">
          Quantity {handover.quantity} · Recorded by {handover.recorded_by ?? 'Warehouse'}
        </p>
      </div>
    );
  if (!can('inventory.issue.create'))
    return <span className="text-xs text-muted">Handover not recorded</span>;
  const unavailable =
    disabled ||
    options.isError ||
    options.isFetching ||
    !options.data ||
    Number(options.data.quantity) <= 0;
  return (
    <>
      <Button
        size="sm"
        variant="secondary"
        disabled={disabled}
        onClick={() => {
          setRecipient('');
          record.reset();
          setOpen(true);
        }}
      >
        {sources ? `Confirm handover (${sources.length} lines)` : 'Record handover'}
      </Button>
      <Modal
        isOpen={open}
        onClose={() => {
          if (!record.isPending) setOpen(false);
        }}
        title="Record material handover"
        size="sm"
      >
        <p className="text-sm text-muted mb-4">
          Confirm physical delivery of the remaining issued material to Production. Stock was
          already issued; this records receipt without another stock movement.
        </p>
        {options.isError && (
          <div role="alert" className="text-sm text-danger-fg mb-3">
            {(options.error as { response?: { data?: { message?: string } } })?.response?.data
              ?.message ?? 'Handover details could not be loaded.'}{' '}
            <Button size="sm" onClick={() => options.refetch()}>
              Retry
            </Button>
          </div>
        )}
        {options.data && (
          <p className="text-sm mb-3">
            Unreturned issue quantity:{' '}
            <span className="font-mono">
              {options.data.quantity} {options.data.unit_of_measure}
            </span>
          </p>
        )}
        <SearchableSelect
          label="Received by"
          value={recipient}
          onChange={setRecipient}
          options={options.data?.recipients ?? []}
          loading={options.isFetching}
          disabled={unavailable || record.isPending}
          placeholder="Choose Production worker…"
          helper="For a WO, only its currently assigned active workers are listed."
        />
        {options.data && options.data.recipients.length === 0 && (
          <p role="alert" className="text-sm text-warning-fg mt-3">
            No active assigned recipient. Ask PPC to review the WO crew.
          </p>
        )}
        {record.isError && (
          <div role="alert" className="text-sm text-danger-fg mt-3">
            {(record.error as { response?: { data?: { message?: string } } })?.response?.data
              ?.message ?? 'Handover failed. Retry after reviewing the details.'}
            <Button
              size="sm"
              variant="secondary"
              onClick={() => {
                record.reset();
                options.refetch();
              }}
            >
              Reload details
            </Button>
          </div>
        )}
        <ModalFooter>
          <Button disabled={record.isPending} onClick={() => setOpen(false)}>
            Cancel
          </Button>
          <Button
            variant="primary"
            loading={record.isPending}
            disabled={unavailable || !recipient || record.isPending}
            onClick={() => {
              if (!unavailable && recipient) record.mutate();
            }}
          >
            Confirm handover
          </Button>
        </ModalFooter>
      </Modal>
    </>
  );
}
