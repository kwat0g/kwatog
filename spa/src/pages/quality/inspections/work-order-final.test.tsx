import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { inspectionsApi } from '@/api/quality/inspections';
import type { Inspection } from '@/types/quality';
import type { WorkOrderFinalInspection } from '@/types/quality-final';
import WorkOrderFinalInspectionPage from './work-order-final';
import InspectionDetailPage from './detail';
import InspectionsListPage from './index';

vi.mock('@/api/quality/inspections', () => ({
  inspectionsApi: {
    list: vi.fn(),
    getWorkOrderFinalInspection: vi.fn(),
    saveWorkOrderFinalEvidence: vi.fn(),
    submitWorkOrderFinalInspection: vi.fn(),
    reviewWorkOrderFinalInspection: vi.fn(),
    replaceOutgoingEvidenceMethod: vi.fn(),
    show: vi.fn(),
    options: vi.fn().mockResolvedValue({ statuses: [], stages: [] }),
    chain: vi.fn().mockResolvedValue([]),
    generateCoC: vi.fn().mockResolvedValue(undefined),
  },
}));
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({ can: () => true }),
}));

const group = {
  work_order_id: 'wo-hash',
  wo_number: 'WO-202610-0042',
  produced_quantity: 4,
  defective_quantity: 1,
  accepted_quantity: 0,
  state: 'awaiting_review',
  manifest: [
    {
      output_id: 'out-a',
      inspection_id: 'qc-a',
      physical_quantity: 2,
      result_version: 3,
      specification_revision_id: 'rev',
    },
    {
      output_id: 'out-b',
      inspection_id: 'qc-b',
      physical_quantity: 2,
      result_version: 3,
      specification_revision_id: 'rev',
    },
  ],
  context: {
    product: { part_number: 'PC-001', name: 'Pivot Cap', unit_of_measure: 'PCS' },
    output_batches: [
      { inspection_id: 'qc-a', batch_code: 'BATCH-A' },
      { inspection_id: 'qc-b', batch_code: 'BATCH-B' },
    ],
    timezone: 'Asia/Manila',
  },
  batches: [
    {
      id: 'qc-a',
      inspection_number: 'QC-202610-0010',
      status: 'awaiting_review',
      batch_quantity: 2,
      result_version: 3,
      defective_quantity: 0,
      described_defective_quantity: 0,
      missing_readings: 0,
      counted_capture: {
        version: 3,
        workflow: 'finish_first_v1',
        evidence_method: 'confirmed_checks',
        required_quantity: 2,
        result_policy: 'zero_defects',
      },
      coverage_confirmed_at: '2026-10-08T08:30:00+08:00',
      inspector: { id: 'inspector', name: 'Mina Inspector' },
      can_review: true,
      measurements: [
        {
          id: 'req-length',
          parameter_name: 'Length',
          parameter_type: 'dimensional',
          nominal_value: '10',
          tolerance_min: '9',
          tolerance_max: '11',
          unit_of_measure: 'mm',
          is_critical: false,
        },
      ],
    },
    {
      id: 'qc-b',
      inspection_number: 'QC-202610-0011',
      status: 'awaiting_review',
      batch_quantity: 2,
      result_version: 3,
      defective_quantity: 1,
      described_defective_quantity: 1,
      missing_readings: 0,
      counted_capture: {
        version: 3,
        workflow: 'finish_first_v1',
        evidence_method: 'confirmed_checks',
        required_quantity: 2,
        result_policy: 'zero_defects',
      },
      coverage_confirmed_at: '2026-10-08T08:30:00+08:00',
      inspector: { id: 'inspector', name: 'Mina Inspector' },
      can_review: true,
      measurements: [
        {
          id: 'req-length',
          parameter_name: 'Length',
          parameter_type: 'dimensional',
          nominal_value: '10',
          tolerance_min: '9',
          tolerance_max: '11',
          unit_of_measure: 'mm',
          is_critical: false,
        },
      ],
      ncr: { id: 'ncr-hash', ncr_number: 'NCR-1' },
    },
  ],
  evidence_page: {
    inspection_id: 'qc-b',
    page: 1,
    per_page: 50,
    total_pieces: 2,
    pieces: [],
  },
  defect_page: {
    page: 1,
    per_page: 50,
    total: 1,
    pieces: [
      {
        piece_number: 2,
        problem_description: 'Surface scratch',
        requirement_id: 'req-length',
      },
    ],
  },
} as unknown as WorkOrderFinalInspection;

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(inspectionsApi.getWorkOrderFinalInspection).mockResolvedValue(group);
});

