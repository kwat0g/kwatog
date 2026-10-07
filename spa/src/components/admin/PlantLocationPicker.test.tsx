import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import toast from 'react-hot-toast';
import { settingsApi, type SettingRow } from '@/api/admin/settings';
import { PlantLocationPicker } from './PlantLocationPicker';

vi.mock('@/api/admin/settings', () => ({ settingsApi: { update: vi.fn() } }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));
// Leaflet needs a real layout engine; the picker's behaviour lives in the form.
vi.mock('./PlantLocationMap', () => ({
  default: ({ onPick, editable }: { onPick: (lat: number, lng: number) => void; editable: boolean }) => (
    <button type="button" data-testid="map" data-editable={String(editable)} onClick={() => onPick(14.3, 120.95)}>
      map
    </button>
  ),
}));

const row = (key: string, value: unknown): SettingRow => ({
  key,
  value: value as SettingRow['value'],
  group: 'company',
  label: key,
  description: null,
});

const ROWS = [
  row('company.latitude', 14.286),
  row('company.longitude', 120.9345),
  row('company.punch_radius_m', 200),
];

function renderPicker(canEdit = true) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return render(
    <QueryClientProvider client={qc}>
      <PlantLocationPicker rows={ROWS} canEdit={canEdit} />
    </QueryClientProvider>,
  );
}

const lat = () => screen.getByLabelText('Latitude') as HTMLInputElement;
const lng = () => screen.getByLabelText('Longitude') as HTMLInputElement;
const radius = () => screen.getByLabelText('Radius') as HTMLInputElement;
const saveButton = () => screen.getByRole('button', { name: /save plant location/i });

describe('PlantLocationPicker', () => {
  afterEach(() => vi.clearAllMocks());

  it('shows the saved location and keeps Save disabled until something changes', async () => {
    renderPicker();

    expect(lat().value).toBe('14.286');
    expect(lng().value).toBe('120.9345');
    expect(radius().value).toBe('200');
    expect(saveButton()).toBeDisabled();
    expect(await screen.findByTestId('map')).toBeInTheDocument();
  });

  it('fills both fields when a "lat, lng" pair is pasted into the latitude field', () => {
    renderPicker();

    fireEvent.change(lat(), { target: { value: '14.3000, 120.9500' } });

    expect(lat().value).toBe('14.3');
    expect(lng().value).toBe('120.95');
    expect(screen.getByText('Unsaved changes')).toBeInTheDocument();
    expect(saveButton()).toBeEnabled();
  });

  it('moves the fields when the pin is placed on the map', async () => {
    renderPicker();

    fireEvent.click(await screen.findByTestId('map'));

    expect(lat().value).toBe('14.3');
    expect(lng().value).toBe('120.95');
  });

  it('refuses an out-of-range value, says why, and blocks Save', () => {
    renderPicker();

    fireEvent.change(lat(), { target: { value: '95' } });

    expect(screen.getByText(/between -90 and 90/i)).toBeInTheDocument();
    expect(saveButton()).toBeDisabled();
  });

  it('saves latitude, longitude and radius as numbers, one setting at a time', async () => {
    vi.mocked(settingsApi.update).mockResolvedValue({} as SettingRow);
    renderPicker();

    fireEvent.change(lat(), { target: { value: '14.3' } });
    fireEvent.change(lng(), { target: { value: '120.95' } });
    fireEvent.change(radius(), { target: { value: '350' } });
    fireEvent.click(saveButton());

    await waitFor(() => expect(settingsApi.update).toHaveBeenCalledTimes(3));
    expect(settingsApi.update).toHaveBeenNthCalledWith(1, 'company.latitude', 14.3);
    expect(settingsApi.update).toHaveBeenNthCalledWith(2, 'company.longitude', 120.95);
    expect(settingsApi.update).toHaveBeenNthCalledWith(3, 'company.punch_radius_m', 350);
    expect(toast.success).toHaveBeenCalledWith('Plant location saved');
  });

  it('puts the saved values back on Reset', () => {
    renderPicker();

    fireEvent.change(radius(), { target: { value: '999' } });
    fireEvent.click(screen.getByRole('button', { name: /reset/i }));

    expect(radius().value).toBe('200');
    expect(saveButton()).toBeDisabled();
  });

  it('is read-only outside edit mode: no actions, fields locked, map not editable', async () => {
    renderPicker(false);

    expect(screen.queryByRole('button', { name: /save plant location/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /use my current location/i })).not.toBeInTheDocument();
    expect(lat().readOnly).toBe(true);
    expect((await screen.findByTestId('map')).dataset.editable).toBe('false');
  });
});
