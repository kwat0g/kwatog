import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { leaveTypesApi } from '@/api/leave';
import { YearEndLeaveModal } from './year-end';

const YEAR_ERROR = 'Enter a whole year from 2020 through 2099.';
const CONTRACT_PATH = resolve(process.cwd(), '../api/resources/contracts/leave-year-end-validation.json');
const YEAR_CONTRACT = JSON.parse(readFileSync(CONTRACT_PATH, 'utf8')) as {
  minimum_year: number;
  maximum_year: number;
  canonical_input: { string_pattern: string };
  examples: { valid_strings: string[]; invalid_strings: string[] };
};

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

    expect(yearInput).toHaveAttribute('min', String(YEAR_CONTRACT.minimum_year));
    expect(yearInput).toHaveAttribute('max', String(YEAR_CONTRACT.maximum_year));
    expect(screen.getByText(/Processes all active leave types/)).toBeInTheDocument();

    for (const invalidYear of [...YEAR_CONTRACT.examples.invalid_strings, 'abc']) {
      fireEvent.change(yearInput, { target: { value: invalidYear } });

      expect(yearInput).toHaveAttribute('aria-invalid', 'true');
      expect(screen.getByRole('alert')).toHaveTextContent(YEAR_ERROR);
      expect(runButton).toBeDisabled();
    }

    fireEvent.change(yearInput, { target: { value: String(YEAR_CONTRACT.maximum_year) } });

    expect(yearInput).toHaveAttribute('aria-invalid', 'false');
    expect(runButton).toBeEnabled();
    fireEvent.click(runButton);

    await waitFor(() => {
      expect(leaveTypesApi.processYearEnd).toHaveBeenCalledWith(YEAR_CONTRACT.maximum_year);
    });
  });

  it('keeps browser bounds and canonical parsing aligned with the checked-in contract', () => {
    renderModal();

    expect(YEAR_CONTRACT.canonical_input.string_pattern).toBe('^[0-9]{4}$');

    for (const validYear of YEAR_CONTRACT.examples.valid_strings) {
      fireEvent.change(screen.getByLabelText('Year'), { target: { value: validYear } });
      expect(screen.getByLabelText('Year')).toHaveAttribute('aria-invalid', 'false');
    }

    for (const invalidYear of YEAR_CONTRACT.examples.invalid_strings) {
      fireEvent.change(screen.getByLabelText('Year'), { target: { value: invalidYear } });
      expect(screen.getByLabelText('Year')).toHaveAttribute('aria-invalid', 'true');
    }

    expect(leaveTypesApi.processYearEnd).not.toHaveBeenCalled();
  });
});
