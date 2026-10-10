import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { AxiosError, AxiosHeaders, type AxiosResponse } from 'axios';
import { beforeEach, expect, it, vi } from 'vitest';
import { recruitmentApi } from '@/api/recruitment';
import type { JobApplication } from '@/types/recruitment';
import ApplicationDetailPage from './detail';

const permissions = vi.hoisted(() => ({ manage: true }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => permissions.manage }) }));
vi.mock('@/api/recruitment', () => ({ recruitmentApi: {
  showApplication: vi.fn(), options: vi.fn(), changeStage: vi.fn(), scheduleInterview: vi.fn(),
  updateInterview: vi.fn(), addNote: vi.fn(), downloadResume: vi.fn(),
} }));

const application: JobApplication = {
  id: 'app-1', application_number: 'JA-202610-0001', tracking_code: 'RCT-XUA9JS',
  first_name: 'Jamie', last_name: 'Reyes', full_name: 'Jamie Reyes',
  email: 'jamie@test.com', phone: '09000000000', cover_letter: 'Available for shift work.',
  stage: 'screening', stage_label: 'Screening', rejected_at_stage: null, rejection_reason: null,
  applied_at: '2026-10-10T01:00:00Z', created_at: '2026-10-10T01:00:00Z', updated_at: '2026-10-10T01:00:00Z',
  interviews: [], notes: [], history: [],
};

beforeEach(() => {
  vi.resetAllMocks();
  permissions.manage = true;
  vi.mocked(recruitmentApi.showApplication).mockResolvedValue({ data: { data: application } } as AxiosResponse<{ data: JobApplication }>);
  vi.mocked(recruitmentApi.options).mockResolvedValue({ data: { data: {
    employment_types: [], posting_statuses: [], application_stages: [
      { value: 'new', label: 'New', is_terminal: false, next: 'screening' },
      { value: 'screening', label: 'Screening', is_terminal: false, next: 'interview' },
      { value: 'interview', label: 'Interview', is_terminal: false, next: 'offer' },
      { value: 'offer', label: 'Offer', is_terminal: false, next: 'hired' },
      { value: 'hired', label: 'Hired', is_terminal: true, next: null },
      { value: 'rejected', label: 'Rejected', is_terminal: true, next: null },
    ],
  } }, status: 200, statusText: 'OK', headers: {}, config: { headers: new AxiosHeaders() } });
});

function show(stage = application.stage) {
  vi.mocked(recruitmentApi.showApplication).mockResolvedValue({ data: { data: { ...application, stage } } } as AxiosResponse<{ data: JobApplication }>);
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/hr/recruitment/applications/app-1']}>
        <Routes><Route path="/hr/recruitment/applications/:id" element={<ApplicationDetailPage />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

function fillInterview(dialog: HTMLElement, date = '2099-10-13T10:30') {
  fireEvent.change(within(dialog).getByLabelText(/Date & Time/), { target: { value: date } });
  fireEvent.change(within(dialog).getByLabelText(/Interviewer/), { target: { value: 'Maria Santos' } });
  fireEvent.change(within(dialog).getByLabelText(/Location/), { target: { value: 'HR meeting room' } });
}

it('opens interview advancement in a modal, validates and submits existing stage payload', async () => {
  show();
  fireEvent.click(await screen.findByRole('button', { name: 'Move to Interview' }));
  const dialog = await screen.findByRole('dialog', { name: 'Schedule interview' });
  expect(screen.getAllByLabelText(/Date & Time/)).toHaveLength(1);
  fillInterview(dialog, '2020-01-01T10:00');
  fireEvent.submit(within(dialog).getByRole('button', { name: 'Schedule & Move to Interview' }).closest('form')!);
  expect(await within(dialog).findByText('Interview must be scheduled in the future.')).toBeInTheDocument();
  expect(recruitmentApi.changeStage).not.toHaveBeenCalled();
  fillInterview(dialog);
  fireEvent.click(within(dialog).getByRole('button', { name: 'Schedule & Move to Interview' }));
  await waitFor(() => expect(recruitmentApi.changeStage).toHaveBeenCalledWith('app-1', {
    action: 'advance', interview: { scheduled_at: new Date('2099-10-13T10:30').toISOString(), location: 'HR meeting room', interviewer_name: 'Maria Santos' },
  }));
  await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
});

it('cancels interview modal without a mutation and clears draft on reopening', async () => {
  show();
  fireEvent.click(await screen.findByRole('button', { name: 'Move to Interview' }));
  fillInterview(await screen.findByRole('dialog'));
  fireEvent.keyDown(document, { key: 'Escape' });
  await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
  expect(recruitmentApi.changeStage).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: 'Move to Interview' }));
  expect(within(await screen.findByRole('dialog')).getByLabelText(/Interviewer/)).toHaveValue('');
});

