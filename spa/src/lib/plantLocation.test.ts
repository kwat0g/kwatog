import { describe, expect, it } from 'vitest';
import {
  areaHectares,
  clampRadius,
  parseCoordinatePair,
  readSavedLocation,
  roundCoord,
  sameLocation,
  toDraft,
  validateDraft,
} from './plantLocation';

describe('plantLocation', () => {
  it('rounds coordinates to six decimals and clamps the radius to whole metres in range', () => {
    expect(roundCoord(14.28601234567)).toBe(14.286012);
    expect(clampRadius(3)).toBe(10);
    expect(clampRadius(99999)).toBe(5000);
    expect(clampRadius(199.6)).toBe(200);
  });

  it('parses a pasted "lat, lng" pair in the formats Google Maps and people produce', () => {
    expect(parseCoordinatePair('14.2860, 120.9345')).toEqual({ lat: 14.286, lng: 120.9345 });
    expect(parseCoordinatePair('  14.2860 120.9345 ')).toEqual({ lat: 14.286, lng: 120.9345 });
    expect(parseCoordinatePair('14.2860;120.9345')).toEqual({ lat: 14.286, lng: 120.9345 });
  });

  it('never treats a single number or junk as a paste', () => {
    expect(parseCoordinatePair('14.2860')).toBeNull();
    expect(parseCoordinatePair('14.2860, abc')).toBeNull();
    expect(parseCoordinatePair('1, 2, 3')).toBeNull();
    expect(parseCoordinatePair('')).toBeNull();
  });

  it('accepts a valid draft and returns rounded numbers, never strings', () => {
    const { errors, value } = validateDraft({ lat: '14.28600049', lng: '120.9345', radius: '200' });
    expect(errors).toEqual({});
    expect(value).toEqual({ lat: 14.286, lng: 120.9345, radius: 200 });
  });

  it('names the field and the fix for every invalid input', () => {
    const { errors, value } = validateDraft({ lat: '95', lng: '', radius: '5' });
    expect(value).toBeNull();
    expect(errors.lat).toMatch(/between -90 and 90/);
    expect(errors.lng).toMatch(/longitude/i);
    expect(errors.radius).toMatch(/between 10 and 5000/);
  });

  it('rejects a decimal radius, because the backend stores it as an integer', () => {
    const { errors, value } = validateDraft({ lat: '14.286', lng: '120.9345', radius: '200.5' });
    expect(value).toBeNull();
    expect(errors.radius).toMatch(/whole number/i);
  });

  it('compares locations ignoring float noise below six decimals', () => {
    const a = { lat: 14.286, lng: 120.9345, radius: 200 };
    expect(sameLocation(a, { ...a, lat: 14.2860000001 })).toBe(true);
    expect(sameLocation(a, { ...a, radius: 201 })).toBe(false);
    expect(sameLocation(null, null)).toBe(true);
    expect(sameLocation(a, null)).toBe(false);
  });

  it('round-trips saved values through the form draft', () => {
    const saved = readSavedLocation(14.286, '120.9345', 200);
    expect(saved).toEqual({ lat: 14.286, lng: 120.9345, radius: 200 });
    expect(toDraft(saved)).toEqual({ lat: '14.286', lng: '120.9345', radius: '200' });
    expect(readSavedLocation(undefined, 1, 2)).toBeNull();
    expect(toDraft(null)).toEqual({ lat: '', lng: '', radius: '' });
  });

  it('reports the circle area in hectares', () => {
    expect(areaHectares(200)).toBeCloseTo(12.566, 2);
  });
});
