/**
 * Plant location picker — Settings > Company.
 *
 * Sets the three values Time In/Out is checked against: the plant centre
 * (`company.latitude`, `company.longitude`) and the allowed radius
 * (`company.punch_radius_m`). An admin who is not on site can place the pin on
 * the map, paste coordinates copied from Google Maps, or — standing at the
 * plant — read their GPS fix. The fields and the map are two views of one
 * draft, so keyboard and screen-reader users lose nothing by skipping the map.
 *
 * Owns these three settings outright: the generic setting rows for them are
 * hidden by SettingsGroup so there is one source of truth on screen.
 */
import { lazy, Suspense, useCallback, useEffect, useMemo, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { LuCheck, LuNavigation, LuRotateCcw } from '@/lib/icons';
import { settingsApi, type SettingRow } from '@/api/admin/settings';
import { reportMutationError } from '@/lib/formErrors';
import {
  RADIUS_MAX,
  RADIUS_MIN,
  RADIUS_SLIDER_MAX,
  areaHectares,
  parseCoordinatePair,
  readSavedLocation,
  roundCoord,
  toDraft,
  validateDraft,
  type PlantLocationDraft,
} from '@/lib/plantLocation';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Input } from '@/components/ui/Input';
import { SkeletonBlock } from '@/components/ui/Skeleton';

const PlantLocationMap = lazy(() => import('./PlantLocationMap'));

export const PLANT_LOCATION_KEYS = [
  'company.latitude',
  'company.longitude',
  'company.punch_radius_m',
] as const;

const GEO_ERRORS: Record<number, string> = {
  1: 'Location access is blocked. Allow it for this site in your browser settings, then try again.',
  2: 'Your location could not be determined. Move outdoors and try again.',
  3: 'Locating you took too long. Try again.',
};

interface Props {
  rows: SettingRow[];
  /** Settings page edit mode. Off = a read-only view of the same map. */
  canEdit: boolean;
}

function valueOf(rows: SettingRow[], key: string): unknown {
  return rows.find((r) => r.key === key)?.value;
}

