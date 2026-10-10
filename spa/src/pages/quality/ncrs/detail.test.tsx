import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ncrsApi } from '@/api/quality/ncrs';
import NcrDetailPage from './detail';

const permissionState = vi.hoisted(() => ({ canManageNcr: true }));
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({ can: (permission: string) => permission !== 'quality.ncr.manage' || permissionState.canManageNcr }),
}));
vi.mock('@/api/quality/ncrs', () => ({
  ncrsApi: {
    show: vi.fn(),
    options: vi.fn(),
    assignees: vi.fn(),
    setDisposition: vi.fn(),
  },
}));
vi.mock('@/api/client', () => ({
  client: {
    get: vi
      .fn()
      .mockResolvedValue({ data: { data: [{ id: 'defect-1', code: 'CRACK', name: 'Crack' }] } }),
  },
}));

afterEach(() => { permissionState.canManageNcr = true; vi.clearAllMocks(); });

describe('incoming receipt review', () => {
  it('lets an independent inspection reviewer approve sorted good only', async () => {
    permissionState.canManageNcr = false;
    vi.mocked(ncrsApi.show).mockResolvedValue({
      id: 'ncr-1', ncr_number: 'NCR-1', status: 'open', severity: 'medium',
      source: 'inspection', affected_quantity: 100, is_incoming_mrb: true,
      sorting_required_quantity: '100.000', actions: [],
      inspection: { id: 'qc-1', inspection_number: 'QC-1', stage: 'incoming', status: 'failed',
        batch_quantity: 100, sampling_basis: 'piece', source_uom_code: 'PCS' },
    } as never);
    vi.mocked(ncrsApi.options).mockResolvedValue({
      dispositions: [
        { value: 'return_to_supplier', label: 'Return to supplier' },
        { value: 'rework', label: 'Rework' },
      ],
      sources: [], severities: [], statuses: [], actions: [], default_disposition: '',
    });
    vi.mocked(ncrsApi.assignees).mockResolvedValue([{ id: 'rosa-1', name: 'Rosa Villareal' }]);
    vi.mocked(ncrsApi.setDisposition).mockResolvedValue({} as never);
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter initialEntries={['/quality/ncrs/ncr-1']}>
          <Routes><Route path="/quality/ncrs/:id" element={<NcrDetailPage />} /></Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );
    await screen.findByText('NCR-1');
    const select = screen.getByLabelText('Disposition');
    await screen.findByRole('option', { name: 'Return to supplier' });
    expect(screen.queryByRole('option', { name: 'Rework' })).not.toBeInTheDocument();
    expect(screen.queryByRole('textbox', { name: 'Root cause' })).not.toBeInTheDocument();
    fireEvent.change(select, { target: { value: 'return_to_supplier' } });
    fireEvent.change(screen.getByLabelText('Verified good quantity after sorting'), { target: { value: '98' } });
    fireEvent.change(screen.getByLabelText(/Quantity inspected after full-batch sorting/), { target: { value: '100' } });
    fireEvent.change(screen.getByLabelText(/Sorting \/ reinspection evidence reference/), { target: { value: 'SORT-100' } });
    fireEvent.change(screen.getByLabelText(/Sorting and reinspection performed by/), { target: { value: 'rosa-1' } });
    fireEvent.click(screen.getByLabelText('Remaining good pieces were segregated, reinspected and passed all requirements.'));
    fireEvent.click(screen.getByRole('button', { name: 'Save disposition' }));
    await waitFor(() => expect(ncrsApi.setDisposition).toHaveBeenCalledWith('ncr-1', expect.objectContaining({
      disposition: 'return_to_supplier', mrb_accepted_quantity: '98',
      sorting_evidence: expect.objectContaining({ performed_by: 'rosa-1' }),
    })));
  });
});

