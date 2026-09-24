import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { LuPlus, LuX } from '@/lib/icons';
import { supplierPortalApi } from '@/api/b2b/supplier';
import type { DeliverySchedule } from '@/types/b2b';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { DataTablePagination } from '@/components/ui/DataTablePagination';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { formatDate, localIsoDate } from '@/lib/formatDate';
import { PageHeader } from '@/components/layout/PageHeader';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { useUrlFilters } from '@/hooks/useUrlFilters';

type ScheduleFilters = { page: number; per_page: number };
type ScheduleLineForm = { purchase_order_item_id: string; quantity: string; notes: string };
type ScheduleForm = { purchase_order_id: string; month: string; lines: ScheduleLineForm[] };

const emptyLine = (itemId = ''): ScheduleLineForm => ({ purchase_order_item_id: itemId, quantity: '', notes: '' });
// Manila calendar month, not UTC: the API rejects months before the current one.
const currentMonth = () => localIsoDate().slice(0, 7);

function isValidQuantity(val: string): boolean {
  return /^\d+(\.\d{1,2})?$/.test(val);
}

export default function SupplierDeliverySchedulesPage() {
  const queryClient = useQueryClient();
  const [filters, setFilters] = useUrlFilters<ScheduleFilters>({ page: 1, per_page: 25 });
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState<ScheduleForm>({
    purchase_order_id: '',
    month: currentMonth(),
    lines: [emptyLine()],
  });
  const [cancelingScheduleId, setCancelingScheduleId] = useState<string | null>(null);
  const initializedPo = useRef<string | null>(null);

  const schedules = useQuery({
    queryKey: ['portal', 'supplier', 'delivery-schedules', filters],
    queryFn: () => supplierPortalApi.listDeliverySchedules(filters),
    placeholderData: (previous) => previous,
  });

  const eligiblePos = useQuery({
    queryKey: ['portal', 'supplier', 'eligible-pos'],
    queryFn: () => supplierPortalApi.getEligiblePurchaseOrders(),
    enabled: showForm,
  });

  const selectedPo = eligiblePos.data?.find((po) => po.id === form.purchase_order_id);

  useEffect(() => {
    if (!selectedPo || initializedPo.current === selectedPo.id) return;
    initializedPo.current = selectedPo.id;
    // Pre-fill with first item that has schedulable qty > 0
    const firstSchedulableItem = selectedPo.items.find((item) => parseFloat(item.quantity_schedulable) > 0);
    setForm((current) => ({ ...current, lines: [emptyLine(firstSchedulableItem?.id ?? '')] }));
  }, [selectedPo]);

  const submit = useMutation({
    mutationFn: () => supplierPortalApi.createDeliverySchedule({
      purchase_order_id: form.purchase_order_id,
      month: form.month,
      lines: form.lines
        .filter((line) => line.purchase_order_item_id && line.quantity)
        .map((line) => ({
          purchase_order_item_id: line.purchase_order_item_id,
          quantity: line.quantity,
          notes: line.notes || undefined,
        })),
    }),
    onSuccess: () => {
      toast.success('Delivery schedule submitted.');
      setShowForm(false);
      initializedPo.current = null;
      setForm({ purchase_order_id: '', month: currentMonth(), lines: [emptyLine()] });
      queryClient.invalidateQueries({ queryKey: ['portal', 'supplier', 'delivery-schedules'] });
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message ?? 'Could not submit the delivery schedule.');
    },
  });

  const cancel = useMutation({
    mutationFn: ({ scheduleId, reason }: { scheduleId: string; reason: string }) =>
      supplierPortalApi.cancelDeliverySchedule(scheduleId, reason),
    onSuccess: () => {
      toast.success('Delivery schedule cancelled.');
      setCancelingScheduleId(null);
      queryClient.invalidateQueries({ queryKey: ['portal', 'supplier', 'delivery-schedules'] });
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message ?? 'Could not cancel the delivery schedule.');
    },
  });

  const selectPo = (id: string) => {
    initializedPo.current = null;
    setForm((current) => ({ ...current, purchase_order_id: id, lines: [emptyLine()] }));
  };

  const updateLine = (index: number, field: keyof ScheduleLineForm, value: string) => {
    setForm((current) => ({
      ...current,
      lines: current.lines.map((line, lineIndex) =>
        lineIndex === index ? { ...line, [field]: value } : line
      ),
    }));
  };

  const addLine = () => {
    if (!selectedPo) return;
    // Find first item not already chosen with schedulable > 0
    const usedIds = new Set(form.lines.map((l) => l.purchase_order_item_id));
    const nextItem = selectedPo.items.find(
      (item) => !usedIds.has(item.id) && parseFloat(item.quantity_schedulable) > 0
    );
    setForm((current) => ({
      ...current,
      lines: [...current.lines, emptyLine(nextItem?.id ?? '')],
    }));
  };

  const submitForm = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (
      !form.purchase_order_id ||
      !form.month ||
      form.lines.length === 0 ||
      form.lines.some((line) => !line.purchase_order_item_id || !line.quantity || !isValidQuantity(line.quantity))
    ) {
      toast.error('Choose a purchase-order item and enter a valid quantity for every line.');
      return;
    }

    // Validate quantities against schedulable amounts
    const errors: string[] = [];
    form.lines.forEach((line, index) => {
      const item = selectedPo?.items.find((i) => i.id === line.purchase_order_item_id);
      if (!item) return;
      const schedulable = parseFloat(item.quantity_schedulable);
      const qty = parseFloat(line.quantity);
      if (qty > schedulable) {
        errors.push(`Line ${index + 1} (${item.name}) exceeds schedulable quantity (max ${item.quantity_schedulable})`);
      }
    });

    if (errors.length > 0) {
      toast.error(errors[0]);
      return;
    }

    submit.mutate();
  };

  const schedulesData: DeliverySchedule[] = schedules.data?.data ?? [];
  const hasEligiblePos = (eligiblePos.data ?? []).some((po) => po.items.some((item) => parseFloat(item.quantity_schedulable) > 0));

  return (
    <div>
      <PageHeader
        title="Delivery Schedules"
        subtitle={
          schedules.data
            ? `${schedules.data.meta.total} schedules submitted`
            : 'Submit and manage your delivery plans'
        }
        backTo="/portal/supplier"
        backLabel="Portal"
        actions={
          <Button
            variant="primary"
            size="sm"
            icon={showForm ? <LuX size={14} /> : <LuPlus size={14} />}
            onClick={() => setShowForm((open) => !open)}
            disabled={!showForm && !hasEligiblePos}
            title={!hasEligiblePos ? 'Accept a purchase order with undelivered quantity to schedule deliveries' : undefined}
          >
            {showForm ? 'Cancel' : 'New schedule'}
          </Button>
        }
      />

      <div className="px-5 py-4 space-y-4">
        {showForm && (
          <Panel title="New delivery schedule">
            <form onSubmit={submitForm} className="space-y-4">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <Select
                  label="Purchase order"
                  required
                  value={form.purchase_order_id}
                  onChange={(event) => selectPo(event.target.value)}
                >
                  <option value="">Select PO…</option>
                  {(eligiblePos.data ?? []).map((po) => (
                    <option key={po.id} value={po.id}>
                      {po.po_number}
                    </option>
                  ))}
                </Select>
                <Input
                  label="Month"
                  type="month"
                  required
                  value={form.month}
                  min={currentMonth()}
                  onChange={(event) => setForm((current) => ({ ...current, month: event.target.value }))}
                />
              </div>

              {eligiblePos.isLoading && <SkeletonTable columns={3} rows={2} />}
              {selectedPo && (
                <div className="space-y-2">
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-medium text-muted">PO line items</span>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      icon={<LuPlus size={12} />}
                      onClick={() => addLine()}
                    >
                      Add line
                    </Button>
                  </div>
                  {form.lines.map((line, index) => {
                    const item = selectedPo.items.find((i) => i.id === line.purchase_order_item_id);
                    const isItemSelected = !!item;
                    const usedIds = new Set(form.lines.map((l, i) => (i !== index ? l.purchase_order_item_id : null)));

                    return (
                      <div
                        key={`${line.purchase_order_item_id}-${index}`}
                        className="grid gap-2 rounded-md border border-default bg-surface p-2 sm:grid-cols-[minmax(0,1fr)_6rem_minmax(8rem,12rem)_auto] sm:items-start"
                      >
                        <div>
                          <Select
                            fieldSize="sm"
                            aria-label="Purchase-order item"
                            value={line.purchase_order_item_id}
                            onChange={(event) => updateLine(index, 'purchase_order_item_id', event.target.value)}
                          >
                            <option value="">Select item…</option>
                            {selectedPo.items.map((poItem) => (
                              <option
                                key={poItem.id}
                                value={poItem.id}
                                disabled={usedIds.has(poItem.id)}
                              >
                                {poItem.part_number} — {poItem.name}
                              </option>
                            ))}
                          </Select>
                          {isItemSelected && (
                            <div className="mt-1 text-xs text-muted">
                              Schedulable: <span className="font-mono">{item.quantity_schedulable}</span>
                            </div>
                          )}
                        </div>
                        <Input
                          fieldSize="sm"
                          type="text"
                          placeholder="Qty"
                          aria-label="Quantity"
                          className="font-mono tabular-nums"
                          containerClassName="min-w-0 sm:w-24"
                          value={line.quantity}
                          onChange={(event) => updateLine(index, 'quantity', event.target.value)}
                          pattern="^\d+(\.\d{1,2})?$"
                        />
                        <Input
                          fieldSize="sm"
                          type="text"
                          placeholder="Notes"
                          aria-label="Notes"
                          containerClassName="min-w-0 sm:w-32"
                          value={line.notes}
                          onChange={(event) => updateLine(index, 'notes', event.target.value)}
                          maxLength={500}
                        />
                        {form.lines.length > 1 && (
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            iconOnly
                            icon={<LuX size={14} />}
                            onClick={() => setForm((current) => ({ ...current, lines: current.lines.filter((_, i) => i !== index) }))}
                            aria-label="Remove line"
                            className="justify-self-end text-muted hover:text-danger-fg sm:justify-self-auto"
                          />
                        )}
                      </div>
                    );
                  })}
                </div>
              )}
              <div className="flex justify-end gap-2 pt-2 border-t border-default">
                <Button type="button" variant="secondary" size="sm" onClick={() => setShowForm(false)}>
                  Cancel
                </Button>
                <Button type="submit" variant="primary" size="sm" loading={submit.isPending}>
                  Submit schedule
                </Button>
              </div>
            </form>
          </Panel>
        )}

        {schedules.isLoading && !schedules.data && <SkeletonTable columns={4} rows={6} />}

        {schedules.isError && (
          <EmptyState
            icon="alert-circle"
            title="Could not load delivery schedules"
            action={<Button variant="secondary" onClick={() => schedules.refetch()}>Retry</Button>}
          />
        )}

        {!schedules.isLoading && !schedules.isError && schedulesData.length === 0 && (
          <EmptyState
            icon="clipboard-list"
            title="No delivery schedules yet"
            description="Submit your first delivery schedule using the button above."
          />
        )}

        {schedulesData.length > 0 && (
          <div className="space-y-3">
            {schedulesData.map((schedule) => (
              <Panel
                key={schedule.id}
                title={schedule.month}
                meta={
                  <span className="flex items-center gap-2">
                    {schedule.purchase_order?.po_number && (
                      <span className="font-mono text-accent">{schedule.purchase_order.po_number}</span>
                    )}
                    <Chip variant={chipVariantForStatus(schedule.status)}>
                      {schedule.status_label ?? schedule.status}
                    </Chip>
                    <span className="text-sm">Submitted {formatDate(schedule.created_at)}</span>
                  </span>
                }
                noPadding
              >
                <div className="space-y-3">
                  {(schedule.reject_reason || schedule.cancel_reason) && (
                    <div className="border-b border-default px-4 pt-3">
                      {schedule.reject_reason && (
                        <div className="text-sm">
                          <span className="font-medium text-orange-fg">Rejection reason:</span> {schedule.reject_reason}
                        </div>
                      )}
                      {schedule.cancel_reason && (
                        <div className="text-sm">
                          <span className="font-medium text-slate-fg">Cancellation reason:</span> {schedule.cancel_reason}
                        </div>
                      )}
                    </div>
                  )}
                  <div className="overflow-x-auto">
                    <table className={tableCls}>
                      <thead>
                        <tr className={theadTrCls}>
                          <Th>Product</Th>
                          <Th align="right">Qty</Th>
                          <Th>Notes</Th>
                        </tr>
                      </thead>
                      <tbody>
                        {schedule.lines.map((line, index) => (
                          <tr key={index} className={trCls}>
                            <Td>{line.product_name}</Td>
                            <Td align="right" mono className="font-medium">
                              {line.quantity}
                            </Td>
                            <Td className="text-muted">{line.notes ?? '—'}</Td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  {schedule.can_cancel && (
                    <div className="flex justify-end gap-2 border-t border-default px-4 py-3">
                      <Button
                        variant="secondary"
                        size="sm"
                        onClick={() => setCancelingScheduleId(schedule.id)}
                      >
                        Cancel schedule
                      </Button>
                    </div>
                  )}
                </div>
              </Panel>
            ))}
          </div>
        )}

        {schedules.data && schedulesData.length > 0 && (
          <DataTablePagination
            meta={schedules.data.meta}
            perPage={filters.per_page}
            onPageChange={(page) => setFilters((current) => ({ ...current, page }))}
            onPageSizeChange={(per_page) => setFilters((current) => ({ ...current, per_page, page: 1 }))}
          />
        )}
      </div>

      <ReasonDialog
        isOpen={!!cancelingScheduleId}
        title="Cancel delivery schedule"
        description="The quantity on this schedule becomes available to schedule again. OGAMI is notified if it had already acknowledged the plan."
        reasonLabel="Reason for cancellation"
        confirmLabel="Cancel schedule"
        cancelLabel="Keep schedule"
        variant="danger"
        onConfirm={(reason) => {
          if (cancelingScheduleId) {
            cancel.mutate({ scheduleId: cancelingScheduleId, reason });
          }
        }}
        onClose={() => setCancelingScheduleId(null)}
        pending={cancel.isPending}
      />
    </div>
  );
}
