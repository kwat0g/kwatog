import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Employee } from '@/types/hr';
import { EmployeeForm } from './EmployeeForm';
import { departmentsApi } from '@/api/hr/departments';
import { positionsApi } from '@/api/hr/positions';
import { employeesApi } from '@/api/hr/employees';
import { manilaIsoDate } from '@/lib/formatDate';

vi.mock('@/api/hr/departments', () => ({
  departmentsApi: {
    tree: vi.fn().mockResolvedValue([{ id: 'd1', name: 'IT', code: 'IT', children: [] }]),
  },
}));
vi.mock('@/api/hr/positions', () => ({
  positionsApi: {
    list: vi.fn().mockResolvedValue({ data: [{ id: 'p1', title: 'Admin', department_id: 'd1' }] }),
  },
}));
vi.mock('@/api/hr/employees', () => ({
  employeesApi: {
    options: vi.fn().mockResolvedValue({
      employment_types: [{ value: 'regular', label: 'Regular' }],
      pay_types: [{ value: 'monthly', label: 'Monthly' }],
      genders: [{ value: 'male', label: 'Male' }],
      civil_statuses: [{ value: 'single', label: 'Single' }],
    }),
  },
}));
vi.mock('@/api/attendance/shifts', () => ({
  shiftsApi: {
    list: vi.fn().mockResolvedValue({
      data: [
        {
          id: 'day',
          name: 'Day Shift',
          start_time: '06:00:00',
          end_time: '14:00:00',
          is_active: true,
        },
        {
          id: 'night',
          name: 'Night Shift',
          start_time: '18:00:00',
          end_time: '06:00:00',
          is_active: true,
        },
      ],
    }),
  },
}));

const employee = {
  id: 'e1',
  employee_no: 'OGM-2026-0001',
  first_name: 'Manuel',
  middle_name: null,
  last_name: 'Cruz',
  suffix: null,
  full_name: 'Manuel Cruz',
  birth_date: '1990-01-01',
  gender: 'male',
  civil_status: 'single',
  nationality: 'Filipino',
  address: {
    street: '1 Main',
    barangay: 'B',
    city: 'Dasmariñas',
    province: 'Cavite',
    zip_code: '4114',
  },
  contact: {
    mobile_number: '09171234567',
    email: 'manuel@example.test',
    emergency_contact_name: 'Ana',
    emergency_contact_relation: 'Spouse',
    emergency_contact_phone: '09181234567',
  },
  employment_type: 'regular',
  pay_type: 'monthly',
  date_hired: '2024-10-07',
  date_regularized: '2025-10-07',
  basic_monthly_salary: '25000.00',
  semi_monthly_rate: null,
  bank_name: null,
  sss_no: null,
  philhealth_no: null,
  pagibig_no: null,
  tin: null,
  bank_account_no: null,
  department: { id: 'd1', name: 'IT' },
  position: { id: 'p1', title: 'Admin' },
} as unknown as Employee;

function renderForm(props: Partial<React.ComponentProps<typeof EmployeeForm>> = {}) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <EmployeeForm
          employee={employee}
          onSubmit={vi.fn()}
          onCancel={vi.fn()}
          isPending={false}
          submitLabel="Save changes"
          initialShift={{ shift_id: 'night', working_days: [1, 2, 3, 4, 5] }}
          {...props}
        />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const save = () => screen.getByRole('button', { name: /save changes/i });
const day = (name: string) => screen.getByRole('button', { name });

