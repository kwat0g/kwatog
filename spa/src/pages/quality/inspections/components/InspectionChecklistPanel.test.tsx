import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { Toaster } from 'react-hot-toast';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { inspectionsApi } from '@/api/quality/inspections';
import type { Inspection } from '@/types/quality';
import { InspectionChecklistPanel } from './InspectionChecklistPanel';

const apiMocks = vi.hoisted(() => ({ recordMeasurements: vi.fn() }));

vi.mock('@/api/quality/inspections', () => ({
  inspectionsApi: {
    recordBatchResult: vi.fn(),
    recordItemResults: vi.fn(),
    recordMeasurements: apiMocks.recordMeasurements,
  },
}));

const inspection: Inspection = {
  id: 'inspection-1',
  inspection_number: 'QC-DEMO',
  stage: 'outgoing',
  status: 'draft',
  batch_quantity: 150,
  sample_size: 20,
  accepted_quantity: 0,
  aql_code: 'F',
  accept_count: 0,
  reject_count: 1,
  defect_count: 0,
  entity_type: null,
  entity_hash_id: null,
  notes: null,
  started_at: null,
  completed_at: null,
  created_at: '',
  updated_at: '',
  measurements: [
    {
      id: 'diameter-1',
      checklist_key: 'spec:1',
      sample_index: 1,
      parameter_name: 'Diameter',
      parameter_type: 'dimensional',
      unit_of_measure: 'mm',
      nominal_value: 10,
      tolerance_min: 9.9,
      tolerance_max: 10.1,
      measured_value: null,
      is_critical: true,
      is_pass: null,
      notes: null,
    },
    {
      id: 'diameter-2',
      checklist_key: 'spec:1',
      sample_index: 2,
      parameter_name: 'Diameter',
      parameter_type: 'dimensional',
      unit_of_measure: 'mm',
      nominal_value: 10,
      tolerance_min: 9.9,
      tolerance_max: 10.1,
      measured_value: null,
      is_critical: true,
      is_pass: null,
      notes: null,
    },
    {
      id: 'surface',
      checklist_key: 'spec:2',
      sample_index: 0,
      parameter_name: 'Surface finish',
      parameter_type: 'visual',
      unit_of_measure: null,
      nominal_value: null,
      tolerance_min: null,
      tolerance_max: null,
      measured_value: null,
      is_critical: false,
      is_pass: null,
      notes: null,
    },
  ],
};

function mount(record: Inspection = inspection) {
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MemoryRouter><InspectionChecklistPanel inspection={record} canManage /></MemoryRouter>
      <Toaster />
    </QueryClientProvider>,
  );
}

