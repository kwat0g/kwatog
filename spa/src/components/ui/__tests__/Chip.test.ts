import { describe, it, expect } from 'vitest';
import { chipVariantForStatus } from '../Chip';

describe('chipVariantForStatus', () => {
  it('maps approved to success', () => {
    expect(chipVariantForStatus('approved')).toBe('success');
  });

  it('maps posted to success', () => {
    expect(chipVariantForStatus('posted')).toBe('success');
  });

  it('maps breakdown to danger', () => {
    expect(chipVariantForStatus('breakdown')).toBe('danger');
  });

  it('maps draft to warning', () => {
    expect(chipVariantForStatus('draft')).toBe('warning');
  });

  it('maps maintenance to warning', () => {
    expect(chipVariantForStatus('maintenance')).toBe('warning');
  });

  it('maps in_progress to info', () => {
    expect(chipVariantForStatus('in_progress')).toBe('info');
  });

  it('maps unknown status to neutral', () => {
    expect(chipVariantForStatus('some_future_status')).toBe('neutral');
  });

  it('maps null to neutral', () => {
    expect(chipVariantForStatus(null)).toBe('neutral');
  });

  it('maps undefined to neutral', () => {
    expect(chipVariantForStatus(undefined)).toBe('neutral');
  });
});
