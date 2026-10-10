import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen } from '@testing-library/react';
import toast, { Toaster } from 'react-hot-toast';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import InspectionDetailPage from './detail';
import { inspectionsApi } from '@/api/quality/inspections';
import { analyticsApi } from '@/api/quality/analytics';
import type { Inspection, InspectionMeasurement } from '@/types/quality';

const navigation = vi.hoisted(() => ({ navigate: vi.fn() }));

const permissionState = vi.hoisted(() => ({ can: vi.fn((_permission: string) => true) }));

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
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: permissionState.can }) }));
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
  entity_type: 'work_order',
  entity_hash_id: 'wo-1',
  batch_quantity: 150,
  accepted_quantity: 0,
  sample_size: 3,
  aql_code: 'F',
  accept_count: 0,
  reject_count: 1,
  defect_count: 0,
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
    expect(await screen.findByText('QC: record defective pieces, then submit inspection.')).toBeInTheDocument();
    expect(screen.getByText('Pass requires zero defective pieces.')).toBeInTheDocument();
  });

  it.each([
    ['incoming', 'lot_checklist'],
    ['in_process', 'lot_checklist'],
    ['outgoing', 'lot_checklist'],
    ['supplier_return', 'per_unit'],
    ['customer_return', 'per_unit'],
  ] as const)(
    'shows critical sample measurements alongside checklist decisions for %s',
    async (stage, mode) => {
      vi.mocked(inspectionsApi.show).mockResolvedValue({
        ...inspection,
        stage,
        inspection_mode: mode,
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
        await screen.findAllByRole('button', { name: /^PASS (?:Outer diameter|Flash)$/ }),
      ).toHaveLength(2);
      expect(screen.getAllByRole('button', { name: /^FAIL / })).toHaveLength(2);
      const details = screen.getByRole('region', { name: 'Inspection details' });
      const checklist = screen.getByRole('region', { name: 'Inspection checklist' });
      expect(
        details.compareDocumentPosition(checklist) & Node.DOCUMENT_POSITION_FOLLOWING,
      ).toBeTruthy();
      expect(document.querySelector('details')).toBeNull();
      expect(screen.getByText('Sample defects').nextElementSibling).toHaveTextContent(
        'Not recorded',
      );
      expect(screen.getAllByRole('spinbutton')).toHaveLength(mode === 'lot_checklist' ? 5 : 3);
      if (mode === 'lot_checklist') {
        expect(screen.getByRole('spinbutton', { name: 'Inspected pieces' })).toBeInTheDocument();
        expect(screen.getByRole('spinbutton', { name: 'Defective pieces found' })).toBeInTheDocument();
      }
      expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
      expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
      expect(screen.queryByText('Measurements', { exact: true })).not.toBeInTheDocument();
      expect(screen.queryByText('Sample plan', { exact: true })).not.toBeInTheDocument();
      expect(screen.queryByText(/Save the required measurements/)).not.toBeInTheDocument();
      const submitName = mode === 'lot_checklist' ? 'Submit result' : 'Submit inspection';
      const submit = () => screen.getByRole('button', { name: submitName });
      expect(submit()).toBeDisabled();
      expect(screen.getByRole('button', { name: 'PASS Outer diameter' })).toBeDisabled();
      fireEvent.click(screen.getByRole('button', { name: 'FAIL Outer diameter' }));
      expect(
        screen.getByRole('textbox', { name: 'Problem found for Outer diameter' }),
      ).toBeRequired();
      fireEvent.change(screen.getByRole('textbox', { name: 'Problem found for Outer diameter' }), {
        target: { value: 'Oversized part' },
      });
      fireEvent.click(screen.getByRole('button', { name: 'PASS Flash' }));
      if (mode === 'lot_checklist') {
        expect(submit()).toBeDisabled();
        fireEvent.change(screen.getByRole('spinbutton', { name: 'Inspected pieces' }), {
          target: { value: '3' },
        });
        fireEvent.change(screen.getByRole('spinbutton', { name: 'Defective pieces found' }), {
          target: { value: '1' },
        });
        fireEvent.click(
          screen.getByRole('checkbox', {
            name: 'I confirm all required checks on the planned sample were completed',
          }),
        );
      }
      expect(submit()).toBeEnabled();
      expect(screen.getAllByRole('textbox')).toHaveLength(1);
    },
  );

  it('opens source-linked defect reporting after batch approval', async () => {
    vi.mocked(inspectionsApi.show).mockResolvedValue({
      ...inspection,
      status: 'passed',
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
});

it('shows incoming warehouse acceptance and existing recovery without granting destination access', async () => {
  permissionState.can.mockImplementation(permission => permission === 'quality.inspections.view');
  vi.mocked(inspectionsApi.show).mockResolvedValue({ ...inspection, stage: 'incoming', status: 'passed', capture_method: 'checklist',
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
