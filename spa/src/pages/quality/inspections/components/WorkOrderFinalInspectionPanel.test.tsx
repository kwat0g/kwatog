import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { inspectionsApi } from '@/api/quality/inspections';
import { WorkOrderFinalInspectionPanel } from './WorkOrderFinalInspectionPanel';
import type { WorkOrderFinalInspection } from '@/types/quality';

vi.mock('@/api/quality/inspections', () => ({
  inspectionsApi: {
    getWorkOrderFinalInspection: vi.fn(),
    saveWorkOrderFinalEvidence: vi.fn(),
    submitWorkOrderFinalInspection: vi.fn(),
    reviewWorkOrderFinalInspection: vi.fn(),
  },
}));
const group = {
  work_order_id: 'wo',
  wo_number: 'WO-200',
  produced_quantity: 200,
  defective_quantity: 0,
  accepted_quantity: 0,
  state: 'checking',
  manifest: [
    {
      output_id: 'out',
      inspection_id: 'qc',
      physical_quantity: 200,
      result_version: 0,
      specification_revision_id: 'rev',
    },
  ],
  batches: [
    {
      id: 'qc',
      inspection_number: 'QC-200',
      status: 'draft',
      batch_quantity: 200,
      result_version: 0,
      sample_defect_count: 0,
      described_defective_quantity: 0,
      missing_readings: 0,
      counted_capture: { version: 2, required_quantity: 200, result_policy: 'zero_defects' },
      measurements: [],
    },
  ],
  evidence_page: { inspection_id: 'qc', page: 1, per_page: 50, total_pieces: 200, pieces: [] },
} as unknown as WorkOrderFinalInspection;
beforeEach(() => {
  vi.clearAllMocks();
  sessionStorage.clear();
  vi.mocked(inspectionsApi.getWorkOrderFinalInspection).mockResolvedValue(group);
  vi.mocked(inspectionsApi.saveWorkOrderFinalEvidence).mockResolvedValue(group);
  vi.mocked(inspectionsApi.submitWorkOrderFinalInspection).mockResolvedValue({
    ...group,
    state: 'awaiting_review',
  });
});
function panel(data = group) {
  return render(
    <QueryClientProvider
      client={
        new QueryClient({
          defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
        })
      }
    >
      <MemoryRouter>
        <WorkOrderFinalInspectionPanel group={data} canManage canReview />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}
it('PASS ALL still requires explicit physical coverage before submission', async () => {
  panel();
  const passAll = screen.getByRole('button', { name: 'PASS ALL' });
  expect(passAll).toHaveAttribute('aria-pressed', 'false');
  fireEvent.click(passAll);
  expect(passAll).toHaveAttribute('aria-pressed', 'true');
  expect(passAll).toHaveClass('bg-success-bg', 'text-success-fg');
  expect(screen.getByRole('checkbox', { name: 'I checked all 200 pieces' })).not.toBeChecked();
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
  fireEvent.click(screen.getByRole('checkbox', { name: 'I checked all 200 pieces' }));
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await waitFor(() =>
    expect(inspectionsApi.submitWorkOrderFinalInspection).toHaveBeenCalledWith(
      'wo',
      expect.objectContaining({
        manifest: group.manifest,
        batches: [{ inspection_id: 'qc', coverage_confirmed: true, inspected_quantity: 200 }],
      }),
    ),
  );
});
it('clears the PASS ALL selected state when the defective count changes', () => {
  panel();
  const passAll = screen.getByRole('button', { name: 'PASS ALL' });
  fireEvent.click(passAll);
  fireEvent.change(screen.getByRole('spinbutton', { name: 'Defective pieces' }), { target: { value: '1' } });
  expect(passAll).toHaveAttribute('aria-pressed', 'false');
  expect(passAll).not.toHaveClass('bg-success-bg');
});
it('opens exact defect descriptions without per-piece PASS/FAIL controls', () => {
  panel();
  fireEvent.change(screen.getByRole('spinbutton', { name: 'Defective pieces' }), {
    target: { value: '5' },
  });
  expect(screen.getAllByRole('textbox', { name: /Problem for piece/ })).toHaveLength(5);
  expect(screen.queryByRole('button', { name: 'FAIL' })).not.toBeInTheDocument();
});
it('bounds large defective batches to 50 description rows', () => {
  panel();
  fireEvent.change(screen.getByRole('spinbutton', { name: 'Defective pieces' }), {
    target: { value: '200' },
  });
  expect(screen.getAllByRole('textbox', { name: /Problem for piece/ })).toHaveLength(50);
});
it('missing required size readings cannot be bypassed by PASS ALL', () => {
  panel({ ...group, batches: [{ ...group.batches[0], missing_readings: 1 }] });
  fireEvent.click(screen.getByRole('button', { name: 'PASS ALL' }));
  fireEvent.click(screen.getByRole('checkbox', { name: 'I checked all 200 pieces' }));
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
});
it('unknown submission outcome locks fresh submission and retains reconciliation', async () => {
  vi.mocked(inspectionsApi.submitWorkOrderFinalInspection).mockRejectedValue(
    new Error('Connection lost'),
  );
  panel();
  fireEvent.click(screen.getByRole('button', { name: 'PASS ALL' }));
  fireEvent.click(screen.getByRole('checkbox', { name: 'I checked all 200 pieces' }));
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await screen.findByRole('button', { name: 'Check saved result' });
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
  expect(inspectionsApi.submitWorkOrderFinalInspection).toHaveBeenCalledTimes(1);
});

it('changing a problem removes physical coverage confirmation', () => {
  panel();
  fireEvent.change(screen.getByRole('spinbutton', { name: 'Defective pieces' }), {
    target: { value: '1' },
  });
  fireEvent.click(screen.getByRole('checkbox', { name: 'I checked all 200 pieces' }));
  fireEvent.change(screen.getByRole('textbox', { name: 'Problem for piece 1' }), {
    target: { value: 'Scratch' },
  });
  expect(screen.getByRole('checkbox', { name: 'I checked all 200 pieces' })).not.toBeChecked();
});
it('an evidence author cannot see the independent approval action', () => {
  panel({
    ...group,
    state: 'awaiting_review',
    batches: [{ ...group.batches[0], status: 'awaiting_review', can_review: false }],
  });
  expect(
    screen.queryByRole('button', { name: 'Approve inspection results' }),
  ).not.toBeInTheDocument();
});
it('saving a second problem page preserves that page and submitted piece identities', async () => {
  const first = {
    ...group,
    batches: [{ ...group.batches[0], defective_quantity: 60 }],
    defect_page: {
      page: 1,
      per_page: 50,
      total: 60,
      pieces: Array.from({ length: 50 }, (_, i) => ({
        piece_number: i + 1,
        problem_description: 'Scratch',
      })),
    },
  };
  const second = {
    ...first,
    defect_page: {
      ...first.defect_page,
      page: 2,
      pieces: Array.from({ length: 10 }, (_, i) => ({
        piece_number: i + 51,
        problem_description: 'Dent',
      })),
    },
  };
  vi.mocked(inspectionsApi.getWorkOrderFinalInspection).mockResolvedValue(second);
  vi.mocked(inspectionsApi.saveWorkOrderFinalEvidence).mockResolvedValue(second);
  panel(first);
  fireEvent.click(screen.getByRole('button', { name: 'Next problems' }));
  await screen.findByRole('textbox', { name: 'Problem for piece 51' });
  fireEvent.change(screen.getByRole('textbox', { name: 'Problem for piece 51' }), {
    target: { value: 'Crack' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Save problems' }));
  await waitFor(() =>
    expect(inspectionsApi.saveWorkOrderFinalEvidence).toHaveBeenCalledWith(
      'wo',
      expect.objectContaining({
        defect_page: 2,
        pieces: expect.arrayContaining([{ piece_number: 51, problem_description: 'Crack' }]),
      }),
    ),
  );
  expect(screen.queryByRole('textbox', { name: 'Problem for piece 1' })).not.toBeInTheDocument();
});

it('reducing saved defect quantity offers clearing hidden descriptions', async () => {
  panel({
    ...group,
    batches: [{ ...group.batches[0], defective_quantity: 60, described_defective_quantity: 60 }],
  });
  fireEvent.change(screen.getByRole('spinbutton', { name: 'Defective pieces' }), {
    target: { value: '5' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Clear saved problems' }));
  expect(screen.getAllByRole('textbox', { name: /Problem for piece/ })).toHaveLength(5);
  fireEvent.click(screen.getByText('Apply same problem to several pieces'));
  fireEvent.change(screen.getByRole('textbox', { name: 'Shared problem' }), {
    target: { value: 'Scratch' },
  });
  fireEvent.click(screen.getByRole('checkbox', { name: 'Select all problems on this page' }));
  fireEvent.click(screen.getByRole('button', { name: 'Apply to selected pieces' }));
  fireEvent.click(screen.getByRole('button', { name: 'Save problems' }));
  await waitFor(() =>
    expect(inspectionsApi.saveWorkOrderFinalEvidence).toHaveBeenCalledWith(
      'wo',
      expect.objectContaining({ defective_quantity: 5, reset_defects: true }),
    ),
  );
});
it('reloads authoritative versions after an unknown save while retaining typed problems', async () => {
  vi.mocked(inspectionsApi.saveWorkOrderFinalEvidence)
    .mockRejectedValueOnce(new Error('Connection lost'))
    .mockResolvedValue(group);
  vi.mocked(inspectionsApi.getWorkOrderFinalInspection).mockResolvedValue({
    ...group,
    manifest: [{ ...group.manifest[0], result_version: 1 }],
    batches: [{ ...group.batches[0], result_version: 1 }],
  });
  panel();
  fireEvent.change(screen.getByRole('spinbutton', { name: 'Defective pieces' }), {
    target: { value: '1' },
  });
  fireEvent.change(screen.getByRole('textbox', { name: 'Problem for piece 1' }), {
    target: { value: 'Dent' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Save problems' }));
  fireEvent.click(await screen.findByRole('button', { name: 'Reload saved totals' }));
  await screen.findByText(/Saved totals refreshed/);
  expect(screen.getByRole('textbox', { name: 'Problem for piece 1' })).toHaveValue('Dent');
  fireEvent.click(screen.getByRole('button', { name: 'Save problems' }));
  await waitFor(() =>
    expect(inspectionsApi.saveWorkOrderFinalEvidence).toHaveBeenLastCalledWith(
      'wo',
      expect.objectContaining({ expected_result_version: 1 }),
    ),
  );
});

it('uses the existing record layout and explains full-piece measurement coverage', () => {
  const measurements = ['Length', 'Width', 'Height', 'Diameter'].map((name, index) => ({
    id: `size-${index}`,
    parameter_name: name,
    parameter_type: 'dimensional',
    evaluation_mode: 'numeric',
    tolerance_min: '1',
    tolerance_max: '2',
    unit_of_measure: 'mm',
  }));
  panel({
    ...group,
    produced_quantity: 500,
    context: {
      product: { part_number: 'PC-001', name: 'Pivot Cap Cover Type A', unit_of_measure: 'PCS' },
      sales_order: {
        id: 'so-hash',
        so_number: 'SO-202610-0025',
        requested_delivery_date: '2026-10-20',
        current_delivery_date: '2026-10-20',
      },
      batch_number: 'BATCH-202610-0001',
      machine: { code: 'IMM-03', name: 'Sumitomo SE130' },
      mold: { code: 'M-PC-002', name: 'Backup mold' },
      production_started_at: '2026-10-09T06:00:00+08:00',
      production_finished_at: '2026-10-09T08:00:00+08:00',
      production_due_at: '2026-10-19T00:00:00+08:00',
      timezone: 'Asia/Manila',
      output_batches: [{ inspection_id: 'qc', batch_code: 'WO-500-B01' }],
    },
    batches: [
      {
        ...group.batches[0],
        batch_quantity: 500,
        missing_readings: 2000,
        measurements,
      } as unknown as WorkOrderFinalInspection['batches'][number],
    ],
  });
  for (const name of [
    'Record flow',
    'Inspection details',
    'Inspection checklist',
    'Linked records',
    'Inspection history',
  ]) {
    expect(screen.getByRole('heading', { name })).toBeInTheDocument();
  }
  expect(screen.getByText('SO-202610-0025', { exact: true })).toBeInTheDocument();
  expect(
    screen.getByText(
      '4 size checks × 500 pieces = 2,000 required readings. Enter actual measurements; PASS/FAIL is calculated.',
    ),
  ).toBeVisible();
  expect(screen.getByRole('textbox', { name: 'Length · piece 1' })).toBeVisible();
  expect(screen.queryByRole('textbox', { name: 'Length · piece 51' })).not.toBeInTheDocument();
  fireEvent.click(screen.getByText('Order and production details'));
  expect(screen.getByText('Oct 9, 2026, 8:00 AM')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'PASS ALL' }));
  fireEvent.click(screen.getByRole('checkbox', { name: 'I checked all 500 pieces' }));
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
});

it('uses confirmed checks without inventing measurements for version 3', async () => {
  const requirements = [
    ...['Length', 'Width', 'Height', 'Diameter'].map((parameter_name, index) => ({
      id: `size-${index}`,
      parameter_name,
      parameter_type: 'dimensional',
      evaluation_mode: 'numeric',
      nominal_value: '10',
      tolerance_min: '9',
      tolerance_max: '11',
      unit_of_measure: 'mm',
      is_critical: false,
    })),
    {
      id: 'surface',
      parameter_name: 'Surface finish',
      parameter_type: 'visual',
      evaluation_mode: 'manual',
      acceptance_criterion: 'No cracks or exposed fibers',
      is_critical: true,
    },
  ];
  const confirmedGroup = {
    ...group,
    produced_quantity: 500,
    batches: [
      {
        ...group.batches[0],
        batch_quantity: 500,
        missing_readings: 0,
        counted_capture: {
          version: 3,
          workflow: 'finish_first_v1',
          evidence_method: 'confirmed_checks',
          required_quantity: 500,
          result_policy: 'zero_defects',
        },
        measurements: requirements,
      },
    ],
  } as unknown as WorkOrderFinalInspection;

  panel(confirmedGroup);
  expect(screen.getByText(/I checked every piece against all listed requirements/)).toBeInTheDocument();
  expect(screen.getByText('No cracks or exposed fibers')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'PASS ALL' }));
  expect(screen.queryByRole('textbox', { name: 'Length · piece 1' })).not.toBeInTheDocument();
  expect(
    screen.getByRole('button', { name: 'Submit inspection' }),
  ).toBeDisabled();
  fireEvent.click(
    screen.getByRole('checkbox', { name: /I checked every piece against all listed requirements/ }),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await waitFor(() =>
    expect(inspectionsApi.submitWorkOrderFinalInspection).toHaveBeenCalledWith(
      'wo',
      expect.objectContaining({
        batches: [
          {
            inspection_id: 'qc',
            coverage_confirmed: true,
            inspected_quantity: 500,
            checks_confirmed: true,
          },
        ],
      }),
    ),
  );
});

it('requires a primary requirement for every version 3 defect and saves its hash reference', async () => {
  const confirmedGroup = {
    ...group,
    batches: [
      {
        ...group.batches[0],
        counted_capture: {
          version: 3,
          workflow: 'finish_first_v1',
          evidence_method: 'confirmed_checks',
          required_quantity: 200,
          result_policy: 'zero_defects',
        },
        measurements: [
          {
            id: 'size-hash',
            parameter_name: 'Length',
            parameter_type: 'dimensional',
            tolerance_min: '9',
            tolerance_max: '11',
            unit_of_measure: 'mm',
            is_critical: false,
          },
        ],
      },
    ],
  } as unknown as WorkOrderFinalInspection;
  vi.mocked(inspectionsApi.saveWorkOrderFinalEvidence).mockResolvedValue({
    ...confirmedGroup,
    defect_page: {
      page: 1,
      per_page: 50,
      total: 1,
      pieces: [{ piece_number: 1, problem_description: 'Scratch', requirement_id: 'size-hash' }],
    },
  } as unknown as WorkOrderFinalInspection);
  panel(confirmedGroup);
  fireEvent.change(screen.getByRole('spinbutton', { name: 'Defective pieces' }), {
    target: { value: '1' },
  });
  fireEvent.change(screen.getByRole('textbox', { name: 'Problem for piece 1' }), {
    target: { value: 'Scratch' },
  });
  fireEvent.change(screen.getByRole('combobox', { name: 'Failed check for piece 1' }), {
    target: { value: 'size-hash' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
  await waitFor(() =>
    expect(inspectionsApi.saveWorkOrderFinalEvidence).toHaveBeenCalledWith(
      'wo',
      expect.objectContaining({
        pieces: [
          {
            piece_number: 1,
            problem_description: 'Scratch',
            requirement_id: 'size-hash',
          },
        ],
      }),
    ),
  );
});

it('applies a shared problem only to selected pieces and clears inspection confirmation', () => {
  panel({
    ...group,
    batches: [{
      ...group.batches[0],
      counted_capture: { version: 3, evidence_method: 'confirmed_checks' },
      measurements: [{ id: 'surface', parameter_name: 'Surface finish', parameter_type: 'visual' }],
    }],
  } as unknown as WorkOrderFinalInspection);
  fireEvent.change(screen.getByRole('spinbutton', { name: 'Defective pieces' }), {
    target: { value: '2' },
  });
  fireEvent.click(screen.getByText('Apply same problem to several pieces'));
  fireEvent.change(screen.getByRole('textbox', { name: 'Shared problem' }), {
    target: { value: 'Scratched edge' },
  });
  fireEvent.change(screen.getByRole('combobox', { name: 'Shared failed check' }), {
    target: { value: 'surface' },
  });
  fireEvent.click(screen.getByRole('checkbox', { name: 'Select problem 2' }));
  const confirmation = screen.getByRole('checkbox', {
    name: /I checked every piece against all listed requirements/,
  });
  fireEvent.click(confirmation);
  fireEvent.click(screen.getByRole('button', { name: 'Apply to selected pieces' }));
  expect(screen.getByRole('textbox', { name: 'Problem for piece 1' })).toHaveValue('');
  expect(screen.getByRole('combobox', { name: 'Failed check for piece 1' })).toHaveValue('');
  expect(screen.getByRole('textbox', { name: 'Problem for piece 2' })).toHaveValue('Scratched edge');
  expect(screen.getByRole('combobox', { name: 'Failed check for piece 2' })).toHaveValue('surface');
  expect(confirmation).not.toBeChecked();
  expect(screen.getByRole('button', { name: 'Save draft' })).toBeDisabled();
});