it('keeps entered details and maps nested server errors after failed advancement', async () => {
  vi.mocked(recruitmentApi.changeStage).mockRejectedValue(new AxiosError('Request failed with status code 422', '422', undefined, undefined, {
    status: 422, data: { errors: { 'interview.interviewer_name': ['Choose another interviewer.'] } },
  } as AxiosResponse));
  show();
  fireEvent.click(await screen.findByRole('button', { name: 'Move to Interview' }));
  const dialog = await screen.findByRole('dialog');
  fillInterview(dialog);
  fireEvent.click(within(dialog).getByRole('button', { name: 'Schedule & Move to Interview' }));
  expect(await within(dialog).findByText('Choose another interviewer.')).toBeInTheDocument();
  expect(within(dialog).getByLabelText(/Date & Time/)).toHaveValue('2099-10-13T10:30');
});

it('schedules an additional interview through the same modal without advancing stage', async () => {
  show('interview');
  fireEvent.click(await screen.findByRole('button', { name: 'Schedule' }));
  const dialog = await screen.findByRole('dialog', { name: 'Schedule interview' });
  fillInterview(dialog);
  fireEvent.click(within(dialog).getByRole('button', { name: 'Schedule interview' }));
  await waitFor(() => expect(recruitmentApi.scheduleInterview).toHaveBeenCalledWith('app-1', {
    scheduled_at: new Date('2099-10-13T10:30').toISOString(), location: 'HR meeting room', interviewer_name: 'Maria Santos',
  }));
  expect(recruitmentApi.changeStage).not.toHaveBeenCalled();
});

it('keeps notes in the sidebar and submits trimmed notes', async () => {
  show();
  const sidebar = await screen.findByRole('complementary', { name: 'Application summary and notes' });
  const note = within(sidebar).getByLabelText('Add a note');
  fireEvent.change(note, { target: { value: '  Screening completed.  ' } });
  fireEvent.click(within(sidebar).getByRole('button', { name: 'Add note' }));
  await waitFor(() => expect(recruitmentApi.addNote).toHaveBeenCalledWith('app-1', 'Screening completed.'));
});

it('rejects through a modal with a reason', async () => {
  show();
  fireEvent.click(await screen.findByRole('button', { name: 'Reject' }));
  const dialog = await screen.findByRole('dialog', { name: 'Reject application' });
  const confirm = within(dialog).getByRole('button', { name: 'Confirm Reject' });
  expect(confirm).toBeDisabled();
  fireEvent.change(within(dialog).getByLabelText(/Rejection reason/), { target: { value: 'Does not meet requirements.' } });
  fireEvent.click(confirm);
  await waitFor(() => expect(recruitmentApi.changeStage).toHaveBeenCalledWith('app-1', { action: 'reject', rejection_reason: 'Does not meet requirements.' }));
});

it('hides editing controls without recruitment permission', async () => {
  permissions.manage = false;
  show();
  await screen.findByText('Jamie Reyes');
  expect(screen.queryByRole('button', { name: /Move to/ })).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Add a note')).not.toBeInTheDocument();
});
