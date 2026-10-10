import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { deliveriesApi, vehiclesApi } from '@/api/supply-chain';
import { downloadAuthenticatedFile } from '@/api/download';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { LuCamera } from '@/lib/icons';
import type { Delivery } from '@/types/supplyChain';

export function ShipmentPlanDialog({ delivery, onClose }: { delivery: Delivery; onClose: () => void }) {
  const qc = useQueryClient();
  const photoInput = useRef<HTMLInputElement>(null);
  const [scheduledDate, setScheduledDate] = useState(delivery.scheduled_date?.slice(0, 10) ?? '');
  const [expectedDate, setExpectedDate] = useState(delivery.expected_delivery_date?.slice(0, 10) ?? '');
  const [carrier, setCarrier] = useState(delivery.carrier ?? '');
  const [vehicleId, setVehicleId] = useState(delivery.vehicle?.id ?? '');
  const [driverId, setDriverId] = useState(delivery.driver?.id ?? '');
  const [reason, setReason] = useState('');
  const [photo, setPhoto] = useState<File>();
  const [preview, setPreview] = useState('');
  const [photoError, setPhotoError] = useState('');
  const existingPhoto = delivery.proofs?.find((proof) => proof.proof_type === 'loading_photo' && !proof.deleted_at);
  const assignmentChanged = Boolean(delivery.vehicle && delivery.driver) &&
    (vehicleId !== delivery.vehicle?.id || driverId !== delivery.driver?.id);

  useEffect(() => {
    if (!photo) { setPreview(''); return; }
    const url = URL.createObjectURL(photo);
    setPreview(url);
    return () => URL.revokeObjectURL(url);
  }, [photo]);

  const vehicles = useQuery({
    queryKey: ['supply-chain', 'vehicles', 'available-for-assignment'],
    queryFn: () => vehiclesApi.list({ status: 'available', per_page: 100 }),
    staleTime: 30_000,
  });
  const drivers = useQuery({
    queryKey: ['supply-chain', 'deliveries', 'driver-options'],
    queryFn: deliveriesApi.driverOptions,
    staleTime: 30_000,
  });
  const save = useMutation({
    mutationFn: (dispatch: boolean) => deliveriesApi.shipmentPlan(delivery.id, {
      scheduled_date: scheduledDate,
      expected_delivery_date: expectedDate,
      carrier: carrier.trim(),
      vehicle_id: vehicleId,
      driver_id: driverId,
      reason: reason.trim() || 'Initial shipment arrangement.',
      dispatch,
      loading_photo: photo,
    }),
    onSuccess: (updated, dispatch) => {
      qc.setQueryData(['supply-chain', 'deliveries', delivery.id], updated);
      qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries'] });
      qc.invalidateQueries({ queryKey: ['supply-chain', 'vehicles'] });
      qc.invalidateQueries({ queryKey: ['driver'] });
      toast.success(dispatch ? 'Shipment dispatched' : 'Shipment plan saved');
      onClose();
    },
  });
  const error = (save.error as AxiosError<{ message?: string; errors?: Record<string, string[]> }> | null)?.response?.data;
  const fieldError = (field: string) => error?.errors?.[field]?.[0];
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
  const futureShipment = scheduledDate > today;
  const datesValid = !scheduledDate || !expectedDate || expectedDate >= scheduledDate;
  const complete = Boolean(scheduledDate && expectedDate && carrier.trim() && vehicleId && driverId) &&
    datesValid && (!assignmentChanged || reason.trim().length >= 5);
  const lookupsUnavailable = vehicles.isLoading || vehicles.isError || drivers.isLoading || drivers.isError;

  return <Modal isOpen onClose={() => { if (!save.isPending) onClose(); }} title="Arrange shipment" size="lg" closeOnOverlayClick={!save.isPending}>
    <form onSubmit={(event) => { event.preventDefault(); if (!save.isPending && complete && !lookupsUnavailable) save.mutate(false); }} className="space-y-5">
      <p className="text-sm text-muted">Save the shipment plan, or attach a photo of the loaded goods and dispatch the truck.</p>
      {save.isError && <div role="alert" className="rounded-md bg-danger-bg p-3 text-sm text-danger-fg">
        {error?.message ?? 'Shipment could not be saved. Review the details and try again.'}
        {error?.errors && <ul className="mt-1 list-disc pl-5">{Object.entries(error.errors).map(([field, messages]) => <li key={field}>{messages[0]}</li>)}</ul>}
      </div>}
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Input label="Shipment date" type="date" required value={scheduledDate} onChange={(event) => setScheduledDate(event.target.value)} error={fieldError('scheduled_date')} disabled={save.isPending} />
        <Input label="Expected arrival" type="date" required min={scheduledDate || undefined} value={expectedDate} onChange={(event) => setExpectedDate(event.target.value)} error={fieldError('expected_delivery_date') ?? (!datesValid ? 'Expected arrival must be on or after the shipment date.' : undefined)} disabled={save.isPending} />
        <Input label="Carrier" required maxLength={100} value={carrier} onChange={(event) => setCarrier(event.target.value)} error={fieldError('carrier')} disabled={save.isPending} containerClassName="sm:col-span-2" />
        <Select label="Vehicle" required value={vehicleId} onChange={(event) => setVehicleId(event.target.value)} error={fieldError('vehicle_id')} disabled={save.isPending || vehicles.isLoading || vehicles.isError || delivery.status === 'loading'}>
          <option value="">{vehicles.isLoading ? 'Loading vehicles…' : 'Select available vehicle'}</option>
          {delivery.vehicle && !vehicles.data?.data.some((vehicle) => vehicle.id === delivery.vehicle?.id) && <option value={delivery.vehicle.id}>{delivery.vehicle.name} ({delivery.vehicle.plate_number})</option>}
          {(vehicles.data?.data ?? []).map((vehicle) => <option key={vehicle.id} value={vehicle.id}>{vehicle.name} ({vehicle.plate_number})</option>)}
        </Select>
        <Select label="Driver" required value={driverId} onChange={(event) => setDriverId(event.target.value)} error={fieldError('driver_id')} disabled={save.isPending || drivers.isLoading || drivers.isError || delivery.status === 'loading'}>
          <option value="">{drivers.isLoading ? 'Loading drivers…' : 'Select active driver'}</option>
          {delivery.driver && !drivers.data?.some((driver) => driver.id === delivery.driver?.id) && <option value={delivery.driver.id}>{delivery.driver.name}</option>}
          {(drivers.data ?? []).map((driver) => <option key={driver.id} value={driver.id}>{driver.name}</option>)}
        </Select>
      </div>
      {delivery.status === 'loading' && <p className="text-xs text-muted">Vehicle and driver are fixed after loading begins.</p>}
      {vehicles.isError && <p role="alert" className="text-sm text-danger-fg">Could not load vehicles. <Button size="sm" type="button" onClick={() => vehicles.refetch()}>Retry vehicles</Button></p>}
      {drivers.isError && <p role="alert" className="text-sm text-danger-fg">Could not load drivers. <Button size="sm" type="button" onClick={() => drivers.refetch()}>Retry drivers</Button></p>}
      {!lookupsUnavailable && ((!vehicles.data?.data.length && !delivery.vehicle) || (!drivers.data?.length && !delivery.driver)) && <p role="status" className="text-sm text-warning-fg">No available vehicle or active driver. Ask dispatch to check fleet and driver availability.</p>}
      <Textarea label={assignmentChanged ? 'Reason for reassignment' : 'Shipment instructions (optional)'} required={assignmentChanged} minLength={assignmentChanged ? 5 : undefined} maxLength={500} value={reason} onChange={(event) => setReason(event.target.value)} error={fieldError('reason')} disabled={save.isPending} rows={2} />
      <div className="space-y-2 border-t border-default pt-4">
        <h3 className="text-sm font-medium">Loading photo</h3>
        <p id="loading-photo-help" className="text-xs text-muted">Photograph the loaded goods before departure. Required to dispatch; a customer receipt is uploaded after arrival.</p>
        <input ref={photoInput} id="loading-photo" aria-label="Loading photo" aria-invalid={Boolean(photoError || fieldError('loading_photo'))} aria-describedby={photoError || fieldError('loading_photo') ? 'loading-photo-error' : 'loading-photo-help'} type="file" accept="image/*" className="sr-only" tabIndex={-1} disabled={save.isPending} onChange={(event) => {
          const selected = event.target.files?.[0];
          event.target.value = '';
          if (!selected) return;
          if (!selected.type.startsWith('image/') || selected.size > 10 * 1024 * 1024) {
            setPhoto(undefined);
            setPhotoError('Choose an image no larger than 10MB.');
            return;
          }
          setPhoto(selected);
          setPhotoError('');
        }} />
        {preview ? <img src={preview} alt="Selected loading photo" className="max-h-48 w-full rounded-md object-contain bg-subtle" /> : existingPhoto?.view_url ? <button type="button" className="block w-full rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent" onClick={() => void downloadAuthenticatedFile(existingPhoto.view_url!, { openInNewTab: true, errorMessage: 'Failed to open the loading photo.' })}><img src={existingPhoto.view_url} alt="Saved loading photo" className="max-h-48 w-full rounded-md object-contain bg-subtle" /></button> : null}
        {(photo || existingPhoto) && <p className="text-xs text-muted break-words">{photo?.name ?? existingPhoto?.file_name}</p>}
        <Button type="button" icon={<LuCamera size={14} />} disabled={save.isPending} onClick={() => photoInput.current?.click()}>{photo || existingPhoto ? 'Replace loading photo' : 'Choose loading photo'}</Button>
        {(photoError || fieldError('loading_photo')) && <p id="loading-photo-error" role="alert" className="text-sm text-danger-fg">{photoError || fieldError('loading_photo')}</p>}
      </div>
      {futureShipment && <p role="status" className="text-sm text-warning-fg">Shipment date is in the future. Save the plan now; dispatch on the shipment date.</p>}
      <ModalFooter className="flex-wrap">
        <Button type="submit" variant="secondary" loading={save.isPending && save.variables === false} disabled={save.isPending || !complete || lookupsUnavailable}>Save plan</Button>
        <Button type="button" variant="primary" loading={save.isPending && save.variables === true} disabled={save.isPending || !complete || lookupsUnavailable || futureShipment || !(photo || existingPhoto) || Boolean(photoError)} onClick={() => save.mutate(true)}>Dispatch shipment</Button>
      </ModalFooter>
    </form>
  </Modal>;
}
