import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import toast, { Toaster } from 'react-hot-toast';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import InspectionDetailPage from './detail';
import { inspectionsApi } from '@/api/quality/inspections';
import { analyticsApi } from '@/api/quality/analytics';
import { inspectionQueryKeys } from '@/lib/queryKeys/inspections';
import type { Inspection, InspectionMeasurement } from '@/types/quality';

const navigation = vi.hoisted(() => ({ navigate: vi.fn() }));

const permissionState = vi.hoisted(() => ({ can: vi.fn((_permission: string) => true) }));
const defectEvidenceMocks = vi.hoisted(() => ({ list: vi.fn(), create: vi.fn() }));

vi.mock('@/api/quality/inspections', () => ({
  inspectionsApi: {
    show: vi.fn(),
    options: vi.fn().mockResolvedValue({
      measurement_results: [
        { value: 'pass', label: 'Pass' },
        { value: 'fail', label: 'Fail' },
      ],
    }),
    chain: vi.fn().mockResolvedValue([]),
    recordItemResults: vi.fn(),
    generateCoC: vi.fn(),
    cancel: vi.fn(),
    review: vi.fn(),
  },
}));
vi.mock('@/api/quality/defectEvidence', () => ({
  defectEvidenceApi: { list: defectEvidenceMocks.list, create: defectEvidenceMocks.create },
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: permissionState.can }) }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeRecord: vi.fn() }));
vi.mock('@/api/quality/analytics', () => ({
  analyticsApi: { spcForSpec: vi.fn().mockResolvedValue({}) },
}));
vi.mock('@/api/quality/capability', () => ({
  capabilityApi: {
    options: vi.fn().mockResolvedValue({
      capability_thresholds: { launch: 1.67, ongoing: 1.33, action: 1, minimum_samples: 30 },
    }),
  },
}));
vi.mock('react-router-dom', async (importOriginal) => ({
  ...(await importOriginal<typeof import('react-router-dom')>()),
  useParams: () => ({ id: 'insp-1' }),
  useNavigate: () => navigation.navigate,
}));

function measurement(sample: number, type: 'dimensional' | 'visual'): InspectionMeasurement {
  return {
    id: `m-${sample}-${type}`,
    sample_index: sample,
    parameter_name: type === 'dimensional' ? 'Outer diameter' : 'Flash',
    parameter_type: type,
    parameter_type_label: type === 'dimensional' ? 'Dimensional' : 'Visual',
    unit_of_measure: type === 'dimensional' ? 'mm' : null,
    nominal_value: type === 'dimensional' ? '10.000' : null,
    tolerance_min: type === 'dimensional' ? '9.950' : null,
    tolerance_max: type === 'dimensional' ? '10.050' : null,
    measured_value: null,
    is_critical: type === 'dimensional',
    is_pass: null,
    notes: null,
  } as unknown as InspectionMeasurement;
}

const inspection = {
  id: 'insp-1',
  inspection_number: 'QC-202609-0001',
  stage: 'outgoing',
  status: 'in_progress',
  result_version: 0,
  entity_type: 'work_order',
  entity_hash_id: 'wo-1',
  batch_quantity: 150,
  accepted_quantity: 0,
  sample_size: 3,
  aql_code: 'F',
  accept_count: 0,
  reject_count: 1,
  defect_count: 0,
  can_manage: true,
  available_actions: ['cancel'],
  started_at: null,
  completed_at: null,
  notes: null,
  measurements: [1, 2, 3].flatMap((i) => [measurement(i, 'dimensional'), measurement(i, 'visual')]),
  spec: { id: 'spec-1' },
} as unknown as Inspection;

