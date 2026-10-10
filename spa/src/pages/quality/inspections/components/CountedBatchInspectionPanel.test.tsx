import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { inspectionsApi } from '@/api/quality/inspections';
import type { Inspection } from '@/types/quality';
import { CountedBatchInspectionPanel } from './CountedBatchInspectionPanel';
vi.mock('@/api/quality/inspections', () => ({ inspectionsApi: { recordBatchResult: vi.fn() } }));
const inspection = {
  id: 'counted',
  status: 'draft',
  result_version: 0,
  batch_quantity: 200,
  counted_capture: { version: 1, required_quantity: 200, result_policy: 'zero_defects' },
  measurements: [
    {
      id: 'appearance',
      parameter_name: 'Appearance',
      acceptance_criterion: 'No cracks',
      parameter_type: 'visual',
    },
  ],
} as unknown as Inspection;
function panel(source = inspection) {
  return render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter>
        <CountedBatchInspectionPanel inspection={source} canManage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}
it('keeps a 200-piece inspection bounded and never sends per-piece answers', async () => {
  vi.mocked(inspectionsApi.recordBatchResult).mockResolvedValue({
    ...inspection,
    status: 'awaiting_review',
  });
  panel();
  expect(screen.getAllByRole('spinbutton')).toHaveLength(1);
  expect(screen.queryByRole('button', { name: 'PASS' })).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
  fireEvent.change(screen.getByRole('spinbutton', { name: 'Defective pieces' }), {
    target: { value: '0' },
  });
  fireEvent.click(screen.getByRole('checkbox', { name: /I checked all 200 pieces/ }));
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await waitFor(() =>
    expect(inspectionsApi.recordBatchResult).toHaveBeenCalledWith('counted', {
      expected_result_version: 0,
      coverage_confirmed: true,
      inspected_quantity: 200,
      sample_defect_count: 0,
      requirement_problem: false,
      measurements: [],
    }),
  );
});
it('requires a problem for defective pieces and holds the entire batch', () => {
  panel();
  fireEvent.change(screen.getByRole('spinbutton', { name: 'Defective pieces' }), {
    target: { value: '2' },
  });
  expect(screen.getAllByRole('checkbox')).toHaveLength(1);
  fireEvent.click(screen.getByRole('checkbox', { name: /I checked all 200 pieces/ }));
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
  expect(screen.getByText(/FAIL.*batch stays on hold/)).toBeVisible();
  fireEvent.change(screen.getByRole('textbox', { name: 'Describe the problem' }), {
    target: { value: 'Two cracked pieces' },
  });
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeEnabled();
});

it('requires defective supplier pieces to be separated before submission', async () => {
  const incoming = { ...inspection, count_only_incoming: true };
  vi.mocked(inspectionsApi.recordBatchResult).mockResolvedValue({ ...incoming, status: 'awaiting_review' });
  panel(incoming);
  fireEvent.change(screen.getByRole('spinbutton', { name: 'Defective pieces' }), { target: { value: '2' } });
  fireEvent.change(screen.getByRole('textbox', { name: 'Describe the problem' }), { target: { value: 'Two torn bags' } });
  fireEvent.click(screen.getByRole('checkbox', { name: /I checked all 200 pieces/ }));
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
  fireEvent.click(screen.getByRole('checkbox', { name: /I separated the 2 defective pieces/ }));
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await waitFor(() => expect(inspectionsApi.recordBatchResult).toHaveBeenCalledWith('counted', expect.objectContaining({
    sample_defect_count: 2,
    defects_removed_confirmed: true,
  })));
});

it('keeps fallback supplier QC focused on defective pieces', async () => {
  const incoming = { ...inspection, count_only_incoming: true };
  vi.mocked(inspectionsApi.recordBatchResult).mockResolvedValue({ ...incoming, status: 'awaiting_review' });
  panel(incoming);
  fireEvent.change(screen.getByRole('spinbutton', { name: 'Defective pieces' }), { target: { value: '0' } });
  expect(screen.queryByRole('checkbox', { name: 'Other inspection requirement failed' })).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('checkbox', { name: /I checked all 200 pieces/ }));
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await waitFor(() => expect(inspectionsApi.recordBatchResult).toHaveBeenCalledWith('counted', expect.objectContaining({
    sample_defect_count: 0,
    requirement_problem: false,
  })));
});

it('lets QC report a separate batch problem when no pieces are defective', async () => {
  vi.mocked(inspectionsApi.recordBatchResult).mockResolvedValue({
    ...inspection,
    status: 'awaiting_review',
  });
  panel();
  fireEvent.change(screen.getByRole('spinbutton', { name: 'Defective pieces' }), {
    target: { value: '0' },
  });
  fireEvent.click(screen.getByRole('checkbox', { name: 'Other inspection requirement failed' }));
  expect(screen.getByRole('button', { name: 'Submit inspection' })).toBeDisabled();
  fireEvent.change(screen.getByRole('textbox', { name: 'Describe the problem' }), {
    target: { value: 'Required certificate missing' },
  });
  fireEvent.click(screen.getByRole('checkbox', { name: /I checked all 200 pieces/ }));
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await waitFor(() =>
    expect(inspectionsApi.recordBatchResult).toHaveBeenCalledWith('counted',
      expect.objectContaining({
        sample_defect_count: 0,
        requirement_problem: true,
        problem_description: 'Required certificate missing',
      })),
  );
});

it('uses an affected-lot confirmation instead of a piece count for material lots', async () => {
  const lot = { ...inspection, sampling_basis: 'lot' as const, source_quantity: '200.500', source_uom_code: 'kg',
    batch_quantity: 1, counted_capture: { ...inspection.counted_capture!, required_quantity: 1 } };
  vi.mocked(inspectionsApi.recordBatchResult).mockClear();
  vi.mocked(inspectionsApi.recordBatchResult).mockResolvedValue({ ...lot, status: 'awaiting_review' });
  panel(lot);
  expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
  expect(screen.getByText(/200.500 kg/)).toBeVisible();
  fireEvent.click(screen.getByRole('checkbox', { name: 'Material lot has defects' }));
  fireEvent.change(screen.getByRole('textbox', { name: 'Describe the problem' }), { target: { value: 'Contaminated resin' } });
  fireEvent.click(screen.getByRole('checkbox', { name: /I checked all 1 lot/ }));
  fireEvent.click(screen.getByRole('button', { name: 'Submit inspection' }));
  await waitFor(() => expect(inspectionsApi.recordBatchResult).toHaveBeenCalledWith('counted', expect.objectContaining({
    physical_defects_confirmed: true, inspected_quantity: 1, problem_description: 'Contaminated resin',
  })));
  expect(vi.mocked(inspectionsApi.recordBatchResult).mock.calls[0][1]).not.toHaveProperty('sample_defect_count');
});
