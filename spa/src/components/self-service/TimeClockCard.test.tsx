import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { punchApi } from '@/api/attendance/punch';
import { TimeClockCard } from './TimeClockCard';

vi.mock('@/api/attendance/punch', () => ({ punchApi: { status: vi.fn(), punch: vi.fn() } }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const status = {
  next_action: 'in' as const,
  shift: { name: 'Day', start: '06:00', end: '14:00' },
  punches: [],
  tardiness_minutes: null,
  radius_m: 200,
};

function renderCard() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <TimeClockCard />
    </QueryClientProvider>,
  );
}

function mockGeolocation(impl: (ok: PositionCallback, err: PositionErrorCallback) => void) {
  Object.defineProperty(globalThis.navigator, 'geolocation', {
    configurable: true,
    value: { getCurrentPosition: impl },
  });
}

describe('TimeClockCard', () => {
  beforeEach(() => vi.mocked(punchApi.status).mockResolvedValue(status));
  afterEach(() => vi.clearAllMocks());

  it('sends the GPS fix and shows the success state', async () => {
    mockGeolocation((ok) => ok({ coords: { latitude: 14.286, longitude: 120.9345, accuracy: 18 } } as GeolocationPosition));
    vi.mocked(punchApi.punch).mockResolvedValue({ direction: 'in', punched_at: '2026-10-07T06:02:00+08:00', distance_m: '4.00', status: { ...status, next_action: 'out' } });
    renderCard();

    fireEvent.click(await screen.findByRole('button', { name: /time in/i }));

    await waitFor(() => expect(punchApi.punch).toHaveBeenCalledWith({ lat: 14.286, lng: 120.9345, accuracy: 18 }));
  });

  it('explains a denied location permission and never calls the API', async () => {
    mockGeolocation((_ok, err) => err({ code: 1, message: 'denied', PERMISSION_DENIED: 1 } as GeolocationPositionError));
    renderCard();

    fireEvent.click(await screen.findByRole('button', { name: /time in/i }));

    expect(await screen.findByText(/allow location access/i)).toBeInTheDocument();
    expect(punchApi.punch).not.toHaveBeenCalled();
  });

  it('shows the server reason when out of range', async () => {
    mockGeolocation((ok) => ok({ coords: { latitude: 14.3, longitude: 120.95, accuracy: 10 } } as GeolocationPosition));
    vi.mocked(punchApi.punch).mockRejectedValue({ response: { status: 422, data: { message: 'You are 2600 m from the plant.', code: 'out_of_range' } } });
    renderCard();

    fireEvent.click(await screen.findByRole('button', { name: /time in/i }));

    expect(await screen.findByText(/2600 m from the plant/i)).toBeInTheDocument();
  });

  it('disables the button while the punch is pending', async () => {
    mockGeolocation((ok) => ok({ coords: { latitude: 14.286, longitude: 120.9345, accuracy: 18 } } as GeolocationPosition));
    vi.mocked(punchApi.punch).mockReturnValue(new Promise(() => {}));
    renderCard();

    const btn = await screen.findByRole('button', { name: /time in/i });
    fireEvent.click(btn);

    await waitFor(() => expect(screen.getByRole('button', { name: /locating|punching/i })).toBeDisabled());
  });
});
