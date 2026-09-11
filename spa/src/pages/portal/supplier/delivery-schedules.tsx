import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { LuPlus, LuX } from '@/lib/icons';
import { supplierPortalApi } from '@/api/b2b/supplier';
import type { DeliverySchedule, PortalPoSummary } from '@/types/b2b';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { DataTablePagination } from '@/components/ui/DataTablePagination';
import { formatDate } from '@/lib/formatDate';
import { PageHeader } from '@/components/layout/PageHeader';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { useUrlFilters } from '@/hooks/useUrlFilters';

type ScheduleFilters = { page: number; per_page: number };
type ScheduleLineForm = { purchase_order_item_id: string; quantity: number; notes: string };
type ScheduleForm = { purchase_order_id: string; month: string; lines: ScheduleLineForm[] };

const emptyLine = (itemId = ''): ScheduleLineForm => ({ purchase_order_item_id: itemId, quantity: 0, notes: '' });

export default function SupplierDeliverySchedulesPage() {
  const queryClient = useQueryClient();
  const [filters, setFilters] = useUrlFilters<ScheduleFilters>({ page: 1, per_page: 25 });
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState<ScheduleForm>({
    purchase_order_id: '',
    month: new Date().toISOString().slice(0, 7),
    lines: [emptyLine()],
  });
  const initializedPo = useRef<string | null>(null);

  const schedules = useQuery({
    queryKey: ['portal', 'supplier', 'delivery-schedules', filters],
    queryFn: () => supplierPortalApi.listDeliverySchedules(filters),
    placeholderData: (previous) => previous,
  });
  const purchaseOrders = useQuery({
    queryKey: ['portal', 'supplier', 'schedule-po-options'],
    queryFn: () => supplierPortalApi.listPos({ per_page: 100 }),
    enabled: showForm,
  });
  const selectedPo = useQuery({
    queryKey: ['portal', 'supplier', 'schedule-po', form.purchase_order_id],
    queryFn: () => supplierPortalApi.getPo(form.purchase_order_id),
    enabled: showForm && !!form.purchase_order_id,
  });

  useEffect(() => {
    const po = selectedPo.data;
    if (!po || initializedPo.current === po.id) return;
    initializedPo.current = po.id;
    setForm((current) => ({ ...current, lines: po.items.map((item) => emptyLine(item.id)) }));
  }, [selectedPo.data]);

  const submit = useMutation({
    mutationFn: () => supplierPortalApi.createDeliverySchedule({
      purchase_order_id: form.purchase_order_id,
      month: form.month,
      lines: form.lines.map((line) => ({
        purchase_order_item_id: line.purchase_order_item_id,
        quantity: line.quantity,
        notes: line.notes || undefined,
      })),
    }),
    onSuccess: () => {
      toast.success('Delivery schedule submitted.');
      setShowForm(false);
      initializedPo.current = null;
      setForm({ purchase_order_id: '', month: new Date().toISOString().slice(0, 7), lines: [emptyLine()] });
      queryClient.invalidateQueries({ queryKey: ['portal', 'supplier', 'delivery-schedules'] });
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message ?? 'Could not submit the delivery schedule.');
    },
  });

  // Use the schedule-specific capability, not can_update_shipment. They happen
 // to share a status set today; reusing the wrong flag silently breaks if the
 // two ever diverge.
 const selectablePos: PortalPoSummary[] = (purchaseOrders.data?.data ?? []).filter((po) => po.capabilities?.can_schedule_delivery === true);

  const selectPo = (id: string) => {
    initializedPo.current = null;
    setForm((current) => ({ ...current, purchase_order_id: id, lines: [emptyLine()] }));
  };

  const updateLine = (index: number, field: keyof ScheduleLineForm, value: string | number) => {
    setForm((current) => ({
      ...current,
      lines: current.lines.map((line, lineIndex) => lineIndex === index ? { ...line, [field]: value } : line),
    }));
  };

  const submitForm = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!form.purchase_order_id || !form.month || form.lines.length === 0 || form.lines.some((line) => !line.purchase_order_item_id || line.quantity <= 0)) {
      toast.error('Choose a purchase-order item and enter a positive quantity for every line.');
      return;
    }
    submit.mutate();
  };

  const schedulesData: DeliverySchedule[] = schedules.data?.data ?? [];

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
                <Select label="Purchase order" required value={form.purchase_order_id} onChange={(event) => selectPo(event.target.value)}>
                  <option value="">Select PO…</option>
                  {selectablePos.map((po) => <option key={po.id} value={po.id}>{po.po_number}</option>)}
                </Select>
                <Input label="Month" type="month" required value={form.month} onChange={(event) => setForm((current) => ({ ...current, month: event.target.value }))} />
              </div>
              {selectedPo.isLoading && <SkeletonTable columns={3} rows={2} />}
              {selectedPo.data && (
                <div className="space-y-2">
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-medium text-muted">PO line items</span>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      icon={<LuPlus size={12} />}
                      onClick={() => setForm((current) => ({ ...current, lines: [...current.lines, emptyLine(selectedPo.data?.items[0]?.id ?? '')] }))}
                    >
                      Add line
                    </Button>
                  </div>
                  {form.lines.map((line, index) => (
                    <div
                      key={`${line.purchase_order_item_id}-${index}`}
                      className="grid gap-2 rounded-md border border-default bg-surface p-2 sm:grid-cols-[minmax(0,1fr)_6rem_minmax(8rem,12rem)_auto] sm:items-start"
                    >
                      <Select fieldSize="sm" aria-label="Purchase-order item" value={line.purchase_order_item_id} onChange={(event) => updateLine(index, 'purchase_order_item_id', event.target.value)}>
                        <option value="">Select item…</option>
                        {selectedPo.data.items.map((item) => <option key={item.id} value={item.id}>{item.part_number} — {item.name}</option>)}
                      </Select>
                      <Input fieldSize="sm" type="number" step="0.01" min="0.01" placeholder="Qty" aria-label="Quantity" className="font-mono tabular-nums" containerClassName="min-w-0 sm:w-24" value={line.quantity || ''} onChange={(event) => updateLine(index, 'quantity', Number(event.target.value) || 0)} />
                      <Input fieldSize="sm" type="text" placeholder="Notes" aria-label="Notes" containerClassName="min-w-0 sm:w-32" value={line.notes} onChange={(event) => updateLine(index, 'notes', event.target.value)} maxLength={500} />
                      {form.lines.length > 1 && (
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          iconOnly
                          icon={<LuX size={14} />}
                          onClick={() => setForm((current) => ({ ...current, lines: current.lines.filter((_, lineIndex) => lineIndex !== index) }))}
                          aria-label="Remove line"
                          className="justify-self-end text-muted hover:text-danger-fg sm:justify-self-auto"
                        />
                      )}
                    </div>
                  ))}
                </div>
              )}
              <div className="flex justify-end gap-2 pt-2 border-t border-default">
                <Button type="button" variant="secondary" size="sm" onClick={() => setShowForm(false)}>Cancel</Button>
                <Button type="submit" variant="primary" size="sm" loading={submit.isPending}>Submit schedule</Button>
              </div>
            </form>
          </Panel>
        )}

        {schedules.isLoading && !schedules.data && <SkeletonTable columns={3} rows={6} />}

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
                    <span>Submitted {formatDate(schedule.created_at)}</span>
                  </span>
                }
                noPadding
              >
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
                          <Td align="right" mono className="font-medium">{line.quantity}</Td>
                          <Td className="text-muted">{line.notes ?? '—'}</Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
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
    </div>
  );
}