describe('InspectionDetailPage', () => {
  afterEach(() => {
    permissionState.can.mockReturnValue(true);
    act(() => toast.dismiss());
  });

  it('does not ask for size readings when a counted inspection has none', async () => {
    vi.mocked(inspectionsApi.show).mockResolvedValue({
      ...inspection,
      stage: 'incoming',
      count_only_incoming: true,
      status: 'draft',
      sampling_basis: 'piece',
      counted_capture: { version: 1, required_quantity: 150, result_policy: 'zero_defects' },
      measurements: [{
        id: 'appearance', parameter_name: 'Appearance', acceptance_criterion: 'No tears',
        parameter_type: 'visual',
      }],
    } as Inspection);
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <InspectionDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );
    expect(await screen.findByText('QC: enter the defective quantity in the source unit, describe any defects, then submit.')).toBeInTheDocument();
    expect(screen.getByText('PASS requires 0 defective PCS. Any defective quantity is FAIL.')).toBeInTheDocument();
  });

  it('does not enable checklist edits when the server manage decision is missing', async () => {
    vi.mocked(inspectionsApi.show).mockResolvedValue({
      ...inspection,
      status: 'in_progress',
      capture_method: 'measurement',
      can_manage: undefined,
      available_actions: [],
    } as Inspection);
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <InspectionDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByRole('region', { name: 'Quality inspection' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Save draft' })).not.toBeInTheDocument();
  });

  it('hides management actions when the API denies manage access despite a stale client permission', async () => {
    permissionState.can.mockReturnValue(true);
    vi.mocked(inspectionsApi.show).mockResolvedValue({
      ...inspection,
      status: 'in_progress',
      capture_method: 'measurement',
      can_manage: false,
      can_replace_checklist: true,
      available_actions: [],
    } as Inspection);
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <InspectionDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByRole('region', { name: 'Quality inspection' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Cancel$/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Replace with PASS/FAIL checklist' })).not.toBeInTheDocument();
  });

  it('renders header mutation buttons only from the API available_actions list', async () => {
    vi.mocked(inspectionsApi.show).mockResolvedValue({
      ...inspection,
      status: 'in_progress',
      can_manage: true,
      available_actions: [],
    } as Inspection);
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter><InspectionDetailPage /></MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByText('QC-202609-0001')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Cancel$/ })).not.toBeInTheDocument();
  });

  it.each([
    ['incoming', 'lot_checklist'],
    ['in_process', 'lot_checklist'],
    ['outgoing', 'lot_checklist'],
    ['supplier_return', 'per_unit'],
    ['customer_return', 'per_unit'],
  ] as const)(
    'uses simple quantity PASS/FAIL for %s without measurement inputs',
    async (stage, mode) => {
      vi.mocked(inspectionsApi.show).mockResolvedValue({ ...inspection, stage, inspection_mode: mode });
      render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter><InspectionDetailPage /></MemoryRouter>
      </QueryClientProvider>);
      const field = await screen.findByLabelText(/Defective pieces/);
      expect(screen.getAllByRole('spinbutton')).toHaveLength(1);
      expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
      expect(screen.queryByRole('button', { name: 'PASS Outer diameter' })).not.toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
      fireEvent.change(field, { target: { value: '1' } });
      expect(screen.getByRole('textbox', { name: /Defective piece 1/ })).toBeRequired();
      fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Oversized part' } });
      expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeEnabled();
    },
  );

  it('opens source-linked defect reporting after batch approval', async () => {
    vi.mocked(inspectionsApi.show).mockResolvedValue({
      ...inspection,
      status: 'passed',
      available_actions: ['report_defect'],
      work_order_output: { id: 'output-1', batch_code: null, good_count: 150, reject_count: 0 },
    } as Inspection);
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <InspectionDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Report defect' }));
    expect(navigation.navigate).toHaveBeenCalledWith('/quality/ncrs/new?inspection_id=insp-1');
  });

  it('shows the certificate evidence error returned by the server', async () => {
    toast.dismiss();
    vi.mocked(inspectionsApi.show).mockResolvedValue({
      ...inspection,
      status: 'passed',
      available_actions: [],
      coc_unavailable_reason: null,
      measurements: inspection.measurements?.map((row) => ({
        ...row,
        measured_value: 10,
        is_pass: true,
      })),
    });
    vi.mocked(inspectionsApi.generateCoC).mockRejectedValue({
      response: {
        data: { message: 'CoC requires every critical characteristic to carry a reading.' },
      },
    });
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <>
            <InspectionDetailPage />
            <Toaster />
          </>
        </MemoryRouter>
      </QueryClientProvider>,
    );
    const generateCertificate = await screen.findByRole('button', { name: 'Certificate of Conformance' });
    await act(async () => {
      fireEvent.click(generateCertificate);
    });
    expect(
      await screen.findByText('CoC requires every critical characteristic to carry a reading.'),
    ).toBeInTheDocument();
  });

  it('explains why checklist-only outgoing inspections cannot issue a certificate', async () => {
    vi.mocked(inspectionsApi.show).mockResolvedValue({
      ...inspection,
      status: 'passed',
      available_actions: [],
      inspection_mode: 'lot_checklist',
      coc_unavailable_reason:
        'CoC requires every sampled measurement to be resolved; 6 have no pass/fail recorded.',
      measurements: inspection.measurements?.map((row) => ({
        ...row,
        measured_value: null,
        is_pass: null,
      })),
    });
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <InspectionDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(
      await screen.findByText(
        'Certificate unavailable: CoC requires every sampled measurement to be resolved; 6 have no pass/fail recorded.',
      ),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Certificate of Conformance' }),
    ).not.toBeInTheDocument();
  });

  it('shows who opened the inspection and who recorded its results', async () => {
    vi.mocked(inspectionsApi.show).mockResolvedValue({
      ...inspection,
      inspection_mode: 'lot_checklist',
      inspector: { id: 'creator-1', name: 'Sales requester' },
      result_authors: [{ id: 'qc-1', name: 'QC inspector' }],
    });
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <InspectionDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByText('Inspection created by')).toBeInTheDocument();
    expect(screen.getByText('Sales requester')).toBeInTheDocument();
    expect(screen.getByText('Results recorded by')).toBeInTheDocument();
    expect(screen.getByText('QC inspector')).toBeInTheDocument();
  });

  it('preserves sampling limits, provenance, review history and saved evidence without entry fields', async () => {
    vi.mocked(analyticsApi.spcForSpec).mockResolvedValue({
      diameter: {
        parameter_name: 'Outer diameter',
        unit: 'mm',
        cp: 1.4,
        cpk: 1.35,
        cpu: 1.35,
        cpl: 1.45,
        mean: 10,
        std_dev: 0.01,
        sample_count: 30,
        usl: 10.05,
        lsl: 9.95,
      },
    });
    vi.mocked(inspectionsApi.chain).mockResolvedValue([
      { key: 'source', label: 'Work order', state: 'done', href: '/production/work-orders/wo-1' },
      { key: 'qc', label: 'Quality inspection', state: 'done' },
    ]);
    vi.mocked(inspectionsApi.show).mockResolvedValue({
      ...inspection,
      status: 'failed',
      available_actions: [],
      capture_method: 'measurement',
      started_at: '2026-10-01T08:00:00Z',
      completed_at: '2026-10-02T09:00:00Z',
      created_at: '2026-09-30T08:00:00Z',
      product: { id: 'product-1', part_number: 'WB-001', name: 'Wiper Bushing' },
      item: { id: 'item-1', code: 'ITEM-001', name: 'Raw material' },
      reviewer: { id: 'checker-1', name: 'Independent checker' },
      reviewed_at: '2026-10-02T10:00:00Z',
      review_remarks: 'Surface crack confirmed',
      notes: 'Keep batch isolated',
      measurements: [{ ...measurement(1, 'dimensional'), measured_value: 10.2, is_pass: false }],
    });
    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <MemoryRouter>
          <InspectionDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );
    expect(await screen.findByText('AQL code')).toBeInTheDocument();
    expect(screen.getByText('Acceptance limit')).toBeInTheDocument();
    expect(screen.getByText('Rejection limit')).toBeInTheDocument();
    expect(screen.getByText('Sample defects').nextElementSibling).toHaveTextContent('Not recorded');
    expect(screen.getByText('Measurement defects').nextElementSibling).toHaveTextContent('0');
    expect(screen.getByText('Started')).toBeInTheDocument();
    expect(screen.getByText('Completed')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'WB-001 — Wiper Bushing' })).toHaveAttribute(
      'href',
      '/crm/products/product-1',
    );
    expect(screen.getByRole('link', { name: 'ITEM-001 — Raw material' })).toHaveAttribute(
      'href',
      '/inventory/items/item-1',
    );
    expect(screen.getByText('Independent checker')).toBeInTheDocument();
    expect(screen.getByText('Surface crack confirmed')).toBeInTheDocument();
    expect(screen.getByText('Keep batch isolated')).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Record flow' })).toBeInTheDocument();
    const flow = screen.getByRole('region', { name: 'Record flow' });
    const details = screen.getByRole('region', { name: 'Inspection details' });
    expect(flow.compareDocumentPosition(details) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    const links = screen.getByRole('region', { name: 'Linked records' });
    const capability = await screen.findByRole('region', { name: 'SPC capability indices' });
    expect(links.parentElement).toBe(capability.parentElement);
    expect(
      links.compareDocumentPosition(capability) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    expect(screen.getByRole('region', { name: 'Saved measurement evidence' })).toHaveTextContent(
      '10.2',
    );
    expect(document.querySelector('input,select,textarea,details')).toBeNull();
  });

  it('renders the successful checker response immediately and removes the stale review action', async () => {
    const pendingReview = {
      ...inspection,
      status: 'awaiting_review',
      proposed_result: 'passed',
      can_review: true,
      available_actions: ['review_pass'],
    } as Inspection;
    const passed = { ...inspection, status: 'passed', status_label: 'Passed', can_review: false, available_actions: [] } as Inspection;
    vi.mocked(inspectionsApi.show).mockResolvedValueOnce(pendingReview).mockResolvedValue(passed);
    vi.mocked(inspectionsApi.review).mockResolvedValue(passed);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <InspectionDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.click(await screen.findByRole('button', { name: 'Approve pass' }));
    const approveButtons = await screen.findAllByRole('button', { name: 'Approve pass' });
    fireEvent.click(approveButtons[approveButtons.length - 1]);

    expect(await screen.findByText('Passed')).toBeInTheDocument();
    expect(inspectionsApi.review).toHaveBeenCalledWith('insp-1', 'passed', undefined, 0);
    expect(screen.queryByRole('button', { name: 'Approve pass' })).not.toBeInTheDocument();
    expect(queryClient.getQueryData(inspectionQueryKeys.detail('insp-1'))).toEqual(passed);
  });

  it('submits the server result version when cancelling a legacy inspection', async () => {
    const current = { ...inspection, result_version: 7 } as Inspection;
    const cancelled = { ...current, result_version: 8, status: 'cancelled', available_actions: [] } as Inspection;
    vi.mocked(inspectionsApi.show).mockResolvedValue(current);
    vi.mocked(inspectionsApi.cancel).mockResolvedValue(cancelled);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <InspectionDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.click(await screen.findByRole('button', { name: /^Cancel$/ }));
    fireEvent.change(screen.getByRole('textbox', { name: 'Reason' }), {
      target: { value: 'Operator confirmed withdrawal' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Cancel inspection' }));

    await waitFor(() => expect(inspectionsApi.cancel).toHaveBeenCalledWith('insp-1', 'Operator confirmed withdrawal', 7));
    await waitFor(() => expect(queryClient.getQueryData(inspectionQueryKeys.detail('insp-1'))).toEqual(cancelled));
  });

  it('refetches the authorized inspection after a stale-version review conflict', async () => {
    const errorToast = vi.spyOn(toast, 'error');
    const pendingReview = {
      ...inspection,
      capture_method: 'checklist',
      result_version: 2,
      status: 'awaiting_review',
      proposed_result: 'passed',
      can_review: true,
      available_actions: ['review_pass'],
    } as Inspection;
    const latest = { ...pendingReview, result_version: 3, status: 'passed', can_review: false, available_actions: [] } as Inspection;
    vi.mocked(inspectionsApi.show).mockResolvedValueOnce(pendingReview).mockResolvedValue(latest);
    vi.mocked(inspectionsApi.review).mockRejectedValue({ response: {
      status: 409,
      data: { code: 'inspection_state_conflict', message: 'This inspection changed. Reload before submitting.' },
    } });
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <InspectionDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.click(await screen.findByRole('button', { name: 'Approve pass' }));
    const approveButtons = await screen.findAllByRole('button', { name: 'Approve pass' });
    fireEvent.click(approveButtons[approveButtons.length - 1]);

    await waitFor(() => expect(queryClient.getQueryData(inspectionQueryKeys.detail('insp-1'))).toEqual(latest));
    expect(errorToast).toHaveBeenCalledWith('Inspection changed in another session. The latest saved version was loaded; review before acting again.');
    expect(inspectionsApi.review).toHaveBeenCalledWith('insp-1', 'passed', undefined, 2);
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Approve pass' })).not.toBeInTheDocument());
  });
});

it('shows incoming warehouse acceptance and existing recovery without granting destination access', async () => {
  permissionState.can.mockImplementation(permission => permission === 'quality.inspections.view');
  vi.mocked(inspectionsApi.show).mockResolvedValue({ ...inspection, stage: 'incoming', status: 'passed', capture_method: 'checklist', available_actions: [],
    product: { id: 'p-1', part_number: 'RAW', name: 'Material' },
    ncr: { id: 'n-1', ncr_number: 'NCR-100', status: 'open', status_label: 'Open', disposition: null, disposition_label: null, href: null },
    incoming_acceptance: { grn_item_id: 'line', grn_id: 'g-1', grn_number: 'GRN-100', quantity_received: '125.500', quantity_accepted: '125.500', unit: 'KG', status: 'accepted', status_label: 'Accepted' },
  });
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><InspectionDetailPage /></MemoryRouter></QueryClientProvider>);
  expect(await screen.findByText('125.500 KG · Accepted')).toBeVisible();
  expect(screen.getByText('NCR-100')).toBeVisible();
  expect(screen.queryByRole('link', { name: 'RAW — Material' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Open NCR' })).not.toBeInTheDocument();
});

it('shows defect severity and private image evidence on an incoming inspection', async () => {
  vi.mocked(inspectionsApi.show).mockResolvedValue({
    ...inspection, stage: 'incoming', status: 'failed', can_manage: true,
    available_actions: ['cancel'],
    product: { id: 'p-1', part_number: 'RAW', name: 'Material' },
  });
  defectEvidenceMocks.list.mockResolvedValue([{
    id: 'defect-1', category: 'surface_damage', category_label: 'Surface damage', severity: 'major',
    severity_label: 'Major', affected_quantity: 2, notes: 'Cracked edges on the sampled pieces.',
    photo_url: '/api/v1/quality/defect-evidence/defect-1/photo', grn_item: { id: 'grn-line-1', item_code: 'RAW-01', item_name: 'Resin' },
    created_by: { id: 'qc-1', name: 'QC Inspector' }, created_at: '2026-11-10T08:00:00Z',
  }]);

  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><InspectionDetailPage /></MemoryRouter></QueryClientProvider>);

  expect(await screen.findByRole('heading', { name: 'Defect evidence' })).toBeVisible();
  expect(await screen.findByText('Major')).toBeVisible();
  expect(screen.getByText('Cracked edges on the sampled pieces.')).toBeVisible();
  expect(screen.getByRole('img', { name: 'Defect evidence photo: Surface damage' })).toHaveAttribute('src', '/api/v1/quality/defect-evidence/defect-1/photo');
  expect(screen.getByText('GRN item: RAW-01 — Resin')).toBeVisible();
});

it('uploads an incoming defect image with its severity and affected quantity', async () => {
  permissionState.can.mockReturnValue(true);
  vi.mocked(inspectionsApi.show).mockResolvedValue({
    ...inspection, stage: 'incoming', status: 'in_progress', can_manage: true,
    available_actions: ['cancel'],
    product: { id: 'p-1', part_number: 'RAW', name: 'Material' },
    incoming_acceptance: { grn_item_id: 'grn-line-1', grn_id: 'grn-1', grn_number: 'GRN-1', quantity_received: '10', quantity_accepted: '8', unit: 'pcs', status: 'pending', status_label: 'Pending' },
  });
  defectEvidenceMocks.list.mockResolvedValue([]);
  defectEvidenceMocks.create.mockResolvedValue({ id: 'defect-new' });

  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}><MemoryRouter><InspectionDetailPage /></MemoryRouter></QueryClientProvider>);

  await screen.findByRole('heading', { name: 'Defect evidence' });
  fireEvent.change(screen.getByLabelText('Defect category', { exact: false }), { target: { value: 'other' } });
  fireEvent.change(screen.getByLabelText('Other defect category', { exact: false }), { target: { value: 'Unusual finish' } });
  fireEvent.change(screen.getByLabelText('Severity', { exact: false }), { target: { value: 'critical' } });
  fireEvent.change(screen.getByLabelText('Affected quantity', { exact: false }), { target: { value: '4' } });
  const photo = new File(['image'], 'defect.png', { type: 'image/png' });
  fireEvent.change(screen.getByLabelText('Defect photo', { exact: false }), { target: { files: [photo] } });
  const addEvidenceButton = screen.getByRole('button', { name: 'Add defect evidence' });
  expect(addEvidenceButton).toBeEnabled();
  const evidenceForm = addEvidenceButton.closest('form');
  expect(evidenceForm).not.toBeNull();
  fireEvent.submit(evidenceForm!);

  await waitFor(() => expect(defectEvidenceMocks.create).toHaveBeenCalledWith('insp-1', expect.objectContaining({
    category: 'other', other_category: 'Unusual finish', severity: 'critical', affected_quantity: 4, photo,
  })));
});
