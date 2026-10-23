import { describe, expect, it } from 'vitest';
import { formatImpact, severityVariant } from './helpers';

describe('decision-center helpers', () => {
  it('maps severity to chip variant', () => {
    expect(severityVariant('critical')).toBe('danger');
    expect(severityVariant('warning')).toBe('warning');
    expect(severityVariant('info')).toBe('info');
  });

  it('formats impact with estimate marker', () => {
    expect(formatImpact('1500.00', 'PHP', true)).toBe('~1500.00 PHP');
    expect(formatImpact('3', 'days', false)).toBe('3 days');
    expect(formatImpact('12345678901234567890.123456789', 'PHP', false)).toBe(
      '12345678901234567890.123456789 PHP',
    );
    expect(formatImpact(null, 'PHP', true)).toBeNull();
  });
});
