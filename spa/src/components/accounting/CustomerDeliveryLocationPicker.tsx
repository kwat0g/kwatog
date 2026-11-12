import { lazy, Suspense, useMemo } from 'react';
import { useFormContext, useWatch, type FieldValues } from 'react-hook-form';
import { Input } from '@/components/ui/Input';
import { SkeletonBlock } from '@/components/ui/Skeleton';

const CustomerDeliveryLocationMap = lazy(() => import('./CustomerDeliveryLocationMap'));

/** Shared by CRM and Accounting customer forms. A map click and the fields are the same pin. */
export function CustomerDeliveryLocationPicker({ required = false }: { required?: boolean }) {
  const { control, register, setValue, formState: { errors } } = useFormContext<FieldValues>();
  const latitudeValue = useWatch({ control, name: 'delivery_latitude' });
  const longitudeValue = useWatch({ control, name: 'delivery_longitude' });

  const latitude = useMemo(() => parseCoordinate(latitudeValue, -90, 90), [latitudeValue]);
  const longitude = useMemo(() => parseCoordinate(longitudeValue, -180, 180), [longitudeValue]);
  const location = latitude != null && longitude != null ? { lat: latitude, lng: longitude } : null;
  const latitudeError = readError(errors, 'delivery_latitude');
  const longitudeError = readError(errors, 'delivery_longitude');

  const placePin = (lat: number, lng: number) => {
    setValue('delivery_latitude', lat.toFixed(7), { shouldDirty: true, shouldValidate: true });
    setValue('delivery_longitude', lng.toFixed(7), { shouldDirty: true, shouldValidate: true });
  };

  return (
    <div className="mt-4 space-y-3">
      <div>
        <p className="text-label font-medium text-primary">Delivery map pin{required && <><span aria-hidden="true" className="ml-0.5 text-danger-fg">*</span><span className="sr-only"> required</span></>}</p>
        <p className="mt-1 text-xs leading-relaxed text-muted">Click the map or enter coordinates. The address stays unchanged.</p>
      </div>
      <div role="region" aria-label="Delivery location map" className="relative h-64 overflow-hidden rounded-md border border-default bg-elevated sm:h-72">
        <Suspense fallback={<SkeletonBlock className="absolute inset-0 rounded-none" />}>
          <CustomerDeliveryLocationMap center={location} onPick={placePin} />
        </Suspense>
      </div>
      {!location && <p className="text-xs text-muted">No map pin selected.</p>}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <Input
          label="Delivery latitude"
          inputMode="decimal"
          autoComplete="off"
          spellCheck={false}
          className="font-mono tabular-nums"
          {...register('delivery_latitude')}
          error={latitudeError}
        />
        <Input
          label="Delivery longitude"
          inputMode="decimal"
          autoComplete="off"
          spellCheck={false}
          className="font-mono tabular-nums"
          {...register('delivery_longitude')}
          error={longitudeError}
        />
      </div>
    </div>
  );
}

function parseCoordinate(value: unknown, minimum: number, maximum: number): number | null {
  if (value === '' || value == null) return null;
  const parsed = Number(value);
  return Number.isFinite(parsed) && parsed >= minimum && parsed <= maximum ? parsed : null;
}

function readError(errors: FieldValues, field: string): string | undefined {
  const error = errors[field] as { message?: unknown } | undefined;
  return typeof error?.message === 'string' ? error.message : undefined;
}
