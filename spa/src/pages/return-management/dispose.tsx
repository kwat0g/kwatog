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
import { formatQuantity } from '@/lib/formatNumber';
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
 const { data: options, isFetching: optionsLoading, isError: optionsError, refetch: refetchOptions } = useQuery({
 queryKey: ['return-management', 'options', rma.type, rma.finance_only],
 queryFn: () => returnManagementApi.options({ type: rma.type, finance_only: rma.finance_only === true }),
 staleTime: 5 * 60 * 1000,
 });
 const qcSupplierReturn = rma.requires_defect_evidence === true;
 const dispositionOptions = useMemo(() => ((options?.dispositions ?? []) as Array<{ value: DispositionType; label: string }>).filter((option) => !qcSupplierReturn || option.value === 'return_to_supplier'), [options?.dispositions, qcSupplierReturn]);
 // Deliberately NOT pre-filled. The dialog used to default every line to the
 // first option (Scrap), so accepting the defaults silently wrote off the
 // whole return and raised an NCR per line. Disposition is irreversible —
 // it must be an explicit choice.
 const [dispositions, setDispositions] = useState<Record<string, { disposition: DispositionType | ''; notes: string }>>(
 () => Object.fromEntries(
 items.map((item) => [item.id, { disposition: '' as const, notes: '' }])
 )
 ); const [createReplacementPo, setCreateReplacementPo] = useState(qcSupplierReturn);
 const [requiredDeliveryDate, setRequiredDeliveryDate] = useState('');
 // Customer-return good stock stays in quarantine until its separate MRB
 // release chooses a destination. Supplier returns still need a source.
 const [locationId, setLocationId] = useState('');

 const { data: warehouses, isFetching: warehousesLoading, isError: warehousesError, refetch: refetchWarehouses } = useQuery({
  queryKey: ['warehouse-tree'],
  queryFn: () => warehouseApi.tree(),
  staleTime: 5 * 60 * 1000,
 });
 const locations = useMemo(() => (warehouses ?? []).flatMap((w) =>
  (w.is_active ? (w.zones ?? []).filter((z) => z.zone_type !== 'quarantine' && z.zone_type !== 'scrap') : []).flatMap((z) =>
   (z.locations ?? []).filter((l) => l.is_active).map((l) => ({
    id: l.id,
    label: `${w.code}-${z.code}-${l.code}`,
    sub: `${w.name} / ${z.name}`,
   })),
  ),
 ), [warehouses]);

 const hasMissingDisposition = items.some((item) => !dispositions[item.id]?.disposition);
 // Supplier return_to_supplier lines still ship at disposition. Customer
 // restock/rework destinations are chosen during MRB release.
 const needsLocation = items.some((item) =>
  rma.type === 'supplier_return' && dispositions[item.id]?.disposition === 'return_to_supplier' && !(Number(item.moved_quantity) > 0),
 );
 const needsDeliveryDate = rma.type === 'supplier_return' && (qcSupplierReturn || createReplacementPo);

 const mutation = useMutation({
  mutationFn: () => {
   const payload: DispositionPayload[] = items.map((item) => ({
    item_id: item.id,
    disposition: dispositions[item.id]!.disposition as DispositionType,
    notes: dispositions[item.id]?.notes || undefined,
   }));
   return returnManagementApi.dispose(rma.id, payload, qcSupplierReturn || createReplacementPo, needsLocation ? locationId : undefined, needsDeliveryDate ? requiredDeliveryDate : undefined);
  },
 onSuccess: (result) => {
 if (needsDeliveryDate && result.replacement_purchase_order?.status !== 'sent') {
  toast.error('Return arranged. Replacement PO needs supplier dispatch; check its status.');
 } else {
  toast.success(qcSupplierReturn ? 'Return arranged and replacement PO sent to supplier.' : 'Disposition recorded successfully.');
 }
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
 <Modal isOpen={isOpen} onClose={onClose} title={qcSupplierReturn ? 'Finish earlier supplier return' : 'Dispose Return Items'} size="lg"> <div className="space-y-4">
  {optionsError && <div role="alert" className="text-sm text-danger-fg">
   Disposition options could not be loaded. <Button variant="secondary" size="sm" onClick={() => refetchOptions()}>Retry dispositions</Button>
  </div>}
  {needsLocation && warehousesError && <div role="alert" className="text-sm text-danger-fg">
   Warehouse locations could not be loaded. <Button variant="secondary" size="sm" onClick={() => refetchWarehouses()}>Retry locations</Button>
  </div>}
  <p className="text-sm text-muted">
   {qcSupplierReturn ? 'QC already checked these defective pieces. Arranging the return creates a replacement purchase order and sends it to the supplier portal.' : 'Set the disposition for each returned item. Scrap and rework items will auto-create an NCR.'}
   {rma.is_truck_return ? ' This depot return does not create a customer credit.' : rma.type === 'customer_return' && ' Any credit follows the agreed resolution and requires Finance review. No-charge replacements do not also issue a return credit.'}
   {' '}
   {rma.type === 'customer_return' && (
    <span className="text-warning-fg font-medium">Restock and rework lines stay in quarantine until a separate MRB reviewer re-inspects and releases them.</span>
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
 {formatQuantity(item.receipt_recorded ? item.returned_quantity : item.quantity)}
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
  </div>  {/* Supplier-return stock ships from this location when disposition is recorded. */}
  {needsLocation && (
   <div className="rounded-md border border-success/40 bg-success-bg/10 px-4 py-3">
    <label className="text-xs font-medium text-success-fg">
     Warehouse location for returned goods
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
     Lines returned to the supplier leave stock at this location when the disposition is recorded.
    </p>
   </div>
  )}

  {needsDeliveryDate && (
   <Input
    label="Required delivery date"
    type="date"
    required
    min={new Date().toISOString().slice(0, 10)}
    value={requiredDeliveryDate}
    onChange={(e) => setRequiredDeliveryDate(e.target.value)}
   />
  )}

  <div className="flex items-center justify-between gap-2 pt-2">
   {/* Supplier returns can raise a replacement PO in the same step; the
       backend supported it but nothing in the UI ever set the flag. */}
   {rma.type === 'supplier_return' && !qcSupplierReturn ? (
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
     disabled={items.length === 0 || hasMissingDisposition || optionsLoading || optionsError || dispositionOptions.length === 0 || (needsLocation && (!locationId || warehousesLoading || warehousesError)) || (needsDeliveryDate && (!requiredDeliveryDate || requiredDeliveryDate < new Date().toISOString().slice(0, 10)))}
    >
     {mutation.isPending ? 'Recording...' : qcSupplierReturn ? 'Finish earlier return' : 'Record Disposition'}
    </Button>
   </div>
  </div>
 </div>
 </Modal>
 );
}