describe('startup trial recovery', () => {
  it('requires actual good plus scrap and defects before independently approving the sample', async () => {
    vi.mocked(ncrsApi.show).mockResolvedValue({
      id: 'ncr-1',
      ncr_number: 'NCR-1',
      status: 'in_progress',
      severity: 'high',
      source: 'inspection',
      affected_quantity: 100,
      defect_description: 'Trial crack',
      is_process_recovery: true,
      startup_trial: true,
      process_recovery_quantity: 12,
      actions: [],
      inspection: {
        id: 'qc-1',
        inspection_number: 'QC-1',
        stage: 'in_process',
        status: 'failed',
        batch_quantity: 100,
        sampling_basis: 'piece',
      },
    } as never);
    vi.mocked(ncrsApi.options).mockResolvedValue({
      dispositions: [{ value: 'rework', label: 'Rework' }],
      sources: [],
      severities: [],
      statuses: [],
      actions: [],
      default_disposition: '',
    });
    vi.mocked(ncrsApi.assignees).mockResolvedValue([{ id: 'inspector-1', name: 'Inspector One' }]);
    vi.mocked(ncrsApi.setDisposition).mockResolvedValue({} as never);
    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <MemoryRouter initialEntries={['/quality/ncrs/ncr-1']}>
          <Routes>
            <Route path="/quality/ncrs/:id" element={<NcrDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );
    await screen.findByText('NCR-1');
    fireEvent.change(screen.getByLabelText('Disposition'), { target: { value: 'rework' } });
    expect(
      screen.getByText(/These counts cover only the 12 recorded trial pieces/),
    ).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Verified good quantity after sorting'), {
      target: { value: '10' },
    });
    fireEvent.change(screen.getByLabelText(/Pieces scrapped/), { target: { value: '2' } });
    fireEvent.change(screen.getByLabelText(/Process sample verified after correction/), {
      target: { value: '12' },
    });
    fireEvent.change(screen.getByLabelText(/Sorting \/ reinspection evidence reference/), {
      target: { value: 'RECOVERY-12' },
    });
    fireEvent.change(screen.getByLabelText(/Sorting and reinspection performed by/), {
      target: { value: 'inspector-1' },
    });
    fireEvent.click(screen.getByLabelText('The corrected process passed all required checks.'));
    expect(screen.getByRole('button', { name: 'Save disposition' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Add trial defect' }));
    fireEvent.change(screen.getByLabelText('Trial defect type 1'), {
      target: { value: 'defect-1' },
    });
    fireEvent.change(screen.getByLabelText('Trial defect count 1'), { target: { value: '2' } });
    fireEvent.change(screen.getByRole('textbox', { name: /Root cause/ }), { target: { value: 'Pressure was low.' } });
    fireEvent.change(screen.getByRole('textbox', { name: /Corrective action/ }), { target: { value: 'Pressure corrected and sample reinspected.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save disposition' }));
    await waitFor(() =>
      expect(ncrsApi.setDisposition).toHaveBeenCalledWith(
        'ncr-1',
        expect.objectContaining({
          mrb_accepted_quantity: '10',
          sorting_evidence: expect.objectContaining({
            inspected_quantity: '12',
            performed_by: 'inspector-1',
            recovery_passed: true,
            trial_scrapped_quantity: 2,
            trial_defects: [{ defect_type_id: 'defect-1', count: 2 }],
          }),
        }),
      ),
    );
  });
});

describe('full-batch outgoing defect handling', () => {
  it('uses saved coverage, limits good release, and retains entries after a rejected approval', async () => {
    vi.mocked(ncrsApi.show).mockResolvedValue({
      id: 'ncr-1', ncr_number: 'NCR-1', status: 'open', severity: 'high', source: 'inspection_fail',
      affected_quantity: 500, full_piece_inspection: true, is_outgoing_recovery: true, historical_held: true,
      sorting_required_quantity: 500, actions: [],
      inspection: { id: 'qc-1', inspection_number: 'QC-1', stage: 'outgoing', status: 'failed',
        batch_quantity: 500, sample_defect_count: 2, accepted_quantity: 0,
        inspector: { id: 'rosa', name: 'Rosa Villareal' } },
    } as never);
    vi.mocked(ncrsApi.options).mockResolvedValue({
      dispositions: [{ value: 'rework', label: 'Rework' }, { value: 'scrap', label: 'Scrap' }],
      sources: [], severities: [], statuses: [], actions: [], default_disposition: '',
    });
    vi.mocked(ncrsApi.assignees).mockResolvedValue([{ id: 'rosa', name: 'Rosa Villareal' }]);
    vi.mocked(ncrsApi.setDisposition).mockRejectedValue({
      response: { data: { message: 'Could not save. Try again.' } },
    });
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter initialEntries={['/quality/ncrs/ncr-1']}>
          <Routes><Route path="/quality/ncrs/:id" element={<NcrDetailPage />} /></Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );
    await screen.findByText('NCR-1');
    expect(screen.getByText('500 pcs')).toBeInTheDocument();
    expect(screen.getByText('2 pcs')).toBeInTheDocument();
    expect(screen.getByText('498 pcs')).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText(/Disposition/), { target: { value: 'rework' } });
    expect(screen.getByText('Repair and QC')).toBeInTheDocument();
    expect(screen.getByText('QC can confirm separation after inspection review. Repaired pieces need QC before release.')).toBeInTheDocument();
    const coverage = screen.getByRole('spinbutton', { name: 'Quantity covered by final inspection' });
    expect(coverage).toHaveValue(500);
    expect(coverage).toHaveAttribute('readonly');
    const good = screen.getByRole('spinbutton', { name: 'Good pieces to release' });
    expect(good).toHaveValue(498);
    expect(screen.getByLabelText('Defects separated by')).toHaveValue('rosa');
    expect(screen.queryByRole('textbox', { name: /Corrective action/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Add action' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Approve release and repair' })).toBeDisabled();
    fireEvent.change(good, { target: { value: '499' } });
    expect(screen.getByText('Enter a whole number from 0 to 498.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Approve release and repair' })).toBeDisabled();
    fireEvent.change(good, { target: { value: '498' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Separation reference' }), { target: { value: 'HOLD-500' } });
    fireEvent.click(screen.getByLabelText('Good pieces match inspection results.'));
    fireEvent.click(screen.getByLabelText('Defective pieces physically separated.'));
    fireEvent.click(screen.getByRole('button', { name: 'Approve release and repair' }));
    await screen.findByRole('alert');
    expect(screen.getByRole('alert')).toHaveTextContent('Could not save. Try again.');
    expect(good).toHaveValue(498);
    expect(ncrsApi.setDisposition).toHaveBeenCalledWith('ncr-1', expect.objectContaining({
      mrb_accepted_quantity: '498',
      sorting_evidence: expect.objectContaining({ inspected_quantity: '500', defects_segregated: true }),
    }));
  });
});
