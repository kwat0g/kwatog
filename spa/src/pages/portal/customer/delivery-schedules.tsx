import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import toast from 'react-hot-toast';
import { LuPlus, LuX, LuSend } from '@/lib/icons';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatDate } from '@/lib/formatDate';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { DataTablePagination } from '@/components/ui/DataTablePagination';
import type { DeliveryScheduleLine } from '@/types/b2b';
import { CompanyName } from '@/components/brand/CompanyName';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

const MONTH_OPTIONS: string[] = [];
const now = new Date();
for (let i = 0; i < 6; i++) {
  const d = new Date(now.getFullYear(), now.getMonth() + i, 1);
  MONTH_OPTIONS.push(d.toISOString().slice(0, 7));
}

export default function DeliverySchedulesPage() {
  const queryClient = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [month, setMonth] = useState(MONTH_OPTIONS[0] ?? '');
  const [lines, setLines] = useState<DeliveryScheduleLine[]>([
    { product_name: '', quantity: 0, notes: '' },
  ]);
  const [page, setPage] = useState(1);

  const { data: schedulesPage, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'delivery-schedules', { page }],
    queryFn: () => customerPortalApi.listDeliverySchedules({ page }),
    placeholderData: (prev) => prev,
  });
  const schedules = schedulesPage?.data ?? [];

  const createMut = useMutation({
    mutationFn: () => customerPortalApi.createDeliverySchedule({ month, lines }),
    onSuccess: (res) => {
      toast.success(res.message ?? 'Delivery schedule submitted.');
      setShowForm(false);
      setLines([{ product_name: '', quantity: 0, notes: '' }]);
      queryClient.invalidateQueries({ queryKey: ['portal', 'customer', 'delivery-schedules'] });
    },
    onError: () => toast.error('Failed to submit delivery schedule.'),
  });

  const addLine = () => setLines([...lines, { product_name: '', quantity: 0, notes: '' }]);
  const removeLine = (idx: number) => {
    if (lines.length <= 1) return;
    setLines(lines.filter((_, i) => i !== idx));
  };
  const updateLine = (idx: number, field: keyof DeliveryScheduleLine, value: string | number) => {
    const updated = [...lines];
    updated[idx] = { ...updated[idx], [field]: value };
    setLines(updated);
  };

  return (
    <div>
      <PageHeader
        title="Delivery Schedules"
        subtitle={
          schedulesPage ? (
            <>{schedulesPage.meta.total} monthly requirement plans submitted to <CompanyName /></>
          ) : (
            <>Monthly delivery requirements you have submitted to <CompanyName /></>
          )
        }
        backTo="/portal/customer"
        backLabel="Portal"
        actions={
          <Button variant="primary" size="sm" icon={showForm ? <LuX size={14} /> : <LuPlus size={14} />} onClick={() => setShowForm(!showForm)}>
            {showForm ? 'Cancel' : 'New schedule'}
          </Button>
        }
      />

      <div className="px-5 py-4 space-y-4">
        {isLoading && !schedulesPage && <SkeletonTable columns={3} rows={6} />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load schedules"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}

        {!isLoading && !isError && showForm && (
          <Panel title="Submit monthly delivery requirements">
            <form onSubmit={(e) => { e.preventDefault(); createMut.mutate(); }} className="flex flex-col gap-4">
              <Select label="Month" value={month} onChange={(e) => setMonth(e.target.value)} containerClassName="max-w-xs">
                {MONTH_OPTIONS.map((m) => (
                  <option key={m} value={m}>{m}</option>
                ))}
              </Select>

              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <span className="text-xs text-muted font-medium">Line items</span>
                  <Button type="button" variant="ghost" size="sm" icon={<LuPlus size={12} />} onClick={addLine}>
                    Add item
                  </Button>
                </div>
                {lines.map((line, idx) => (
                  <div key={idx} className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-2 rounded-md border border-default bg-surface p-2">
                    <div className="min-w-0 space-y-1.5">
                      <Input
                        fieldSize="sm"
                        type="text"
                        placeholder="Product name"
                        aria-label="Product name"
                        value={line.product_name}
                        onChange={(e) => updateLine(idx, 'product_name', e.target.value)}
                        required
                      />
                      <div className="flex flex-col gap-2 sm:flex-row">
                        <Input
                          fieldSize="sm"
                          type="number"
                          placeholder="Qty"
                          aria-label="Quantity"
                          className="font-mono tabular-nums"
                          containerClassName="w-full sm:w-24"
                          value={line.quantity || ''}
                          onChange={(e) => updateLine(idx, 'quantity', parseFloat(e.target.value) || 0)}
                          required
                          min={0.01}
                          step={0.01}
                        />
                        <Input
                          fieldSize="sm"
                          type="text"
                          placeholder="Notes (optional)"
                          aria-label="Notes"
                          containerClassName="flex-1"
                          value={line.notes ?? ''}
                          onChange={(e) => updateLine(idx, 'notes', e.target.value)}
                        />
                      </div>
                    </div>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      iconOnly
                      icon={<LuX size={14} />}
                      onClick={() => removeLine(idx)}
                      disabled={lines.length <= 1}
                      aria-label="Remove line"
                      className="text-muted hover:text-danger-fg"
                    />
                  </div>
                ))}
              </div>

              <div className="flex justify-end gap-2 pt-2 border-t border-default">
                <Button type="button" variant="secondary" size="sm" onClick={() => setShowForm(false)}>
                  Cancel
                </Button>
                <Button type="submit" variant="primary" size="sm" icon={<LuSend size={14} />} loading={createMut.isPending}>
                  Submit schedule
                </Button>
              </div>
            </form>
          </Panel>
        )}

        {!isLoading && !isError && schedules.length === 0 && (
          <EmptyState
            icon="clipboard-list"
            title="No schedules yet"
            description="Submit your monthly delivery requirements above."
          />
        )}

        {!isLoading && !isError && schedules.length > 0 && (
          <div className="space-y-3">
            {schedules.map((s) => (
              <Panel
                key={s.id}
                title={s.month}
                meta={
                  <span className="flex items-center gap-2">
                    <Chip variant={chipVariantForStatus(s.status)}>{s.status_label ?? s.status}</Chip>
                    <span>Submitted {formatDate(s.created_at)}</span>
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
                      {s.lines.map((line, li) => (
                        <tr key={li} className={trCls}>
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

        {schedulesPage?.meta && schedules.length > 0 && (
          <DataTablePagination meta={schedulesPage.meta} onPageChange={setPage} />
        )}
      </div>
    </div>
  );
}
