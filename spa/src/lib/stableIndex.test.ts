import { describe, expect, it } from 'vitest';
import { stableIndex } from './stableIndex';

describe('stableIndex', () => {
  it('assigns the same identity consistently after normalization', () => {
    expect(stableIndex('  Élodie Martin ', 6)).toBe(stableIndex('e\u0301lodie martin', 6));
  });

  it('always returns an index within the requested set', () => {
    for (const value of ['a', 'employee-42', '日本語']) {
      expect(stableIndex(value, 6)).toBeGreaterThanOrEqual(0);
      expect(stableIndex(value, 6)).toBeLessThan(6);
    }
  });

  it('handles an empty set', () => {
    expect(stableIndex('employee-42', 0)).toBe(0);
  });
});
