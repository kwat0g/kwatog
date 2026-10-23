import { describe, expect, it } from 'vitest';
import { googleMapsDirectionsUrl, hasText, telHref } from '../maps';

describe('googleMapsDirectionsUrl', () => {
  it('builds a directions link with the address as destination', () => {
    expect(googleMapsDirectionsUrl('Toyota Plant, Santa Rosa, Laguna')).toBe(
      'https://www.google.com/maps/dir/?api=1&destination=Toyota%20Plant%2C%20Santa%20Rosa%2C%20Laguna',
    );
  });

  it('trims surrounding whitespace and escapes characters that would break the query', () => {
    expect(googleMapsDirectionsUrl('  Block 2, Lots 1 & 2, FCIE  ')).toBe(
      'https://www.google.com/maps/dir/?api=1&destination=Block%202%2C%20Lots%201%20%26%202%2C%20FCIE',
    );
    expect(googleMapsDirectionsUrl('Gate #3 / Door B?')).toContain('%23');
    expect(googleMapsDirectionsUrl('Gate #3 / Door B?')).toContain('%3F');
  });

  it('leaves the origin unset so Maps resolves the driver position itself', () => {
    expect(googleMapsDirectionsUrl('Cavite')).not.toContain('origin=');
  });
});

describe('telHref', () => {
  it('strips formatting but keeps a dialable number', () => {
    expect(telHref('049-500-1234')).toBe('tel:0495001234');
    expect(telHref('(049) 500 1234 loc. 2')).toBe('tel:04950012342');
    expect(telHref('+63 917 555 0100')).toBe('tel:+639175550100');
  });
});

describe('hasText', () => {
  it('treats null, undefined and blank strings as absent', () => {
    expect(hasText(null)).toBe(false);
    expect(hasText(undefined)).toBe(false);
    expect(hasText('   ')).toBe(false);
    expect(hasText('Gate 3')).toBe(true);
  });
});
