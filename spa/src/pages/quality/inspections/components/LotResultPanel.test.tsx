import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { inspectionsApi } from '@/api/quality/inspections';
import { LotResultPanel } from './LotResultPanel';
import type { Inspection, InspectionMeasurement } from '@/types/quality';

/**
 * The capture panel records by ticking. A non-critical dimension is one tick
 * when it is within tolerance; a critical one keeps its per-piece measured
 * matrix. Unticking reveals the dimension's pieces, and each revealed piece
 * needs a reading or an explicit NG mark before the lot is submittable — a tick
 * is a claim the server stores, so an unanswered dimension must block submit
 * rather than reach `complete()` as an unresolved row.
 */
vi.mock('@/api/quality/inspections', () => ({
  inspectionsApi: { recordLotResult: vi.fn() },
}));

const recordLotResult = vi.mocked(inspectionsApi.recordLotResult);

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
  measurement({
    id: 'crit-1',
    sample_index: 1,
    parameter_name: 'Outer diameter',
    is_critical: true,
  }),
  measurement({
    id: 'crit-2',
    sample_index: 2,
    parameter_name: 'Outer diameter',
    is_critical: true,
  }),
];
const nonCriticalDimension = [
  measurement({ id: 'nc-1', sample_index: 1, parameter_name: 'Flash' }),
  measurement({ id: 'nc-2', sample_index: 2, parameter_name: 'Flash' }),
];
const secondNonCriticalDimension = [
  measurement({ id: 'nc2-1', sample_index: 1, parameter_name: 'Sink mark' }),
  measurement({ id: 'nc2-2', sample_index: 2, parameter_name: 'Sink mark' }),
];

function renderPanel(inspection: Inspection) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <LotResultPanel inspection={inspection} isTerminal={false} />
    </QueryClientProvider>,
  );
}

/** The per-piece readings a CTQ cannot be submitted without. */
function fillCriticalReadings(value = '10') {
  fireEvent.change(screen.getByLabelText('Outer diameter, piece 1 (mm)'), {
    target: { value },
  });
  fireEvent.change(screen.getByLabelText('Outer diameter, piece 2 (mm)'), {
    target: { value },
  });
}

const submitButton = () => screen.getByRole('button', { name: 'Submit result' });

beforeEach(() => {
  recordLotResult.mockReset();
  recordLotResult.mockResolvedValue({ status: 'awaiting_review' } as unknown as Inspection);
});

describe('LotResultPanel', () => {
  it('collapses a non-critical dimension to one tick and keeps a critical one measured per piece', () => {
    renderPanel(inspectionWith([...criticalDimension, ...nonCriticalDimension]));

    // The critical CTQ keeps its variable-data matrix.
    expect(screen.getByLabelText('Outer diameter, piece 1 (mm)')).toBeInTheDocument();
    expect(screen.getByLabelText('Outer diameter, piece 2 (mm)')).toBeInTheDocument();

    // The non-critical dimension is one tick, with no per-piece inputs behind it.
    const tick = screen.getByLabelText('Flash within tolerance') as HTMLInputElement;
    expect(tick.checked).toBe(false);
    expect(screen.queryByLabelText('Flash, piece 1 (mm)')).not.toBeInTheDocument();
  });

  it('renders a tick for every dimension when no dimension is critical', () => {
    renderPanel(inspectionWith([...nonCriticalDimension, ...secondNonCriticalDimension]));

    // Nothing is revealed, and every dimension still offers its answer.
    expect(screen.getByLabelText('Flash within tolerance')).toBeInTheDocument();
    expect(screen.getByLabelText('Sink mark within tolerance')).toBeInTheDocument();
    expect(screen.queryByLabelText('Flash, piece 1 (mm)')).not.toBeInTheDocument();
  });

  it('sends the tick as an explicit is_pass on the dimension it answers', async () => {
    renderPanel(inspectionWith([...criticalDimension, ...nonCriticalDimension]));

    fillCriticalReadings();
    fireEvent.click(screen.getByLabelText('Flash within tolerance'));
    fireEvent.click(screen.getByRole('button', { name: '0' }));
    fireEvent.click(submitButton());

    await waitFor(() => expect(recordLotResult).toHaveBeenCalledTimes(1));

    const [id, payload] = recordLotResult.mock.calls[0];
    expect(id).toBe('insp-1');
    expect(payload.complete).toBe(true);
    expect(payload.sample_defect_count).toBe(0);

    // A tick carries the claim, and no reading — the row would otherwise stay
    // unresolved and `complete()` would refuse the whole submission.
    expect(payload.measurements.filter((m) => m.id.startsWith('nc-'))).toEqual([
      { id: 'nc-1', measured_value: null, is_pass: true },
      { id: 'nc-2', measured_value: null, is_pass: true },
    ]);

    // A reading decides for itself, so no claim is sent beside it.
    expect(payload.measurements.filter((m) => m.id.startsWith('crit-'))).toEqual([
      { id: 'crit-1', measured_value: '10', is_pass: null },
      { id: 'crit-2', measured_value: '10', is_pass: null },
    ]);
  });

  it('keeps submit disabled and counts the rows an unanswered dimension leaves open', () => {
    renderPanel(inspectionWith([...criticalDimension, ...nonCriticalDimension]));

    fillCriticalReadings();
    fireEvent.click(screen.getByRole('button', { name: '0' }));

    // Nothing is claimed for Flash, so the preview must not read as a pass and
    // the lot must not be submittable: the server holds two unresolved rows.
    expect(screen.queryByText('Will pass')).not.toBeInTheDocument();
    expect(screen.getByText('2 measurements unanswered')).toBeInTheDocument();
    expect(screen.getByText(/Missing: 2 unanswered measurement/)).toBeInTheDocument();
    expect(submitButton()).toBeDisabled();
  });

  it('requires a reading or an NG mark on each revealed piece instead of inventing defects', () => {
    renderPanel(inspectionWith([...criticalDimension, ...nonCriticalDimension], 0));
    fillCriticalReadings();

    const tick = screen.getByLabelText('Flash within tolerance') as HTMLInputElement;
    fireEvent.click(tick);
    expect(tick.checked).toBe(true);
    fireEvent.click(tick);
    expect(tick.checked).toBe(false);

    // The pieces come back for a recorded answer...
    expect(screen.getByLabelText('Flash, piece 1 (mm)')).toBeInTheDocument();
    expect(screen.getByLabelText('Flash, piece 2 (mm)')).toBeInTheDocument();
    // ...and a blank piece is not a defect: it is an unanswered row.
    expect(screen.queryByText(/Will fail/)).not.toBeInTheDocument();
    expect(screen.getByText('2 measurements unanswered')).toBeInTheDocument();
    expect(submitButton()).toBeDisabled();

    fireEvent.click(screen.getByLabelText('Flash, piece 1 NG'));
    expect(screen.getByText('Will fail — Defects (1) exceed Ac (0)')).toBeInTheDocument();
    expect(screen.getByText(/1 unanswered measurement/)).toBeInTheDocument();
    expect(submitButton()).toBeDisabled();

    // Both pieces answered, and the sample counted: the failing lot submits.
    fireEvent.click(screen.getByLabelText('Flash, piece 2 NG'));
    fireEvent.click(screen.getByRole('button', { name: '0' }));
    expect(submitButton()).not.toBeDisabled();
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
