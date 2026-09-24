import { describe, it, expect } from 'vitest';
import { computeLotChecklistVerdict } from './computeLotChecklistVerdict';

type ChecklistItem = { is_critical: boolean; is_pass: boolean };
type NumericItem = {
  is_critical: boolean;
  sample_index: number;
  measured_value: string | null;
  tolerance_min: number | null;
  tolerance_max: number | null;
};

describe('computeLotChecklistVerdict', () => {
  it('passes when all checks are OK and defects within limit', () => {
    const checklist: ChecklistItem[] = [
      { is_critical: false, is_pass: true },
      { is_critical: false, is_pass: true },
    ];
    const numeric: NumericItem[] = [
      {
        is_critical: false,
        sample_index: 1,
        measured_value: '10',
        tolerance_min: 8,
        tolerance_max: 12,
      },
    ];
    const result = computeLotChecklistVerdict(checklist, numeric, 0, 2);
    expect(result.verdict).toBe('pass');
  });

  it('fails when critical checklist item is NG', () => {
    const checklist: ChecklistItem[] = [
      { is_critical: true, is_pass: false },
      { is_critical: false, is_pass: true },
    ];
    const numeric: NumericItem[] = [];
    const result = computeLotChecklistVerdict(checklist, numeric, 0, 2);
    expect(result.verdict).toBe('fail');
    expect(result.reason).toContain('Critical checklist item NG');
  });

  it('fails when critical measurement is out of tolerance', () => {
    const checklist: ChecklistItem[] = [{ is_critical: false, is_pass: true }];
    const numeric: NumericItem[] = [
      {
        is_critical: true,
        sample_index: 1,
        measured_value: '15',
        tolerance_min: 8,
        tolerance_max: 12,
      },
    ];
    const result = computeLotChecklistVerdict(checklist, numeric, 0, 2);
    expect(result.verdict).toBe('fail');
    expect(result.reason).toContain('Critical measurement out of tolerance');
  });

  it('fails when defects exceed accept count', () => {
    const checklist: ChecklistItem[] = [{ is_critical: false, is_pass: true }];
    const numeric: NumericItem[] = [
      {
        is_critical: false,
        sample_index: 1,
        measured_value: '15',
        tolerance_min: 8,
        tolerance_max: 12,
      },
      {
        is_critical: false,
        sample_index: 2,
        measured_value: '16',
        tolerance_min: 8,
        tolerance_max: 12,
      },
    ];
    const result = computeLotChecklistVerdict(checklist, numeric, 0, 1);
    expect(result.verdict).toBe('fail');
    expect(result.reason).toContain('Defects (2) exceed Ac (1)');
  });

  it('fails when sample defect count exceeds accept count', () => {
    const checklist: ChecklistItem[] = [{ is_critical: false, is_pass: true }];
    const numeric: NumericItem[] = [];
    const result = computeLotChecklistVerdict(checklist, numeric, 5, 2);
    expect(result.verdict).toBe('fail');
    expect(result.reason).toContain('Defects (5) exceed Ac (2)');
  });

  it('passes when non-critical items are NG but within defect limit', () => {
    const checklist: ChecklistItem[] = [{ is_critical: false, is_pass: false }];
    const numeric: NumericItem[] = [
      {
        is_critical: false,
        sample_index: 1,
        measured_value: '15',
        tolerance_min: 8,
        tolerance_max: 12,
      },
    ];
    const result = computeLotChecklistVerdict(checklist, numeric, 0, 2);
    expect(result.verdict).toBe('pass');
  });

  it('counts both sample defects and measurement defects toward accept limit', () => {
    const checklist: ChecklistItem[] = [];
    const numeric: NumericItem[] = [
      {
        is_critical: false,
        sample_index: 1,
        measured_value: '15',
        tolerance_min: 8,
        tolerance_max: 12,
      },
    ];
    const result = computeLotChecklistVerdict(checklist, numeric, 2, 1);
    expect(result.verdict).toBe('fail');
    expect(result.reason).toContain('Defects (2) exceed Ac (1)');
  });

  it('handles null measured values', () => {
    const checklist: ChecklistItem[] = [{ is_critical: false, is_pass: true }];
    const numeric: NumericItem[] = [
      {
        is_critical: false,
        sample_index: 1,
        measured_value: null,
        tolerance_min: 8,
        tolerance_max: 12,
      },
    ];
    const result = computeLotChecklistVerdict(checklist, numeric, 0, 2);
    expect(result.verdict).toBe('pass');
  });

  it('passes when all measured values are in tolerance', () => {
    const checklist: ChecklistItem[] = [{ is_critical: false, is_pass: true }];
    const numeric: NumericItem[] = [
      {
        is_critical: false,
        sample_index: 1,
        measured_value: '10',
        tolerance_min: 8,
        tolerance_max: 12,
      },
      {
        is_critical: false,
        sample_index: 2,
        measured_value: '9',
        tolerance_min: 8,
        tolerance_max: 12,
      },
      {
        is_critical: false,
        sample_index: 3,
        measured_value: '11',
        tolerance_min: 8,
        tolerance_max: 12,
      },
    ];
    const result = computeLotChecklistVerdict(checklist, numeric, 0, 2);
    expect(result.verdict).toBe('pass');
  });

  it('correctly evaluates tolerance boundaries', () => {
    const checklist: ChecklistItem[] = [];
    const numeric: NumericItem[] = [
      {
        is_critical: false,
        sample_index: 1,
        measured_value: '8',
        tolerance_min: 8,
        tolerance_max: 12,
      },
      {
        is_critical: false,
        sample_index: 2,
        measured_value: '12',
        tolerance_min: 8,
        tolerance_max: 12,
      },
    ];
    const result = computeLotChecklistVerdict(checklist, numeric, 0, 2);
    expect(result.verdict).toBe('pass');
  });

  it('counts one piece with multiple out-of-tolerance dimensions as one defect', () => {
    const checklist: ChecklistItem[] = [];
    const numeric: NumericItem[] = [
      {
        is_critical: false,
        sample_index: 1,
        measured_value: '15',
        tolerance_min: 8,
        tolerance_max: 12,
      },
      {
        is_critical: false,
        sample_index: 1,
        measured_value: '16',
        tolerance_min: 8,
        tolerance_max: 12,
      },
    ];
    const result = computeLotChecklistVerdict(checklist, numeric, 0, 1);
    expect(result.verdict).toBe('pass');
  });

  it('counts two different pieces each with one out-of-tolerance dimension as two defects', () => {
    const checklist: ChecklistItem[] = [];
    const numeric: NumericItem[] = [
      {
        is_critical: false,
        sample_index: 1,
        measured_value: '15',
        tolerance_min: 8,
        tolerance_max: 12,
      },
      {
        is_critical: false,
        sample_index: 2,
        measured_value: '16',
        tolerance_min: 8,
        tolerance_max: 12,
      },
    ];
    const result = computeLotChecklistVerdict(checklist, numeric, 0, 1);
    expect(result.verdict).toBe('fail');
    expect(result.reason).toContain('Defects (2) exceed Ac (1)');
  });
});
