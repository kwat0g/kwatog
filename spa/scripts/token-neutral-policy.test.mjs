import { describe, expect, it } from 'vitest';
import { isAllowedNeutral } from './token-neutral-policy.mjs';

describe('hardcoded neutral exceptions', () => {
  it('allows white icon ink only on the photo contrast scrim', () => {
    expect(isAllowedNeutral('bg-black/60', 'bg-black/60 text-white')).toBe(true);
    expect(isAllowedNeutral('text-white', 'bg-black/60 text-white')).toBe(true);
    expect(isAllowedNeutral('text-white', 'bg-black/40 text-white')).toBe(false);
    expect(isAllowedNeutral('text-white', 'bg-canvas text-white')).toBe(false);
  });
});