it('keeps recruitment defaults when select options arrive after create form mounts', async () => {
  let departmentsReady!: (value: Awaited<ReturnType<typeof departmentsApi.tree>>) => void;
  let positionsReady!: (value: Awaited<ReturnType<typeof positionsApi.list>>) => void;
  let optionsReady!: (value: Awaited<ReturnType<typeof employeesApi.options>>) => void;
  vi.mocked(departmentsApi.tree).mockImplementationOnce(() => new Promise(resolve => { departmentsReady = resolve; }));
  vi.mocked(positionsApi.list).mockImplementationOnce(() => new Promise(resolve => { positionsReady = resolve; }));
  vi.mocked(employeesApi.options).mockImplementationOnce(() => new Promise(resolve => { optionsReady = resolve; }));
  renderForm({ employee: { ...employee, id: undefined } as unknown as Employee, initialShift: null });
  fireEvent.change(await screen.findByLabelText(/first name/i), { target: { value: 'Jamie' } });
  departmentsReady([{ id: 'd1', name: 'IT', code: 'IT' }] as Awaited<ReturnType<typeof departmentsApi.tree>>);
  positionsReady({ data: [{ id: 'p1', title: 'Admin', department_id: 'd1' }] } as Awaited<ReturnType<typeof positionsApi.list>>);
  optionsReady({ employment_types: [{ value: 'regular', label: 'Regular' }], pay_types: [{ value: 'monthly', label: 'Monthly' }], genders: [], civil_statuses: [], statuses: [], separation_reasons: [], skill_levels: [] });
  await screen.findByRole('option', { name: 'Admin' });
  expect(screen.getByLabelText(/^department/i)).toHaveValue('d1');
  expect(screen.getByLabelText(/^position/i)).toHaveValue('p1');
  expect(screen.getByLabelText(/employment type/i)).toHaveValue('regular');
  expect(screen.getByLabelText(/first name/i)).toHaveValue('Jamie');
});

it('defaults new employee hire date to today in Manila', async () => {
  renderForm({ employee: null, initialShift: null });
  expect(await screen.findByLabelText(/date hired/i)).toHaveValue(manilaIsoDate());
});

it('locks posting fields during conversion and retains their values when submitting', async () => {
  const onSubmit = vi.fn();
  renderForm({ employee: { ...employee, id: undefined } as unknown as Employee, initialShift: null, lockPostingFields: true, onSubmit });
  await screen.findByRole('option', { name: 'Admin' });
  expect(screen.getByLabelText(/^department/i)).toBeDisabled();
  expect(screen.getByLabelText(/^position/i)).toBeDisabled();
  expect(screen.getByLabelText(/employment type/i)).toBeDisabled();
  expect(screen.getByLabelText(/date hired/i)).toBeEnabled();
  expect(screen.getByLabelText(/pay type/i)).toBeEnabled();
  fireEvent.change(screen.getByLabelText(/^gender/i), { target: { value: 'male' } });
  fireEvent.change(screen.getByLabelText(/civil status/i), { target: { value: 'single' } });
  fireEvent.change(screen.getByLabelText(/pay type/i), { target: { value: 'monthly' } });
  fireEvent.click(save());
  await waitFor(() => expect(onSubmit).toHaveBeenCalled());
  expect(onSubmit.mock.calls[0][0]).toMatchObject({ department_id: 'd1', position_id: 'p1', employment_type: 'regular' });
});

it('allows selecting position when recruitment posting has no position', async () => {
  renderForm({ employee: { ...employee, id: undefined, position: null } as unknown as Employee, initialShift: null, lockPostingFields: true });
  await screen.findByRole('option', { name: 'Admin' });
  expect(screen.getByLabelText(/^department/i)).toBeDisabled();
  expect(screen.getByLabelText(/^position/i)).toBeEnabled();
});

