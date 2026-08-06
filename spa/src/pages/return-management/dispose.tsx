import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { returnManagementApi } from '@/api/returnManagement';
import type { ReturnRequest, ReturnRequestItem, DispositionType, DispositionPayload } from '@/types/returnManagement';
import { formatInt } from '@/lib/formatNumber';
import toast from 'react-hot-toast';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

interface Props {
 rma: ReturnRequest;
 isOpen: boolean;
 onClose: () => void;
}

export default function DisposeDialog({ rma, isOpen, onClose }: Props) {
 const queryClient = useQueryClient();
 const items = useMemo(() => rma.items ?? [], [rma.items]);
 const { data: options } = useQuery({
 queryKey: ['return-management', 'options'],
 queryFn: () => returnManagementApi.options(),
 staleTime: 5 * 60 * 1000,
 });
 const dispositionOptions = useMemo(() => (options?.dispositions ?? []) as Array<{ value: DispositionType; label: string }>, [options?.dispositions]);
 // Deliberately NOT pre-filled. The dialog used to default every line to the
 // first option (Scrap), so accepting the defaults silently wrote off the
 // whole return and raised an NCR per line. Disposition is irreversible —
 // it must be an explicit choice.
 const [dispositions, setDispositions] = useState<Record<string, { disposition: DispositionType | ''; notes: string }>>(
 () => Object.fromEntries(
 items.map((item) => [item.id, { disposition: '' as const, notes: '' }])
 )
 );
 const [createReplacementPo, setCreateReplacementPo] = useState(false);

 const hasMissingDisposition = items.some((item) => !dispositions[item.id]?.disposition);

 const mutation = useMutation({
 mutationFn: () => {
 const payload: DispositionPayload[] = items.map((item) => ({
 item_id: item.id,
 disposition: dispositions[item.id]!.disposition as DispositionType,
 notes: dispositions[item.id]?.notes || undefined,
 }));
 return returnManagementApi.dispose(rma.id, payload, createReplacementPo);
 },
 onSuccess: () => {
 toast.success('Disposition recorded successfully.');
 queryClient.invalidateQueries({ queryKey: ['return-request', rma.id] });
 onClose();
 },
 onError: (e) => {
 // Supplier returns fail with specific, actionable messages ("Each
 // supplier-return line requires source GRN and PO lines"). The old
 // generic toast hid every one of them.
 toast.error(
 (e instanceof AxiosError ? e.response?.data?.message : undefined)
 ?? 'Failed to record disposition.',
 );
 },
 });

 const updateItem = (itemId: string, field: 'disposition' | 'notes', value: string) => {
 setDispositions((prev) => ({
 ...prev,
 [itemId]: { ...prev[itemId], [field]: value } as { disposition: DispositionType | ''; notes: string },
 }));
 };

 const itemLabel = (item: ReturnRequestItem) => {
 if (item.product) return `${item.product.part_number} - ${item.product.name}`;
 if (item.item) return `${item.item.code} - ${item.item.name}`;
 return `Item ${item.id}`;
 };

 return (
 <Modal isOpen={isOpen} onClose={onClose} title="Dispose Return Items" size="lg">
 <div className="space-y-4">
 <p className="text-sm text-muted">
 Set the disposition for each returned item. Scrap and rework items will auto-create an NCR.
 {rma.type === 'customer_return' && ' A credit memo will be generated for customer returns.'}
 </p>

 <div className="overflow-x-auto">
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>Product</Th>
 <Th align="right" className="font-mono">Qty</Th>
 <Th>Disposition</Th>
 <Th>Notes</Th>
 </tr>
 </thead>
 <tbody>
 {items.map((item) => (
 <tr key={item.id} className={trCls}>
 <Td>{itemLabel(item)}</Td>
 <Td align="right" mono>
 {formatInt(item.returned_quantity || item.quantity)}
 </Td>
 <Td>
 <Select
 fieldSize="sm"
 aria-label="Disposition"
 value={dispositions[item.id]?.disposition ?? ''}
 disabled={dispositionOptions.length === 0}
 onChange={(e) => updateItem(item.id, 'disposition', e.target.value)}
 >
 <option value="">
 {dispositionOptions.length === 0 ? 'Loading dispositions…' : '— Select —'}
 </option>
 {dispositionOptions.map((opt) => (
 <option key={opt.value} value={opt.value}>{opt.label}</option>
 ))}
 </Select>
 </Td>
 <Td>
 <Input
 fieldSize="sm"
 type="text"
 aria-label="Notes"
 placeholder="Optional notes…"
 value={dispositions[item.id]?.notes ?? ''}
 onChange={(e) => updateItem(item.id, 'notes', e.target.value)}
 maxLength={500}
 />
 </Td>
 </tr>
 ))}
 </tbody>
 </table>
 </div>

 <div className="flex items-center justify-between gap-2 pt-2">
 {/* Supplier returns can raise a replacement PO in the same step; the
     backend supported it but nothing in the UI ever set the flag. */}
 {rma.type === 'supplier_return' ? (
 <label className="flex items-center gap-2 text-sm">
 <input
 type="checkbox"
 checked={createReplacementPo}
 onChange={(e) => setCreateReplacementPo(e.target.checked)}
 />
 Raise a replacement purchase order for lines returned to the supplier
 </label>
 ) : <span />}
 <div className="flex gap-2">
 <Button variant="secondary" onClick={onClose}>Cancel</Button>
 <Button
 variant="primary"
 loading={mutation.isPending}
 onClick={() => mutation.mutate()}
 disabled={items.length === 0 || hasMissingDisposition || dispositionOptions.length === 0}
 >
 {mutation.isPending ? 'Recording...' : 'Record Disposition'}
 </Button>
 </div>
 </div>
 </div>
 </Modal>
 );
}