export function PlantLocationPicker({ rows, canEdit }: Props) {
  const queryClient = useQueryClient();

  const saved = useMemo(
    () =>
      readSavedLocation(
        valueOf(rows, 'company.latitude'),
        valueOf(rows, 'company.longitude'),
        valueOf(rows, 'company.punch_radius_m'),
      ),
    [rows],
  );
  const savedDraft = useMemo(() => toDraft(saved), [saved]);
  const savedSignature = `${savedDraft.lat}|${savedDraft.lng}|${savedDraft.radius}`;

  const [draft, setDraft] = useState<PlantLocationDraft>(savedDraft);
  const [viewNonce, setViewNonce] = useState(0);
  const [saving, setSaving] = useState(false);
  const [justSaved, setJustSaved] = useState(false);
  const [locating, setLocating] = useState(false);
  const [geoError, setGeoError] = useState<string | null>(null);

  // A refetch after saving (or another admin's change) replaces the draft.
  useEffect(() => {
    setDraft(savedDraft);
    setViewNonce((n) => n + 1);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- keyed on the value signature, not the object identity
  }, [savedSignature]);

  const { errors, value } = useMemo(() => validateDraft(draft), [draft]);
  const dirty =
    draft.lat !== savedDraft.lat ||
    draft.lng !== savedDraft.lng ||
    draft.radius !== savedDraft.radius;
  const canSave = canEdit && dirty && value !== null && !saving;

  const edit = useCallback((patch: Partial<PlantLocationDraft>) => {
    setJustSaved(false);
    setGeoError(null);
    setDraft((d) => ({ ...d, ...patch }));
  }, []);

  /** Map click / pin drag: move the centre where the admin pointed, without re-framing (the pin would jump under the cursor). */
  const placePin = useCallback(
    (lat: number, lng: number) =>
      edit({ lat: String(roundCoord(lat)), lng: String(roundCoord(lng)) }),
    [edit],
  );

  /** Fields, paste and GPS: move the centre and re-frame the map on it. */
  const jumpTo = useCallback(
    (lat: number, lng: number) => {
      placePin(lat, lng);
      setViewNonce((n) => n + 1);
    },
    [placePin],
  );

  /** A "lat, lng" pair pasted into either field fills both. */
  const onCoordinateInput = (field: 'lat' | 'lng') => (text: string) => {
    const pair = parseCoordinatePair(text);
    if (pair) jumpTo(pair.lat, pair.lng);
    else edit({ [field]: text });
  };

  const reframeIfValid = () => {
    if (value) setViewNonce((n) => n + 1);
  };

  const useMyLocation = () => {
    if (!('geolocation' in navigator)) {
      setGeoError('This browser cannot provide a location.');
      return;
    }
    setLocating(true);
    setGeoError(null);
    navigator.geolocation.getCurrentPosition(
      (p) => {
        setLocating(false);
        jumpTo(p.coords.latitude, p.coords.longitude);
      },
      (e) => {
        setLocating(false);
        setGeoError(GEO_ERRORS[e.code] ?? GEO_ERRORS[2]);
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    );
  };

  const reset = () => {
    setJustSaved(false);
    setGeoError(null);
    setDraft(savedDraft);
    setViewNonce((n) => n + 1);
  };

  const save = async () => {
    if (!value) return;
    setSaving(true);
    try {
      // Sequential on purpose: if one write fails the others are not racing it,
      // and the refetch below shows exactly which values did land.
      await settingsApi.update('company.latitude', value.lat);
      await settingsApi.update('company.longitude', value.lng);
      await settingsApi.update('company.punch_radius_m', value.radius);
      toast.success('Plant location saved');
      setJustSaved(true);
    } catch (error) {
      reportMutationError(error, 'Could not save the plant location.');
    } finally {
      setSaving(false);
      await queryClient.invalidateQueries({ queryKey: ['admin', 'settings'] });
    }
  };

  const sliderValue = Math.min(
    RADIUS_SLIDER_MAX,
    Math.max(RADIUS_MIN, Number(draft.radius) || RADIUS_MIN),
  );
  const hectares = value ? areaHectares(value.radius) : null;

  return (
    <section aria-labelledby="plant-location-title" className="mb-5 pb-5 border-b border-default">
      <div className="grid gap-5 lg:grid-cols-[minmax(0,1.3fr)_minmax(280px,1fr)]">
        <div
          role="region"
          aria-label="Plant location map. The latitude, longitude and radius fields below give the same control without the map."
          className="relative h-[280px] sm:h-[340px] lg:h-auto lg:min-h-[420px] overflow-hidden rounded-md border border-default bg-elevated"
        >
          <Suspense fallback={<SkeletonBlock className="absolute inset-0 rounded-none" />}>
            <PlantLocationMap
              center={value ? { lat: value.lat, lng: value.lng } : null}
              radius={value ? value.radius : null}
              editable={canEdit}
              viewNonce={viewNonce}
              onPick={placePin}
            />
          </Suspense>
        </div>

        <div className="flex flex-col gap-4">
          <div>
            <h4 id="plant-location-title" className="text-sm font-medium text-primary">
              Time in/out area
            </h4>
            <p className="mt-1 text-xs text-muted leading-relaxed">
              Employees can only time in or out inside the circle.{' '}
              {canEdit
                ? 'Click the map or drag the pin to set the centre.'
                : 'Switch to edit mode to change it.'}
            </p>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <Input
              label="Latitude"
              inputMode="decimal"
              autoComplete="off"
              spellCheck={false}
              className="font-mono tabular-nums"
              value={draft.lat}
              readOnly={!canEdit}
              error={draft.lat !== '' || dirty ? errors.lat : undefined}
              onChange={(e) => onCoordinateInput('lat')(e.target.value)}
              onBlur={reframeIfValid}
            />
            <Input
              label="Longitude"
              inputMode="decimal"
              autoComplete="off"
              spellCheck={false}
              className="font-mono tabular-nums"
              value={draft.lng}
              readOnly={!canEdit}
              error={draft.lng !== '' || dirty ? errors.lng : undefined}
              onChange={(e) => onCoordinateInput('lng')(e.target.value)}
              onBlur={reframeIfValid}
            />
          </div>
          {canEdit && (
            <p className="-mt-2 text-xs text-muted">
              Tip: in Google Maps, right-click the plant and click the coordinates to copy them,
              then paste into either field.
            </p>
          )}

          <div className="flex flex-col gap-2">
            <div className="flex items-end justify-between gap-3">
              <Input
                label="Radius"
                type="number"
                inputMode="numeric"
                min={RADIUS_MIN}
                max={RADIUS_MAX}
                step={1}
                suffix="m"
                className="font-mono tabular-nums"
                containerClassName="w-36"
                value={draft.radius}
                readOnly={!canEdit}
                error={draft.radius !== '' || dirty ? errors.radius : undefined}
                onChange={(e) => edit({ radius: e.target.value })}
              />
              {value && hectares !== null && (
                <p className="pb-2 text-xs text-muted font-mono tabular-nums text-right">
                  {value.radius * 2} m across ·{' '}
                  {hectares < 10 ? hectares.toFixed(1) : Math.round(hectares)} ha
                </p>
              )}
            </div>
            <input
              type="range"
              aria-label="Radius in metres"
              min={RADIUS_MIN}
              max={RADIUS_SLIDER_MAX}
              step={10}
              value={sliderValue}
              disabled={!canEdit}
              onChange={(e) => edit({ radius: e.target.value })}
              className="w-full h-6 cursor-pointer accent-accent disabled:cursor-not-allowed disabled:opacity-50"
            />
            <div
              className="flex justify-between font-mono text-2xs text-muted tabular-nums"
              aria-hidden
            >
              <span>{RADIUS_MIN} m</span>
              <span>{RADIUS_SLIDER_MAX.toLocaleString()} m</span>
            </div>
          </div>

          <div aria-live="polite" className="min-h-6 flex flex-wrap items-center gap-2">
            {canEdit && dirty && value && <Chip variant="warning">Unsaved changes</Chip>}
            {canEdit && justSaved && !dirty && (
              <Chip variant="success" className="gap-1">
                <LuCheck size={12} aria-hidden /> Saved
              </Chip>
            )}
            {geoError && (
              <p role="alert" className="text-xs text-danger-fg">
                {geoError}
              </p>
            )}
          </div>

          {canEdit && (
            <div className="mt-auto flex flex-col gap-2 sm:flex-row sm:flex-wrap">
              <Button
                variant="secondary"
                icon={<LuNavigation size={14} />}
                loading={locating}
                onClick={useMyLocation}
                className="min-h-11 sm:min-h-0 sm:mr-auto"
              >
                {locating ? 'Locating…' : 'Use my current location'}
              </Button>
              {dirty && (
                <Button
                  variant="ghost"
                  icon={<LuRotateCcw size={14} />}
                  onClick={reset}
                  disabled={saving}
                >
                  Reset
                </Button>
              )}
              <Button
                variant="primary"
                disabled={!canSave}
                loading={saving}
                onClick={save}
                className="min-h-11 sm:min-h-0"
              >
                {saving ? 'Saving…' : 'Save plant location'}
              </Button>
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
