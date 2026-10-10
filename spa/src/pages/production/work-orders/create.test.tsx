import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { AxiosError } from 'axios';
import { workOrdersApi } from '@/api/production/workOrders';
import CreateWorkOrderPage from './create';
const navigate = vi.hoisted(() => vi.fn());
vi.mock('react-router-dom', async (original) => ({
  ...(await original<typeof import('react-router-dom')>()),
  useNavigate: () => navigate,
}));
vi.mock('@/hooks/useUnsavedChangesGuard', () => ({ useUnsavedChangesGuard: vi.fn() }));
vi.mock('@/api/production/workOrders', () => ({
  workOrdersApi: { formOptions: vi.fn().mockResolvedValue({ products: [] }), create: vi.fn() },
}));
afterEach(() => {
  cleanup();
  vi.useRealTimers();
  navigate.mockClear();
  vi.mocked(workOrdersApi.create).mockReset();
  vi.mocked(workOrdersApi.formOptions)
    .mockReset()
    .mockResolvedValue({ products: [], machines: [], molds: [], shifts: [] });
});
it('shows invalid whole pieces immediately and blocks planning', () => {
  render(
    <MemoryRouter>
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <CreateWorkOrderPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
  fireEvent.change(screen.getByLabelText(/Quantity/), { target: { value: '-1' } });
  expect(screen.getByText('Enter a positive whole-piece quantity.').className).toContain(
    'text-danger-fg',
  );
  expect(screen.getByRole('button', { name: 'Plan production' })).toBeDisabled();
  expect(screen.queryByLabelText(/Sales Order/)).toBeNull();
});

it('disables products with setup blockers and explains unavailable options', async () => {
  vi.mocked(workOrdersApi.formOptions).mockResolvedValueOnce({
    products: [
      {
        id: 'blocked',
        part_number: 'PC-002',
        name: 'Cover',
        stock_production: { eligible: false, blockers: ['Publish QC specification.'] },
      },
    ],
    machines: [],
    molds: [],
    shifts: [],
  });
  render(
    <MemoryRouter>
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <CreateWorkOrderPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
  expect(await screen.findByRole('option', { name: /PC-002/ })).toBeDisabled();
  expect(screen.getByText('Products needing setup (1)')).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText(/Product/), { target: { value: 'blocked' } });
  expect(screen.getByRole('alert')).toHaveTextContent('Publish QC specification.');
  expect(screen.getByRole('button', { name: 'Plan production' })).toBeDisabled();
});

it('preserves entered values after stale setup fails and refreshes product readiness', async () => {
  const ready = {
    id: 'product',
    part_number: 'PC-001',
    name: 'Cover',
    stock_production: { eligible: true, blockers: [] },
  };
  const blocked = {
    ...ready,
    stock_production: { eligible: false, blockers: ['QC specification changed.'] },
  };
  vi.mocked(workOrdersApi.formOptions)
    .mockResolvedValueOnce({ products: [ready], machines: [], molds: [], shifts: [] })
    .mockResolvedValueOnce({ products: [blocked], machines: [], molds: [], shifts: [] });
  vi.mocked(workOrdersApi.create).mockRejectedValueOnce(
    new AxiosError('Setup changed', 'ERR_BAD_REQUEST', undefined, undefined, {
      status: 422,
      statusText: 'Unprocessable Content',
      headers: {},
      config: { headers: {} } as never,
      data: { message: 'QC specification changed.' },
    }),
  );
  render(
    <MemoryRouter>
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <CreateWorkOrderPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
  await screen.findByRole('option', { name: /PC-001/ });
  fireEvent.change(screen.getByRole('combobox', { name: /Product/ }), {
    target: { value: 'product' },
  });
  fireEvent.change(screen.getByLabelText(/Quantity/), { target: { value: '3' } });
  fireEvent.click(screen.getByText('Planning options'));
  fireEvent.change(screen.getByLabelText(/Target completion date/), {
    target: { value: '2099-10-20' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Plan production' }));
  await screen.findByText('Products needing setup (1)');
  expect(screen.getByLabelText(/Quantity/)).toHaveValue(3);
  expect(screen.getByRole('button', { name: 'Plan production' })).toBeDisabled();
});

it('plans from product and quantity using the visible 14-day Manila target', async () => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-10-09T17:30:00Z'));
  vi.mocked(workOrdersApi.formOptions).mockResolvedValueOnce({
    products: [{ id: 'product', part_number: 'PC-001', name: 'Cover' }],
    machines: [],
    molds: [],
    shifts: [],
  });
  vi.mocked(workOrdersApi.create).mockResolvedValueOnce({ id: 'saved' } as never);
  render(
    <MemoryRouter>
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <CreateWorkOrderPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
  await screen.findByRole('option', { name: /PC-001/ });
  expect(screen.getByText(/^14-day planning window/)).toHaveTextContent('Oct 24, 2026');
  expect(screen.queryByLabelText(/Number of workers/)).toBeNull();
  expect(screen.queryByLabelText(/Preferred production day/)).toBeNull();
  fireEvent.change(screen.getByRole('combobox', { name: /Product/ }), {
    target: { value: 'product' },
  });
  fireEvent.change(screen.getByLabelText(/Quantity/), { target: { value: '1000' } });
  fireEvent.click(screen.getByRole('button', { name: 'Plan production' }));
  await waitFor(() =>
    expect(workOrdersApi.create).toHaveBeenCalledWith(
      expect.objectContaining({
        quantity_target: 1000,
        planned_start: '2026-10-09T17:30:00.000Z',
        planned_end: '2026-10-24T23:59:59+08:00',
        minimum_workers: 1,
        maximum_workers: 1,
      }),
    ),
  );
  await waitFor(() => expect(navigate).toHaveBeenCalledWith('/production/work-orders/saved'));
});

it('validates optional target dates immediately and can restore automatic timing', async () => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-10-09T17:30:00Z'));
  vi.mocked(workOrdersApi.formOptions).mockResolvedValueOnce({
    products: [{ id: 'product', part_number: 'PC-001', name: 'Cover' }],
    machines: [],
    molds: [],
    shifts: [],
  });
  render(
    <MemoryRouter>
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <CreateWorkOrderPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
  await screen.findByRole('option', { name: /PC-001/ });
  fireEvent.change(screen.getByRole('combobox', { name: /Product/ }), {
    target: { value: 'product' },
  });
  fireEvent.change(screen.getByLabelText(/Quantity/), { target: { value: '10' } });
  fireEvent.click(screen.getByText('Planning options'));
  const target = screen.getByLabelText(/Target completion date/);
  expect(target).not.toBeRequired();
  fireEvent.change(target, { target: { value: '2026-10-09' } });
  expect(target).toHaveAttribute('aria-invalid', 'true');
  expect(screen.getByText('Choose today or a future date in Manila.').className).toContain(
    'text-danger-fg',
  );
  expect(screen.getByRole('button', { name: 'Plan production' })).toBeDisabled();
  fireEvent.change(target, { target: { value: '2026-10-10' } });
  expect(screen.getByRole('button', { name: 'Plan production' })).toBeEnabled();
  fireEvent.change(target, { target: { value: '' } });
  expect(target).toHaveAttribute('aria-invalid', 'false');
  expect(screen.getByRole('button', { name: 'Plan production' })).toBeEnabled();
});

it('retries the saved request after a transport failure without creating a second stock order', async () => {
  vi.mocked(workOrdersApi.formOptions).mockResolvedValueOnce({
    products: [{ id: 'product', part_number: 'PC-001', name: 'Cover' }],
    machines: [],
    molds: [],
    shifts: [],
  });
  vi.mocked(workOrdersApi.create)
    .mockRejectedValueOnce(new Error('Connection interrupted.'))
    .mockResolvedValueOnce({ id: 'saved' } as never);
  render(
    <MemoryRouter>
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <CreateWorkOrderPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
  await screen.findByRole('option', { name: /PC-001/ });
  fireEvent.change(screen.getByRole('combobox', { name: /Product/ }), {
    target: { value: 'product' },
  });
  fireEvent.change(screen.getByLabelText(/Quantity/), { target: { value: '10' } });
  fireEvent.click(screen.getByRole('button', { name: 'Plan production' }));
  await screen.findByRole('alert');
  expect(navigate).not.toHaveBeenCalled();
  expect(screen.getByLabelText(/Quantity/)).toBeDisabled();
  const original = vi.mocked(workOrdersApi.create).mock.calls[0][0];
  fireEvent.click(screen.getByRole('button', { name: 'Plan production' }));
  await waitFor(() => expect(workOrdersApi.create).toHaveBeenCalledTimes(2));
  expect(vi.mocked(workOrdersApi.create).mock.calls[1][0]).toEqual(original);
  await waitFor(() => expect(navigate).toHaveBeenCalledWith('/production/work-orders/saved'));
});
