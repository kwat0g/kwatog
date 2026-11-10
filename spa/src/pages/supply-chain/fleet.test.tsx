import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Vehicle } from '@/types/supplyChain';
import FleetPage from './fleet';

const mocks = vi.hoisted(() => ({
  list: vi.fn(),
  options: vi.fn(),
  update: vi.fn(),
  record: vi.fn(),
  listCredentials: vi.fn(),
  saveCredential: vi.fn(),
}));

vi.mock('@/api/supply-chain', () => ({
  vehiclesApi: {
    list: mocks.list,
    options: mocks.options,
    update: mocks.update,
    create: vi.fn(),
    destroy: vi.fn(),
    restore: vi.fn(),
  },
  driverCredentialsApi: { list: mocks.listCredentials, update: mocks.saveCredential },
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/hooks/useRealtimeRecord', () => ({
  useRealtimeList: vi.fn(),
  useRealtimeRecord: mocks.record,
}));

const vehicle: Vehicle = {
  id: 'truck-1',
  plate_number: 'ABC-123',
  name: 'Molding truck',
  vehicle_type: 'truck',
  capacity_kg: 2500,
  status: 'available',
  notes: null,
  version: '2026-11-02T01:00:00.000Z',
  permissions: { manage: true },
  available_actions: ['update', 'archive'],
};

describe('Fleet edit realtime conflict handling', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.list.mockResolvedValue({
      data: [vehicle],
      meta: { current_page: 1, from: 1, last_page: 1, per_page: 50, to: 1, total: 1 },
    });
    mocks.options.mockResolvedValue({
      types: [{ value: 'truck', label: 'Truck' }],
      statuses: [{ value: 'available', label: 'Available' }, { value: 'maintenance', label: 'Maintenance' }],
    });
    mocks.listCredentials.mockResolvedValue([]);
  });

  it('subscribes to the edited vehicle and disables stale saves when it changes remotely', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <FleetPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    const editButtons = await screen.findAllByRole('button', { name: 'Edit' });
    fireEvent.click(editButtons[0]);
    const saveButton = await screen.findByRole('button', { name: 'Save vehicle' });
    expect(mocks.record).toHaveBeenCalledWith('vehicle', 'truck-1', [], expect.any(Function));

    const onRemoteChange = mocks.record.mock.calls.at(-1)?.[3] as (() => void) | undefined;
    act(() => onRemoteChange?.());

    expect(screen.getByRole('alert')).toHaveTextContent(/changed while you were editing/i);
    expect(saveButton).toBeDisabled();
    queryClient.clear();
  });

  it('shows the update response in the actor list before the list refetch completes', async () => {
    let resolveRefetch: ((page: { data: Vehicle[]; meta: { current_page: number; from: number; last_page: number; per_page: number; to: number; total: number } }) => void) | undefined;
    mocks.list
      .mockResolvedValueOnce({
        data: [vehicle],
        meta: { current_page: 1, from: 1, last_page: 1, per_page: 50, to: 1, total: 1 },
      })
      .mockImplementationOnce(() => new Promise((resolve) => { resolveRefetch = resolve; }));
    const updated = { ...vehicle, name: 'Updated fleet truck', version: '2026-11-02T01:05:00.000Z' };
    mocks.update.mockResolvedValue(updated);

    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <FleetPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.click((await screen.findAllByRole('button', { name: 'Edit' }))[0]);
    fireEvent.change(screen.getByRole('textbox', { name: 'Name' }), { target: { value: 'Updated fleet truck' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save vehicle' }));

    await screen.findAllByText('Updated fleet truck');
    expect(mocks.update).toHaveBeenCalledWith('truck-1', expect.objectContaining({ name: 'Updated fleet truck' }));

    act(() => resolveRefetch?.({
      data: [updated],
      meta: { current_page: 1, from: 1, last_page: 1, per_page: 50, to: 1, total: 1 },
    }));
    queryClient.clear();
  });

  it('fails closed when the server action projection is missing', async () => {
    const legacyVehicle = { ...vehicle };
    delete legacyVehicle.available_actions;
    mocks.list.mockResolvedValue({
      data: [legacyVehicle],
      meta: { current_page: 1, from: 1, last_page: 1, per_page: 50, to: 1, total: 1 },
    });
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter><FleetPage /></MemoryRouter>
      </QueryClientProvider>,
    );

    const row = await screen.findByRole('row', { name: /ABC-123/ });
    expect(within(row).queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();
    expect(within(row).queryByRole('button', { name: 'Archive' })).not.toBeInTheDocument();
  });
});

describe('Driver license credentials', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.list.mockResolvedValue({
      data: [vehicle],
      meta: { current_page: 1, from: 1, last_page: 1, per_page: 50, to: 1, total: 1 },
    });
    mocks.options.mockResolvedValue({
      types: [{ value: 'truck', label: 'Truck' }],
      statuses: [{ value: 'available', label: 'Available' }, { value: 'maintenance', label: 'Maintenance' }],
    });
    mocks.listCredentials.mockResolvedValue([{
      id: 'driver-1', name: 'Nestor Flores',
      credential: {
        license_number: 'DL-2024-11', expires_at: '2025-11-01', status: 'expired',
        document_url: '/api/v1/supply-chain/driver-credentials/cred-1/document',
      },
    }]);
  });

  it('shows expired credential state and lets a fleet manager replace the license document', async () => {
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>
        <MemoryRouter><FleetPage /></MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByRole('heading', { name: 'Driver credentials' })).toBeVisible();
    expect((await screen.findAllByText('DL-2024-11')).length).toBeGreaterThan(0);
    expect((await screen.findAllByText('Expired')).length).toBeGreaterThan(0);
    fireEvent.click(screen.getAllByRole('button', { name: 'Update license for Nestor Flores' })[0]);

    expect(screen.getByRole('link', { name: 'View current license document' })).toHaveAttribute('href', '/api/v1/supply-chain/driver-credentials/cred-1/document');
    fireEvent.change(screen.getByRole('textbox', { name: 'License identifier' }), { target: { value: 'DL-2027-44' } });
    fireEvent.change(screen.getByLabelText('License expiry', { exact: false }), { target: { value: '2027-10-31' } });
    const file = new File(['license'], 'license.pdf', { type: 'application/pdf' });
    fireEvent.change(screen.getByLabelText('License document', { exact: false }), { target: { files: [file] } });
    fireEvent.click(screen.getByRole('button', { name: 'Save driver credential' }));

    await waitFor(() => expect(mocks.saveCredential).toHaveBeenCalledWith('driver-1', expect.objectContaining({
      license_number: 'DL-2027-44', expires_at: '2027-10-31', credential_document: file,
    })));
  });
});