function page() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/quality/work-orders/wo-hash/final-inspection']}>
        <Routes>
          <Route
            path="/quality/work-orders/:id/final-inspection"
            element={<WorkOrderFinalInspectionPage />}
          />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

it('selects batch named by evidence page and uses its inspection number in the header', async () => {
  page();
  expect(await screen.findByText('QC-202610-0011', { exact: true })).toBeInTheDocument();
  expect(screen.getByRole('combobox', { name: 'Output batch' })).toHaveValue('qc-b');
});

it('shows saved confirmed-check and defect evidence in read-only state', async () => {
  page();
  expect(await screen.findByText('Surface scratch')).toBeInTheDocument();
  expect(screen.getByText(/Confirmed all listed requirements/)).toBeInTheDocument();
  expect(screen.getByText('Mina Inspector')).toBeInTheDocument();
  expect(screen.queryByRole('textbox', { name: 'Problem for piece 2' })).not.toBeInTheDocument();
  expect(screen.queryByRole('textbox', { name: 'Length · piece 1' })).not.toBeInTheDocument();
});

it('keeps version 2 linked to the numeric-reading method', async () => {
  vi.mocked(inspectionsApi.getWorkOrderFinalInspection).mockResolvedValue({
    ...group,
    evidence_page: { ...group.evidence_page, inspection_id: 'qc-a' },
    batches: group.batches.map((batch) => ({
      ...batch,
      status: 'draft',
      counted_capture: {
        version: 2,
        workflow: 'finish_first_v1',
        required_quantity: 2,
        result_policy: 'zero_defects',
      },
      missing_readings: 2,
    })),
  } as WorkOrderFinalInspection);
  page();
  expect(await screen.findByText('QC-202610-0010', { exact: true })).toBeInTheDocument();
  expect(screen.getByText(/required readings/)).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'PASS ALL' }));
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
});

it('routes active version 3 inspections from the generic URL to the shared record', async () => {
  vi.mocked(inspectionsApi.show).mockResolvedValue({
    id: 'qc-b',
    inspection_number: 'QC-202610-0011',
    stage: 'outgoing',
    status: 'draft',
    entity_type: 'work_order',
    entity_hash_id: 'wo-hash',
    final_work_order_id: 'wo-hash',
    counted_capture: {
      version: 3,
      workflow: 'finish_first_v1',
      evidence_method: 'confirmed_checks',
      required_quantity: 2,
      result_policy: 'zero_defects',
    },
  } as unknown as Inspection);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/quality/inspections/qc-b']}>
        <Routes>
          <Route path="/quality/inspections/:id" element={<InspectionDetailPage />} />
          <Route
            path="/quality/work-orders/:id/final-inspection"
            element={<div>Shared final inspection record</div>}
          />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
  expect(await screen.findByText('Shared final inspection record')).toBeInTheDocument();
});

it('keeps cancelled version 2 evidence on its historical inspection page', async () => {
  vi.mocked(inspectionsApi.show).mockResolvedValue({
    id: 'qc-a',
    inspection_number: 'QC-202610-0010',
    stage: 'outgoing',
    status: 'cancelled',
    entity_type: 'work_order',
    entity_hash_id: 'wo-hash',
    final_work_order_id: 'wo-hash',
    counted_capture: {
      version: 2,
      workflow: 'finish_first_v1',
      required_quantity: 2,
      result_policy: 'zero_defects',
    },
    measurements: [],
  } as unknown as Inspection);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/quality/inspections/qc-a']}>
        <Routes>
          <Route path="/quality/inspections/:id" element={<InspectionDetailPage />} />
          <Route
            path="/quality/work-orders/:id/final-inspection"
            element={<div>Shared final inspection record</div>}
          />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
  expect(await screen.findByText('QC-202610-0010')).toBeInTheDocument();
  expect(screen.queryByText('Shared final inspection record')).not.toBeInTheDocument();
});

