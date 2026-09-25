import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import InspectionDetailPage from './detail';
import { inspectionsApi } from '@/api/quality/inspections';
import type { Inspection, InspectionMeasurement } from '@/types/quality';

/**
 * O2C audit 2026-09-25 — an outgoing inspection rendered one panel per sample:
 * 20 samples × 2 parameters made a 5,700px page. It is now one table grouped
 * by sample, and undecided manual (visual) checks can be passed in one click
 * before saving — the inspector still saves, completes and gets reviewed.
 */
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
    recordMeasurements: vi.fn(),
    complete: vi.fn(),
    cancel: vi.fn(),
    review: vi.fn(),
  },
}));
vi.mock('@/api/quality/capability', () => ({ capabilityApi: { options: vi.fn().mockResolvedValue({}) } }));
vi.mock('@/api/quality/analytics', () => ({ analyticsApi: { spcForSpec: vi.fn().mockResolvedValue([]) } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('react-router-dom', async (importOriginal) => ({
  ...(await importOriginal<typeof import('react-router-dom')>()),
  useParams: () => ({ id: 'insp-1' }),
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
} as unknown as Inspection;

describe('InspectionDetailPage', () => {
  it('lists every sample in one measurements table', async () => {
    vi.mocked(inspectionsApi.show).mockResolvedValue(inspection);
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <InspectionDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByText('Measurements')).toBeInTheDocument();
    expect(screen.queryByText('Sample #1')).not.toBeInTheDocument();
    expect(screen.getByText('#1')).toBeInTheDocument();
    expect(screen.getByText('#3')).toBeInTheDocument();
  });

  it('passes the open manual checks in one click, leaving dimensions to be measured', async () => {
    vi.mocked(inspectionsApi.show).mockResolvedValue(inspection);
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <InspectionDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.click(await screen.findByRole('button', { name: /Pass 3 open manual checks/ }));

    const results = screen.getAllByLabelText('Visual result') as HTMLSelectElement[];
    expect(results).toHaveLength(3);
    expect(results.every((select) => select.value === 'pass')).toBe(true);
    expect(screen.queryByRole('button', { name: /open manual check/ })).not.toBeInTheDocument();
  });
});
