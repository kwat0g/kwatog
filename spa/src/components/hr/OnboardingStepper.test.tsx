import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { onboardingApi } from '@/api/hr/onboarding';
import type { EmployeeOnboarding, OnboardingStepKey } from '@/types/hr';
import { OnboardingStepper } from './OnboardingStepper';

const keys: OnboardingStepKey[] = [
  'profile_completed',
  'shift_assigned',
  'leave_balances_initialized',
  'account_provisioned',
  'dept_team_notified',
  'gov_ids_recorded',
  'banking_recorded',
];

function onboarding(completed: OnboardingStepKey[] = []): EmployeeOnboarding {
  return {
    steps: keys.map((key) => ({
      key,
      label: key === 'dept_team_notified' ? 'Dept Team Notified' : key,
      completed_at: completed.includes(key) ? '2026-08-25T08:00:00Z' : null,
    })),
    completed_at: completed.length === keys.length ? '2026-08-25T08:00:00Z' : null,
    is_complete: completed.length === keys.length,
  };
}

function renderStepper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, refetchOnWindowFocus: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <OnboardingStepper employeeId="emp-1" />
    </QueryClientProvider>,
  );
}

describe('OnboardingStepper', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it('offers the HR attestation action and refreshes after completion', async () => {
    vi.spyOn(onboardingApi, 'show').mockResolvedValue(onboarding(['profile_completed']));
    const mark = vi.spyOn(onboardingApi, 'markDepartmentTeamNotified').mockResolvedValue(
      onboarding(keys),
    );

    renderStepper();

    const action = await screen.findByRole('button', { name: 'Mark team notified' });
    expect(screen.getByText('HR confirmation is required for this step.')).toBeInTheDocument();

    fireEvent.click(action);

    await waitFor(() => expect(mark).toHaveBeenCalledWith('emp-1'));
    expect(await screen.findByText(/Onboarding completed on/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Mark team notified' })).not.toBeInTheDocument();
  });

  it('shows a retry action when loading status fails', async () => {
    const show = vi
      .spyOn(onboardingApi, 'show')
      .mockRejectedValueOnce(new Error('network error'))
      .mockResolvedValueOnce(onboarding(['profile_completed']));

    renderStepper();

    expect(await screen.findByText('Failed to load onboarding status.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));

    await waitFor(() => expect(show).toHaveBeenCalledTimes(2));
    expect(await screen.findByRole('button', { name: 'Mark team notified' })).toBeInTheDocument();
  });
});