it('requires an explicit reason before replacing an untouched version 2 draft', async () => {
  const untouched = {
    ...group,
    state: 'checking',
    can_replace_method: true,
    manifest: [group.manifest[0]],
    evidence_page: { ...group.evidence_page, inspection_id: 'qc-a' },
    batches: [
      {
        ...group.batches[0],
        status: 'draft',
        counted_capture: {
          version: 2,
          workflow: 'finish_first_v1',
          required_quantity: 2,
          result_policy: 'zero_defects',
        },
      },
    ],
  } as unknown as WorkOrderFinalInspection;
  const replacement = {
    ...untouched,
    can_replace_method: false,
    batches: [
      {
        ...untouched.batches[0],
        id: 'qc-v3',
        inspection_number: 'QC-202610-0020',
        counted_capture: {
          version: 3,
          workflow: 'finish_first_v1',
          evidence_method: 'confirmed_checks',
          required_quantity: 2,
          result_policy: 'zero_defects',
        },
      },
    ],
    manifest: [{ ...untouched.manifest[0], inspection_id: 'qc-v3' }],
    evidence_page: { ...untouched.evidence_page, inspection_id: 'qc-v3' },
  } as unknown as WorkOrderFinalInspection;
  vi.mocked(inspectionsApi.getWorkOrderFinalInspection).mockResolvedValue(untouched);
  vi.mocked(inspectionsApi.replaceOutgoingEvidenceMethod).mockResolvedValue(replacement);
  page();
  const open = await screen.findByRole('button', {
    name: 'Replace untouched draft with confirmed checks',
  });
  expect(inspectionsApi.replaceOutgoingEvidenceMethod).not.toHaveBeenCalled();
  fireEvent.click(open);
  fireEvent.change(screen.getByRole('textbox', { name: 'Reason for evidence method change' }), {
    target: { value: 'Approved confirmed-check rollout' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Confirm replacement' }));
  expect(inspectionsApi.replaceOutgoingEvidenceMethod).toHaveBeenCalledWith(
    'wo-hash',
    {
      manifest: untouched.manifest,
      reason: 'Approved confirmed-check rollout',
    },
  );
});

it('keeps the outgoing certificate action for a passed batch', async () => {
  vi.mocked(inspectionsApi.getWorkOrderFinalInspection).mockResolvedValue({
    ...group,
    state: 'released',
    batches: group.batches.map((batch) => ({
      ...batch,
      status: 'passed',
      stage: 'outgoing',
      can_review: false,
    })),
  });
  page();
  fireEvent.click(
    await screen.findByRole('button', { name: 'Certificate of Conformance' }),
  );
  expect(inspectionsApi.generateCoC).toHaveBeenCalledWith('qc-b');
});


it('distinguishes released WO totals from the original failed batch after repair', async () => {
  vi.mocked(inspectionsApi.getWorkOrderFinalInspection).mockResolvedValue({
    ...group,
    produced_quantity: 500,
    defective_quantity: 2,
    accepted_quantity: 500,
    repaired_accepted_quantity: 2,
    state: 'released',
    evidence_page: { ...group.evidence_page!, inspection_id: 'qc-a' },
    batches: group.batches.map((batch, index) => ({
      ...batch,
      status: index === 0 ? 'failed' : 'passed',
      stage: 'outgoing',
      batch_quantity: index === 0 ? 500 : 2,
      defective_quantity: index === 0 ? 2 : 0,
      accepted_quantity: index === 0 ? 498 : 2,
      can_review: false,
    })),
  });
  page();
  expect((await screen.findAllByText('Quality released')).length).toBeGreaterThan(0);
  expect(screen.getByText('Original batch result: Failed')).toBeInTheDocument();
  expect(screen.getByText('Passed initially')).toBeInTheDocument();
  expect(screen.getByText('Repaired · QC approved')).toBeInTheDocument();
  expect(screen.getByText('Originally defective')).toBeInTheDocument();
  expect(screen.getByText('500 pieces approved for release, including 2 repaired pieces.')).toBeInTheDocument();
  expect(screen.queryByText('Without reported defects')).not.toBeInTheDocument();
});


it('shows the current WO release and repair quantities in the grouped inspection list', async () => {
  vi.mocked(inspectionsApi.list).mockResolvedValue({
    data: [{
      ...group.batches[0],
      status: 'failed',
      stage: 'outgoing',
      work_order_quality: {
        state: 'released', produced_quantity: 500, defective_quantity: 2,
        accepted_quantity: 500, repaired_accepted_quantity: 2,
      },
    }],
    meta: { total: 1, current_page: 1, last_page: 1, per_page: 25 },
  } as unknown as Awaited<ReturnType<typeof inspectionsApi.list>>);
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    <MemoryRouter><InspectionsListPage /></MemoryRouter>
  </QueryClientProvider>);
  expect(await screen.findByText('Quality released')).toBeInTheDocument();
  expect(screen.getByText('Original QC failed')).toBeInTheDocument();
  expect(screen.getByText('500 approved · 2 repaired')).toBeInTheDocument();
});