describe('EmployeeForm edit mode', () => {
  beforeEach(() => vi.clearAllMocks());

  it('opens fully prefilled: employee data, current shift and working days', async () => {
    renderForm();

    expect(((await screen.findByLabelText(/first name/i)) as HTMLInputElement).value).toBe(
      'Manuel',
    );
    expect((screen.getByLabelText(/last name/i) as HTMLInputElement).value).toBe('Cruz');
    expect((screen.getByLabelText(/gender/i) as HTMLSelectElement).value).toBe('male');
    expect((screen.getByLabelText(/civil status/i) as HTMLSelectElement).value).toBe('single');
    expect((screen.getByLabelText(/^department/i) as HTMLSelectElement).value).toBe('d1');
    await waitFor(() =>
      expect((screen.getByLabelText('Shift') as HTMLSelectElement).value).toBe('night'),
    );
    expect(day('Monday')).toHaveAttribute('aria-pressed', 'true');
    expect(day('Friday')).toHaveAttribute('aria-pressed', 'true');
    expect(day('Saturday')).toHaveAttribute('aria-pressed', 'false');
    expect(day('Sunday')).toHaveAttribute('aria-pressed', 'false');
  });

  it('keeps Save disabled until something changes, and disables it again when the change is undone', async () => {
    renderForm();
    await waitFor(() =>
      expect((screen.getByLabelText('Shift') as HTMLSelectElement).value).toBe('night'),
    );

    expect(save()).toBeDisabled();
    expect(screen.getByText('No changes to save.')).toBeInTheDocument();

    fireEvent.click(day('Saturday'));
    expect(save()).toBeEnabled();
    expect(screen.getByText('You have unsaved changes.')).toBeInTheDocument();

    fireEvent.click(day('Saturday'));
    expect(save()).toBeDisabled();
  });

  it('enables Save when an ordinary field changes', async () => {
    renderForm();
    await waitFor(() =>
      expect((screen.getByLabelText('Shift') as HTMLSelectElement).value).toBe('night'),
    );

    fireEvent.change(await screen.findByLabelText(/first name/i), { target: { value: 'Manny' } });

    expect(save()).toBeEnabled();
  });

  it('puts the working-days picker inside the Shift assignment section', async () => {
    renderForm();
    const section = (await screen.findByText('Shift assignment')).closest(
      'fieldset',
    ) as HTMLElement;

    expect(within(section).getByRole('button', { name: 'Monday' })).toBeInTheDocument();
    expect(within(section).getByText('Working days')).toBeInTheDocument();
  });

  it('explains a shift change and its start date', async () => {
    renderForm();
    await waitFor(() =>
      expect((screen.getByLabelText('Shift') as HTMLSelectElement).value).toBe('night'),
    );

    fireEvent.change(screen.getByLabelText('Shift'), { target: { value: 'day' } });

    expect(
      await screen.findByText(/saving assigns this shift starting today/i),
    ).toBeInTheDocument();
  });

  it('says so when no custom schedule is set', async () => {
    renderForm({ initialShift: { shift_id: 'night', working_days: null } });

    expect(await screen.findByText(/monday to saturday is assumed/i)).toBeInTheDocument();
    expect(day('Saturday')).toHaveAttribute('aria-pressed', 'true');
    expect(day('Sunday')).toHaveAttribute('aria-pressed', 'false');
  });

  it('refuses to save a shift with no working day selected, with an inline message', async () => {
    const onSubmit = vi.fn();
    renderForm({ onSubmit, initialShift: { shift_id: 'night', working_days: [1] } });
    await waitFor(() =>
      expect((screen.getByLabelText('Shift') as HTMLSelectElement).value).toBe('night'),
    );

    fireEvent.click(day('Monday')); // now none selected
    fireEvent.click(save());

    expect(await screen.findAllByText(/select at least one working day/i)).not.toHaveLength(0);
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('shows the shift read-only without permission to manage shifts', async () => {
    renderForm({ canAssignShift: false });

    expect(
      await screen.findByText(/night shift .* you do not have access to change shifts/i),
    ).toBeInTheDocument();
    expect(screen.queryByLabelText('Shift')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Monday' })).not.toBeInTheDocument();
  });

  it('shows bank details read-only on edit, with the way to change them', async () => {
    renderForm();

    expect(((await screen.findByLabelText('Bank name')) as HTMLInputElement).readOnly).toBe(true);
    expect((screen.getByLabelText('Account number') as HTMLInputElement).readOnly).toBe(true);
    expect(screen.getByRole('link', { name: /open bank account changes/i })).toHaveAttribute(
      'href',
      '/hr/profile-update-requests',
    );
  });
});
