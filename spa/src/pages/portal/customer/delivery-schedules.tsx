import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import toast from 'react-hot-toast';
import { Plus, X, Send } from 'lucide-react';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatDate } from '@/lib/formatDate';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import type { DeliveryScheduleLine } from '@/types/b2b';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { CompanyName } from '@/components/brand/CompanyName';

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

  const { data: schedules, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'delivery-schedules'],
    queryFn: () => customerPortalApi.listDeliverySchedules(),
    placeholderData: (prev) => prev,
  });

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
        title="Delivery schedules"
        subtitle={<>Monthly delivery requirements you have submitted to <CompanyName /></>}
        backTo="/portal/customer"
        backLabel="Portal"
        actions={
          <Button variant="primary" size="sm" icon={showForm ? <X size={14} /> : <Plus size={14} />} onClick={() => setShowForm(!showForm)}>
            {showForm ? 'Cancel' : 'New schedule'}
          </Button>
        }
      />

      {/* One padded body holds every state, so loading and loaded agree on width. */}
      <div className="px-5 py-4 space-y-4 max-w-5xl">
        {isLoading && <SkeletonBlock className="h-64 rounded-md" />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load schedules"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}

        {/* Submission form */}
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
                  <Button type="button" variant="ghost" size="sm" icon={<Plus size={12} />} onClick={addLine}>
                    Add item
                  </Button>
                </div>
                {lines.map((line, idx) => (
                  <div key={idx} className="flex items-start gap-2 p-2 bg-surface border border-default rounded-md">
                    <div className="flex-1 space-y-1.5">
                      <Input
                        fieldSize="sm"
                        type="text"
                        placeholder="Product name"
                        aria-label="Product name"
                        value={line.product_name}
                        onChange={(e) => updateLine(idx, 'product_name', e.target.value)}
                        required
                      />
                      <div className="flex gap-2">
                        <Input
                          fieldSize="sm"
                          type="number"
                          placeholder="Qty"
                          aria-label="Quantity"
                          className="font-mono tabular-nums"
                          containerClassName="w-24"
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
                      icon={<X size={14} />}
                      onClick={() => removeLine(idx)}
                      disabled={lines.length <= 1}
                      aria-label="Remove line"
                      className="text-muted hover:text-danger"
                    />
                  </div>
                ))}
              </div>

              <Button type="submit" variant="primary" size="sm" icon={<Send size={14} />} loading={createMut.isPending} className="self-start">
                Submit schedule
              </Button>
            </form>
          </Panel>
        )}

        {/* Submitted schedules list */}
        {!isLoading && !isError && (
          <Panel title="Submitted schedules">
            {schedules && schedules.length > 0 ? (
              <div className="space-y-3">
                {schedules.map((s) => (
                  <div key={s.id} className="border border-default rounded-md p-3 hover:bg-subtle/50 transition-colors">
                    <div className="flex items-center justify-between mb-2">
                      <span className="text-xs font-medium">{s.month}</span>
                      <Chip variant={chipVariantForStatus(s.status)}>{s.status_label ?? s.status}</Chip>
                    </div>
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
                            <Td align="right" mono>{line.quantity}</Td>
                            <Td className="text-muted">{line.notes ?? '—'}</Td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                    <p className="text-2xs text-muted mt-1.5">
                      Submitted {formatDate(s.created_at)}
                    </p>
                  </div>
                ))}
              </div>
            ) : (
              <EmptyState icon="clipboard-list" title="No schedules yet" description="Submit your monthly delivery requirements above." />
            )}
          </Panel>
        )}
      </div>
    </div>
  );
}
