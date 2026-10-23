import { describe, expect, it } from 'vitest';
import { formatConfidence, formatImpact, rationaleEntries, severityVariant } from './helpers';

describe('decision-center helpers', () => {
  it('maps severity to chip variant', () => {
    expect(severityVariant('critical')).toBe('danger');
    expect(severityVariant('warning')).toBe('warning');
    expect(severityVariant('info')).toBe('info');
  });

  it('formats impact with estimate marker', () => {
    expect(formatImpact('1500.00', 'PHP', true)).toBe('~1500.00 PHP');
    expect(formatImpact('3', 'days', false)).toBe('3 days');
    expect(formatImpact(null, 'PHP', true)).toBeNull();
  });

  it('formats confidence as percent', () => {
    expect(formatConfidence('0.9')).toBe('90%');
    expect(formatConfidence(null)).toBe('n/a');
  });

  it('flattens rationale to readable rows', () => {
    expect(rationaleEntries({ reorder_point: 10, inputs: { a: 1 } })).toEqual([
      ['reorder point', '10'],
      ['inputs', '{"a":1}'],
    ]);
  });
});
