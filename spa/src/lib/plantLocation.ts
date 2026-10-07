/**
 * Plant location draft logic — the pure half of the Settings map picker.
 *
 * The three settings the picker owns (`company.latitude`, `company.longitude`,
 * `company.punch_radius_m`) are validated on the backend as numeric ranges, and
 * the radius is stored as a JSON integer, so a decimal radius would be refused
 * by the stored-type guard. Everything here exists so the form can never send
 * a value the API will bounce.
 */

export const RADIUS_MIN = 10;
export const RADIUS_MAX = 5000;
/** Slider ceiling; the number field still accepts up to RADIUS_MAX. */
export const RADIUS_SLIDER_MAX = 1000;

export interface PlantLocation {
  lat: number;
  lng: number;
  radius: number;
}

/** Form state — strings, because that is what an <input> holds mid-typing. */
export interface PlantLocationDraft {
  lat: string;
  lng: string;
  radius: string;
}

export interface PlantLocationErrors {
  lat?: string;
  lng?: string;
  radius?: string;
}

/** Six decimals is ~0.11 m — finer than any phone GPS fix. */
export function roundCoord(value: number): number {
  return Math.round(value * 1e6) / 1e6;
}

export function clampRadius(value: number): number {
  return Math.min(RADIUS_MAX, Math.max(RADIUS_MIN, Math.round(value)));
}

export function toDraft(location: PlantLocation | null): PlantLocationDraft {
  if (!location) return { lat: '', lng: '', radius: '' };

  return {
    lat: String(roundCoord(location.lat)),
    lng: String(roundCoord(location.lng)),
    radius: String(location.radius),
  };
}

/**
 * Accepts what Google Maps copies from a right-click ("14.2860, 120.9345") and
 * the common variants — space, semicolon or tab separated. Returns null unless
 * BOTH halves are numbers, so a half-typed value is never mistaken for a paste.
 */
export function parseCoordinatePair(text: string): { lat: number; lng: number } | null {
  const parts = text
    .trim()
    .split(/[\s,;]+/)
    .filter(Boolean);
  if (parts.length !== 2) return null;

  const [lat, lng] = parts.map(Number);
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;

  return { lat, lng };
}

function parseField(raw: string): number | null {
  if (raw.trim() === '') return null;
  const n = Number(raw);

  return Number.isFinite(n) ? n : null;
}

export function validateDraft(draft: PlantLocationDraft): {
  errors: PlantLocationErrors;
  value: PlantLocation | null;
} {
  const errors: PlantLocationErrors = {};

  const lat = parseField(draft.lat);
  if (lat === null) errors.lat = 'Enter a latitude, for example 14.2860.';
  else if (lat < -90 || lat > 90) errors.lat = 'Latitude must be between -90 and 90.';

  const lng = parseField(draft.lng);
  if (lng === null) errors.lng = 'Enter a longitude, for example 120.9345.';
  else if (lng < -180 || lng > 180) errors.lng = 'Longitude must be between -180 and 180.';

  const radius = parseField(draft.radius);
  if (radius === null) errors.radius = `Enter a radius between ${RADIUS_MIN} and ${RADIUS_MAX} m.`;
  else if (!Number.isInteger(radius)) errors.radius = 'Use a whole number of metres.';
  else if (radius < RADIUS_MIN || radius > RADIUS_MAX) {
    errors.radius = `Radius must be between ${RADIUS_MIN} and ${RADIUS_MAX} m.`;
  }

  if (errors.lat || errors.lng || errors.radius || lat === null || lng === null || radius === null) {
    return { errors, value: null };
  }

  return { errors, value: { lat: roundCoord(lat), lng: roundCoord(lng), radius } };
}

export function sameLocation(a: PlantLocation | null, b: PlantLocation | null): boolean {
  if (!a || !b) return a === b;

  return roundCoord(a.lat) === roundCoord(b.lat) && roundCoord(a.lng) === roundCoord(b.lng) && a.radius === b.radius;
}

/** Hectares inside the circle, for a sense-check ("is 200 m the whole compound?"). */
export function areaHectares(radiusM: number): number {
  return (Math.PI * radiusM * radiusM) / 10_000;
}

/** Saved settings arrive as JSON numbers, but a stringified number must not break the form. */
export function readSavedLocation(
  lat: unknown,
  lng: unknown,
  radius: unknown,
): PlantLocation | null {
  const nLat = Number(lat);
  const nLng = Number(lng);
  const nRadius = Number(radius);
  if (![nLat, nLng, nRadius].every(Number.isFinite)) return null;

  return { lat: nLat, lng: nLng, radius: nRadius };
}
