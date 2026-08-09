import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { returnManagementApi } from '@/api/returnManagement';
import { warehouseApi } from '@/api/inventory/warehouse';
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
 ); const [createReplacementPo, setCreateReplacementPo] = useState(false);
 // 2026-08-08 — restock lines are received back into stock the moment the
 // disposition is recorded, so customer-return disposals must name the
 // destination warehouse location.
 const [locationId, setLocationId] = useState('');

 const { data: warehouses } = useQuery({
  queryKey: ['warehouse-tree'],
  queryFn: () => warehouseApi.tree(),
  staleTime: 5 * 60 * 1000,
 });
 const locations = useMemo(() => (warehouses ?? []).flatMap((w) =>
  (w.zones ?? []).flatMap((z) =>
   (z.locations ?? []).map((l) => ({
    id: l.id,
    label: `${w.code}-${z.code}-${l.code}`,
    sub: `${w.name} / ${z.name}`,
   })),
  ),
 ), [warehouses]);

 const hasMissingDisposition = items.some((item) => !dispositions[item.id]?.disposition);
 // 2026-08-08 — any movement line needs a location: customer restock/rework
 // lines come back into stock, supplier return_to_supplier lines ship out.
 const needsLocation = items.some((item) =>
  rma.type === 'supplier_return'
   ? dispositions[item.id]?.disposition === 'return_to_supplier'
   : ['restock', 'rework'].includes(dispositions[item.id]?.disposition as string),
 );

 const mutation = useMutation({
  mutationFn: () => {
   const payload: DispositionPayload[] = items.map((item) => ({
    item_id: item.id,
    disposition: dispositions[item.id]!.disposition as DispositionType,
    notes: dispositions[item.id]?.notes || undefined,
   }));
   return returnManagementApi.dispose(rma.id, payload, createReplacementPo, needsLocation ? locationId : undefined);
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
 <Modal isOpen={isOpen} onClose={onClose} title="Dispose Return Items" size="lg"> <div className="space-y-4">
  <p className="text-sm text-muted">
   Set the disposition for each returned item. Scrap and rework items will auto-create an NCR.
   {rma.type === 'customer_return' && ' A credit memo will be generated for customer returns.'}
   {' '}
   {rma.type === 'customer_return' && (
    <span className="text-success-fg font-medium">Lines disposed as Restock are received back into stock immediately.</span>
   )}
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
 </div>  {/* 2026-08-08 — movement happens right here: customer restock/rework lines
      land back in stock, supplier return_to_supplier lines ship out. Either
      way the location is required the moment such a line is chosen. */}
  {needsLocation && (
   <div className="rounded-md border border-success/40 bg-success-bg/10 px-4 py-3">
    <label className="text-xs font-medium text-success-fg">
     {rma.type === 'supplier_return' ? 'Warehouse location for returned goods' : 'Warehouse location for restocked goods'}
    </label>
    <Select
     className="mt-1.5"
     aria-label="Restock location"
     value={locationId}
     onChange={(e) => setLocationId(e.target.value)}
    >
     <option value="">— Select location —</option>
     {locations.map((l) => (
      <option key={l.id} value={l.id}>
       {l.label} · {l.sub}
      </option>
     ))}
    </Select>
    <p className="mt-1.5 text-xs text-muted">
     {rma.type === 'supplier_return'
      ? 'Lines returned to the supplier leave stock at this location when the disposition is recorded.'
      : 'Restock and rework lines are received back into stock at this location when the disposition is recorded.'}
    </p>
   </div>
  )}

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
     disabled={items.length === 0 || hasMissingDisposition || dispositionOptions.length === 0 || (needsLocation && !locationId)}
    >
     {mutation.isPending ? 'Recording...' : 'Record Disposition'}
    </Button>
   </div>
  </div>
 </div>
 </Modal>
 );
}
