import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { leaveTypesApi } from '@/api/leave';
import { YearEndLeaveModal } from './year-end';

const MIN_SUPPORTED_YEAR = 2020;
const MAX_SUPPORTED_YEAR = 2099;
const YEAR_ERROR = `Enter a whole year from ${MIN_SUPPORTED_YEAR} through ${MAX_SUPPORTED_YEAR}.`;

vi.mock('@/api/leave', () => ({
  leaveTypesApi: {
    processYearEnd: vi.fn(),
  },
}));

vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({ can: () => true }),
}));

function renderModal() {
  const queryClient = new QueryClient({
    defaultOptions: { mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <YearEndLeaveModal open onClose={() => {}} />
    </QueryClientProvider>,
  );
}

describe('year-end leave processing', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(leaveTypesApi.processYearEnd).mockResolvedValue({
      message: 'Year-end processing queued.',
    });
  });

  it('enforces the supported year bounds and describes all active leave types', async () => {
    renderModal();

    const yearInput = screen.getByLabelText('Year');
    const runButton = screen.getByRole('button', { name: 'Run year-end processing' });

    expect(yearInput).toHaveAttribute('min', String(MIN_SUPPORTED_YEAR));
    expect(yearInput).toHaveAttribute('max', String(MAX_SUPPORTED_YEAR));
    expect(screen.getByText(/Processes all active leave types/)).toBeInTheDocument();

    for (const invalidYear of ['2019', '2100', 'abc']) {
      fireEvent.change(yearInput, { target: { value: invalidYear } });

      expect(yearInput).toHaveAttribute('aria-invalid', 'true');
      expect(screen.getByRole('alert')).toHaveTextContent(YEAR_ERROR);
      expect(runButton).toBeDisabled();
    }

    fireEvent.change(yearInput, { target: { value: String(MAX_SUPPORTED_YEAR) } });

    expect(yearInput).toHaveAttribute('aria-invalid', 'false');
    expect(runButton).toBeEnabled();
    fireEvent.click(runButton);

    await waitFor(() => {
      expect(leaveTypesApi.processYearEnd).toHaveBeenCalledWith(MAX_SUPPORTED_YEAR);
    });
  });
});
