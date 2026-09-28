import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { LotResultPanel } from './LotResultPanel';
import type { Inspection, InspectionMeasurement } from '@/types/quality';

/**
 * Task 7 — the capture panel records by ticking. A non-critical dimension is one
 * tick when it is within tolerance; only a critical one keeps the per-piece
 * measured matrix, and only an explicit untick may declare a dimension out.
 */
vi.mock('@/api/quality/inspections', () => ({
  inspectionsApi: { recordLotResult: vi.fn() },
}));

function measurement(
  overrides: Partial<InspectionMeasurement> & { id: string; sample_index: number },
): InspectionMeasurement {
  return {
    parameter_name: 'Outer diameter',
    parameter_type: 'dimensional',
    parameter_type_label: 'Dimensional',
    evaluation_mode: 'numeric',
    unit_of_measure: 'mm',
    nominal_value: 10,
    tolerance_min: 9.95,
    tolerance_max: 10.05,
    measured_value: null,
    is_critical: false,
    is_pass: null,
    notes: null,
    ...overrides,
  } as InspectionMeasurement;
}

function inspectionWith(measurements: InspectionMeasurement[], rejectCount = 1): Inspection {
  return {
    id: 'insp-1',
    inspection_number: 'QC-202609-0001',
    stage: 'outgoing',
    status: 'in_progress',
    inspection_mode: 'lot_checklist',
    batch_quantity: 150,
    sample_size: 3,
    aql_code: 'F',
    accept_count: 0,
    reject_count: rejectCount,
    defect_count: 0,
    sample_defect_count: null,
    measurements,
  } as unknown as Inspection;
}

const criticalDimension = [
  measurement({ id: 'crit-1', sample_index: 1, parameter_name: 'Outer diameter', is_critical: true }),
  measurement({ id: 'crit-2', sample_index: 2, parameter_name: 'Outer diameter', is_critical: true }),
];
const nonCriticalDimension = [
  measurement({ id: 'nc-1', sample_index: 1, parameter_name: 'Flash' }),
  measurement({ id: 'nc-2', sample_index: 2, parameter_name: 'Flash' }),
];

function renderPanel(inspection: Inspection) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <LotResultPanel inspection={inspection} isTerminal={false} />
    </QueryClientProvider>,
  );
}

describe('LotResultPanel', () => {
  it('collapses a non-critical dimension to one tick and keeps a critical one measured per piece', () => {
    renderPanel(inspectionWith([...criticalDimension, ...nonCriticalDimension]));

    // The critical CTQ keeps its variable-data matrix.
    expect(screen.getByLabelText('Outer diameter, piece 1 (mm)')).toBeInTheDocument();
    expect(screen.getByLabelText('Outer diameter, piece 2 (mm)')).toBeInTheDocument();

    // The non-critical dimension is one tick, with no per-piece inputs behind it.
    const tick = screen.getByLabelText('Within tolerance') as HTMLInputElement;
    expect(tick.checked).toBe(false);
    expect(screen.queryByLabelText('Flash, piece 1 (mm)')).not.toBeInTheDocument();
  });

  it('reveals the piece rows when the tick is undone, and the preview then fails', () => {
    renderPanel(inspectionWith([...criticalDimension, ...nonCriticalDimension]));

    const tick = screen.getByLabelText('Within tolerance') as HTMLInputElement;
    fireEvent.click(tick);
    expect(tick.checked).toBe(true);

    // Unticking must not leave the dimension silently passing: the pieces come back
    // for a recorded value, and the preview already counts it as out.
    fireEvent.click(tick);
    expect(tick.checked).toBe(false);
    expect(screen.getByLabelText('Flash, piece 1 (mm)')).toBeInTheDocument();
    expect(screen.getByText('Will fail — Defects (2) exceed Ac (0)')).toBeInTheDocument();
  });

  it('records the sample defect count by tapping, with the number field removed', () => {
    renderPanel(inspectionWith([...criticalDimension, ...nonCriticalDimension], 3));

    expect(
      screen.queryByLabelText('Defective pieces found', { selector: 'input' }),
    ).not.toBeInTheDocument();

    const two = screen.getByRole('button', { name: '2' });
    expect(two).toHaveAttribute('aria-pressed', 'false');
    fireEvent.click(two);
    expect(two).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByText('Will fail — Defects (2) exceed Ac (0)')).toBeInTheDocument();
  });

  it('falls back to a typed count when the reject limit is past the tap row', () => {
    renderPanel(inspectionWith([...criticalDimension, ...nonCriticalDimension], 8));

    expect(screen.queryByRole('button', { name: '2' })).not.toBeInTheDocument();
    expect(
      screen.getByLabelText('Defective pieces found', { selector: 'input' }),
    ).toBeInTheDocument();
  });
});
