import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { assetsApi } from '@/api/assets';
import type { Asset } from '@/types/assets';
import AssetDetailPage from './detail';

const permissionState = vi.hoisted(() => ({ canUpdate: true }));

vi.mock('@/api/assets', () => ({
  assetsApi: {
    show: vi.fn(),
    qr: vi.fn(),
    options: vi.fn(),
    associationOptions: vi.fn(),
    associate: vi.fn(),
    dispose: vi.fn(),
    approveDisposal: vi.fn(),
    rejectDisposal: vi.fn(),
    cancelDisposal: vi.fn(),
  },
}));
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({ can: (permission: string) => permission === 'assets.update' && permissionState.canUpdate }),
}));
vi.mock('qrcode', () => ({ default: { toDataURL: vi.fn().mockResolvedValue('data:image/png;base64,qr') } }));

const vehicleAsset = (): Asset => ({
  id: 'asset-1',
  asset_code: 'AST-001',
  name: 'Delivery van',
  description: null,
  category: 'vehicle',
  category_label: 'Vehicle',
  association: null,
  acquisition_date: '2026-01-01',
  acquisition_cost: '100.00',
  useful_life_years: 5,
  depreciation_method: 'straight_line',
  salvage_value: '0.00',
  accumulated_depreciation: '0.00',
  monthly_depreciation: '1.67',
  book_value: '100.00',
  status: 'active',
  disposed_date: null,
  disposal_amount: null,
  disposal_reason: null,
  disposal_request: null,
  location: null,
  insurance_policy_no: null,
  insurance_provider: null,
  insurance_expiry: null,
  insured_value: null,
  depreciations: [],
  created_at: null,
  updated_at: null,
});

function renderPage(value: Asset = vehicleAsset(), afterAssociation: Asset = {
  ...vehicleAsset(),
  association: { type: 'vehicle', id: 'vehicle-1', code: 'VAN-001', name: 'Delivery van' },
}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  vi.mocked(assetsApi.show).mockResolvedValueOnce(value).mockResolvedValue(afterAssociation);
  vi.mocked(assetsApi.qr).mockResolvedValue({ asset_code: 'AST-001', name: 'Delivery van', url: 'https://ogami.test/assets/AST-001' });
  vi.mocked(assetsApi.options).mockResolvedValue({
    categories: [{ value: 'vehicle', label: 'Vehicle' }],
    statuses: [{ value: 'active', label: 'Active' }],
  });
  vi.mocked(assetsApi.associationOptions).mockResolvedValue({
    data: [{ id: 'vehicle-1', code: 'VAN-001', name: 'Delivery van' }],
    meta: { current_page: 1, last_page: 1, per_page: 50, total: 1, from: 1, to: 1 },
    links: { first: '', last: '', prev: null, next: null },
  });
  vi.mocked(assetsApi.associate).mockResolvedValue(afterAssociation);

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/assets/asset-1']}>
        <Routes><Route path="/assets/:id" element={<AssetDetailPage />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('asset operational association', () => {
  beforeEach(() => {
    permissionState.canUpdate = true;
    vi.clearAllMocks();
  });

  it('offers an authorized asset editor a way to link an unassociated vehicle', async () => {
    renderPage();

    expect(await screen.findByText('Operational association')).toBeInTheDocument();
    expect(screen.getByText('No operational record linked.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Associate vehicle' })).toBeEnabled();
  });

  it('saves the selected vehicle through the asset association endpoint', async () => {
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Associate vehicle' }));
    fireEvent.change(await screen.findByLabelText('Vehicle'), { target: { value: 'vehicle-1' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save association' }));

    expect(await screen.findByText('VAN-001')).toBeInTheDocument();
    expect(assetsApi.associate).toHaveBeenCalledWith('asset-1', {
      target_type: 'vehicle',
      target_id: 'vehicle-1',
    });
  });

  it('requires confirmation and clears the current link', async () => {
    const linked = {
      ...vehicleAsset(),
      association: { type: 'vehicle' as const, id: 'vehicle-1', code: 'VAN-001', name: 'Delivery van' },
    };
    renderPage(linked, vehicleAsset());

    fireEvent.click(await screen.findByRole('button', { name: 'Clear association' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText(/VAN-001/)).toBeInTheDocument();
    fireEvent.click(within(dialog).getByRole('button', { name: 'Clear association' }));

    expect(await screen.findByText('No operational record linked.')).toBeInTheDocument();
    expect(assetsApi.associate).toHaveBeenCalledWith('asset-1', {
      target_type: 'vehicle',
      target_id: null,
    });
  });

  it('keeps the association read-only without assets.update permission', async () => {
    permissionState.canUpdate = false;
    renderPage({
      ...vehicleAsset(),
      association: { type: 'vehicle', id: 'vehicle-1', code: 'VAN-001', name: 'Delivery van' },
    });

    expect(await screen.findByText('VAN-001')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Clear association' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Associate vehicle' })).not.toBeInTheDocument();
  });

  it('does not offer association changes for disposed assets', async () => {
    renderPage({ ...vehicleAsset(), status: 'disposed' });

    expect(await screen.findByText('Disposed assets cannot be associated with operational records.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Associate vehicle' })).not.toBeInTheDocument();
  });
});