describe('InspectionChecklistPanel', () => {
  beforeEach(() => vi.clearAllMocks());

  it.each(['measurement', 'checklist'] as const)('blocks %s evidence until real startup pieces exist', (capture_method) => {
    mount({ ...inspection, capture_method, stage: 'in_process', startup_trial_required: true,
      startup_trial: { required_quantity: 20, recorded_quantity: 0, settled: false, captured_at: null, output_id: null } } as Inspection);
    expect(screen.getByText('Waiting for Production to record 20 trial pieces')).toBeInTheDocument();
    expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^PASS / })).not.toBeInTheDocument();
    expect(inspectionsApi.recordMeasurements).not.toHaveBeenCalled();
    expect(inspectionsApi.recordItemResults).not.toHaveBeenCalled();
  });

  it('offers one decision per unique requirement and submits only after every requirement is answered', async () => {
    apiMocks.recordMeasurements.mockResolvedValue({
      ...inspection,
      updated_at: '2026-10-04T00:00:00Z',
      measurements: inspection.measurements?.map((row) =>
        row.parameter_name === 'Diameter'
          ? { ...row, measured_value: 10, is_pass: true }
          : row,
      ),
    });
    vi.mocked(inspectionsApi.recordItemResults).mockResolvedValue({
      ...inspection,
      updated_at: '2026-10-04T00:00:01Z',
      status: 'awaiting_review',
      proposed_result: 'passed',
      measurements: inspection.measurements?.map((row) => ({ ...row, item_result: 'passed' })),
    });
    mount();

    expect(screen.getAllByRole('button', { name: /^PASS / })).toHaveLength(2);
    expect(screen.getAllByRole('button', { name: /^FAIL / })).toHaveLength(2);
    expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Diameter sample 1 (mm)' }), {
      target: { value: '10' },
    });
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Diameter sample 2 (mm)' }), {
      target: { value: '10' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save measurements' }));
    await screen.findByText('Measurements saved.');
    expect(apiMocks.recordMeasurements).toHaveBeenCalledWith('inspection-1', {
      measurements: [
        { id: 'diameter-1', measured_value: '10' },
        { id: 'diameter-2', measured_value: '10' },
      ],
    });
    fireEvent.click(screen.getByRole('button', { name: 'PASS Diameter' }));
    expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'PASS Surface finish' }));
    fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));

    await waitFor(() =>
      expect(inspectionsApi.recordItemResults).toHaveBeenCalledWith('inspection-1', {
        items: [
          { id: 'diameter-1', result: 'passed' },
          { id: 'surface', result: 'passed' },
        ],
        submit: true,
      }),
    );
    expect(await screen.findByText(/Awaiting checker review/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Submit inspection' })).not.toBeInTheDocument();
    expect(inspection.measurements?.every((row) => row.measured_value === null)).toBe(true);
  });

  it('requires actual sample coverage and uses the batch gate for lot-checklist submission', async () => {
    const lot = { ...inspection, inspection_mode: 'lot_checklist' as const };
    const submitted: Inspection = {
      ...lot,
      updated_at: '2026-10-04T00:00:02Z',
      status: 'awaiting_review',
      proposed_result: 'passed',
      inspected_quantity: 20,
      sample_defect_count: 0,
    };
    apiMocks.recordMeasurements.mockResolvedValue({
      ...lot,
      updated_at: '2026-10-04T00:00:00Z',
      measurements: lot.measurements?.map((row) =>
        row.parameter_name === 'Diameter'
          ? { ...row, measured_value: 10, is_pass: true }
          : row,
      ),
    });
    vi.mocked(inspectionsApi.recordItemResults).mockResolvedValue({ ...lot, status: 'in_progress' });
    vi.mocked(inspectionsApi.recordBatchResult).mockResolvedValue(submitted);
    mount(lot);

    const submit = screen.getByRole('button', { name: 'Submit result' });
    expect(submit).toBeDisabled();
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Inspected pieces' }), {
      target: { value: '20' },
    });
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Defective pieces found' }), {
      target: { value: '0' },
    });
    fireEvent.click(screen.getByRole('checkbox', { name: 'I confirm all required checks on the planned sample were completed' }));
    expect(submit).toBeDisabled();

    fireEvent.change(screen.getByRole('spinbutton', { name: 'Diameter, piece 1 (mm)' }), {
      target: { value: '10' },
    });
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Diameter, piece 2 (mm)' }), {
      target: { value: '10' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'PASS all remaining' }));
    expect(submit).toBeEnabled();
    fireEvent.click(submit);

    await waitFor(() =>
      expect(inspectionsApi.recordBatchResult).toHaveBeenCalledWith('inspection-1', {
        result: 'passed',
        coverage_confirmed: true,
        inspected_quantity: 20,
        sample_defect_count: 0,
        critical_defect: false,
        defects_removed_confirmed: false,
      }),
    );
    expect(apiMocks.recordMeasurements).toHaveBeenCalledWith('inspection-1', {
      measurements: [
        { id: 'diameter-1', measured_value: '10' },
        { id: 'diameter-2', measured_value: '10' },
      ],
    });
    expect(inspectionsApi.recordItemResults).toHaveBeenCalledWith('inspection-1', {
      items: [
        { id: 'diameter-1', result: 'passed' },
        { id: 'surface', result: 'passed' },
      ],
      submit: false,
    });
    expect(apiMocks.recordMeasurements.mock.invocationCallOrder[0]).toBeLessThan(
      vi.mocked(inspectionsApi.recordItemResults).mock.invocationCallOrder[0],
    );
    expect(vi.mocked(inspectionsApi.recordItemResults).mock.invocationCallOrder[0]).toBeLessThan(
      vi.mocked(inspectionsApi.recordBatchResult).mock.invocationCallOrder[0],
    );
    expect(await screen.findByText('Awaiting checker review · proposed passed')).toBeInTheDocument();
  });

  it('blocks PASS when an unsaved lot-checklist reading is outside tolerance', () => {
    mount({ ...inspection, inspection_mode: 'lot_checklist' });

    fireEvent.change(screen.getByRole('spinbutton', { name: 'Diameter, piece 1 (mm)' }), {
      target: { value: '10.2' },
    });
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Diameter, piece 2 (mm)' }), {
      target: { value: '10.2' },
    });

    expect(screen.getByRole('button', { name: 'PASS Diameter' })).toBeDisabled();
  });

  it('requires a problem description for every failed requirement', async () => {
    vi.mocked(inspectionsApi.recordItemResults).mockResolvedValue({
      ...inspection,
      status: 'awaiting_review',
      proposed_result: 'failed',
    });
    mount();
    fireEvent.click(screen.getByRole('button', { name: 'FAIL Diameter' }));
    fireEvent.click(screen.getByRole('button', { name: 'PASS Surface finish' }));

    expect(screen.getByRole('textbox', { name: 'Problem found for Diameter' })).toBeRequired();
    expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
    fireEvent.change(screen.getByRole('textbox', { name: 'Problem found for Diameter' }), {
      target: { value: 'Oversized piece' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));

    await waitFor(() =>
      expect(inspectionsApi.recordItemResults).toHaveBeenCalledWith('inspection-1', {
        items: [
          { id: 'diameter-1', result: 'failed', problem_description: 'Oversized piece' },
          { id: 'surface', result: 'passed' },
        ],
        submit: true,
      }),
    );
  });

  it('saves partial decisions as drafts and leaves submission disabled', async () => {
    vi.mocked(inspectionsApi.recordItemResults).mockResolvedValue({
      ...inspection,
      status: 'in_progress',
      measurements: inspection.measurements?.map((row) =>
        row.id === 'diameter-1' ? { ...row, item_result: 'passed' } : row,
      ),
    });
    mount();
    fireEvent.click(screen.getByRole('button', { name: 'FAIL Diameter' }));
    fireEvent.change(screen.getByRole('textbox', { name: 'Problem found for Diameter' }), {
      target: { value: 'Out-of-spec housing' },
    });
    expect(screen.getByRole('button', { name: 'Save draft' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));

    await waitFor(() =>
      expect(inspectionsApi.recordItemResults).toHaveBeenCalledWith('inspection-1', {
        items: [{ id: 'diameter-1', result: 'failed', problem_description: 'Out-of-spec housing' }],
        submit: false,
      }),
    );
    expect(await screen.findByText('Checklist draft saved.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
  });

  it('blocks PASS when saved measurement evidence failed', () => {
    mount({ ...inspection, measurements: [{ ...inspection.measurements![0], is_pass: false }] });

    expect(screen.getByRole('button', { name: 'PASS Diameter' })).toBeDisabled();
    expect(screen.getByRole('alert')).toHaveTextContent(/saved measurement evidence.*failed/i);
    expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
  });

  it('requires numeric readings for dimensional checks even without a critical flag', () => {
    mount({ ...inspection, measurements: inspection.measurements?.map((row) => ({ ...row, is_critical: false })) });
    expect(screen.getByRole('spinbutton', { name: 'Diameter sample 1 (mm)' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'PASS Diameter' })).toBeDisabled();
  });

  it('requires saved measurements for every sample of a critical dimension before PASS', async () => {
    apiMocks.recordMeasurements.mockResolvedValue({
      ...inspection,
      updated_at: '2026-10-04T00:00:00Z',
      measurements: inspection.measurements?.map((row) =>
        row.parameter_name === 'Diameter'
          ? { ...row, measured_value: 10, is_pass: true }
          : row,
      ),
    });
    mount();

    expect(screen.getByRole('spinbutton', { name: 'Diameter sample 1 (mm)' })).toHaveValue(null);
    expect(screen.getByRole('spinbutton', { name: 'Diameter sample 2 (mm)' })).toHaveValue(null);
    expect(screen.getByRole('button', { name: 'PASS Diameter' })).toBeDisabled();
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Diameter sample 1 (mm)' }), {
      target: { value: '10' },
    });
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Diameter sample 2 (mm)' }), {
      target: { value: '10' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save measurements' }));

    await waitFor(() =>
      expect(apiMocks.recordMeasurements).toHaveBeenCalledWith('inspection-1', {
        measurements: [
          { id: 'diameter-1', measured_value: '10' },
          { id: 'diameter-2', measured_value: '10' },
        ],
      }),
    );
    expect(await screen.findAllByText('Measurements saved.')).not.toHaveLength(0);
    expect(screen.getByRole('button', { name: 'PASS Diameter' })).toBeEnabled();
  });

  it('preserves problem input and shows the server error after a failed save', async () => {
    vi.mocked(inspectionsApi.recordItemResults).mockRejectedValue({
      response: { data: { message: 'Inspection specification is no longer approved.' } },
    });
    mount();
    fireEvent.click(screen.getByRole('button', { name: 'FAIL Diameter' }));
    fireEvent.click(screen.getByRole('button', { name: 'PASS Surface finish' }));
    fireEvent.change(screen.getByRole('textbox', { name: 'Problem found for Diameter' }), {
      target: { value: 'Cracked housing' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Inspection specification is no longer approved.',
    );
    expect(screen.getByRole('textbox', { name: 'Problem found for Diameter' })).toHaveValue(
      'Cracked housing',
    );
  });

  it('shows only the approved release quantity after outgoing sorting', () => {
    mount({
      ...inspection,
      status: 'failed',
      batch_quantity: 100,
      accepted_quantity: 99,
      inspected_quantity: 20,
      sample_defect_count: 1,
      sorting_approved_at: '2026-10-03T12:00:00Z',
    });

    expect(screen.getByText('FAIL retained · sorting approved')).toBeInTheDocument();
    expect(screen.getByText('Approved release quantity').nextElementSibling).toHaveTextContent('99');
    expect(screen.getByText('Confirmed rejected pieces').nextElementSibling).toHaveTextContent('1');
    expect(screen.queryByText('Batch held for NCR sorting or rework, not scrap.')).not.toBeInTheDocument();
  });

  it('labels missing legacy sample counts and a held batch without implying scrap', () => {
    mount({ ...inspection, status: 'failed', inspected_quantity: null, sample_defect_count: null });

    expect(screen.getAllByText('Not recorded')).toHaveLength(2);
    expect(screen.getByText(/sorting or rework.*not scrap/i)).toBeInTheDocument();
    expect(screen.getByText('Held batch')).toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  });

  it('searches large checklists and PASS all remaining includes hidden unanswered requirements', async () => {
    const large = {
      ...inspection,
      measurements: Array.from({ length: 30 }, (_, index) => ({
        ...inspection.measurements![0],
        id: `item-${index}`,
        checklist_key: `check:${index}`,
        parameter_name: `Check ${index + 1}`,
        parameter_type: 'visual' as const,
        unit_of_measure: null,
        tolerance_min: null,
        tolerance_max: null,
        is_critical: false,
      })),
    };
    vi.mocked(inspectionsApi.recordItemResults).mockResolvedValue({
      ...large,
      status: 'awaiting_review',
      proposed_result: 'passed',
    });
    mount(large);

    fireEvent.click(screen.getByRole('button', { name: 'PASS Check 1' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search requirements' }), {
      target: { value: 'Check 30' },
    });
    fireEvent.click(screen.getByRole('checkbox', { name: 'Needs attention' }));
    expect(screen.queryByText('Check 1')).not.toBeInTheDocument();
    expect(screen.getByText('Check 30')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'PASS all remaining' }));
    fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));

    await waitFor(() => expect(inspectionsApi.recordItemResults).toHaveBeenCalled());
    const submitted = vi.mocked(inspectionsApi.recordItemResults).mock.calls[0][1];
    expect(submitted.submit).toBe(true);
    expect(submitted.items).toHaveLength(30);
    expect(submitted.items[0]).toEqual({ id: 'item-0', result: 'passed' });
    expect(submitted.items[29]).toEqual({ id: 'item-29', result: 'passed' });
  });
});
