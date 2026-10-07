import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import toast from 'react-hot-toast';
import { punchApi } from '@/api/attendance/punch';
import { TimeClockQuickAction } from './TimeClockQuickAction';

vi.mock('@/api/attendance/punch', () => ({ punchApi: { status: vi.fn(), punch: vi.fn() } }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@/hooks/useFeature', () => ({ useFeature: vi.fn(() => true) }));

let mockEmployee: object | null = { id: 'e1' };
vi.mock('@/stores/authStore', () => ({
  useAuthStore: (select: (s: { user: { employee: object | null } }) => unknown) =>
    select({ user: { employee: mockEmployee } }),
}));

const STATUS = {
  next_action: 'in' as const,
  shift: { name: 'Day', start: '06:00', end: '14:00' },
  punches: [],
  tardiness_minutes: null,
  radius_m: 200,
};

function renderAction() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <TimeClockQuickAction />
    </QueryClientProvider>,
  );
}

function geolocation(impl: (ok: PositionCallback, err: PositionErrorCallback) => void) {
  const getCurrentPosition = vi.fn(impl);
  Object.defineProperty(globalThis.navigator, 'geolocation', { configurable: true, value: { getCurrentPosition } });

  return getCurrentPosition;
}

describe('TimeClockQuickAction', () => {
  beforeEach(() => {
    mockEmployee = { id: 'e1' };
    Object.defineProperty(globalThis.navigator, 'permissions', { configurable: true, value: undefined });
    vi.mocked(punchApi.status).mockResolvedValue(STATUS);
  });
  afterEach(() => vi.clearAllMocks());

  it('renders nothing for an account with no linked employee, and never loads the status', () => {
    mockEmployee = null;
    const { container } = renderAction();

    expect(container).toBeEmptyDOMElement();
    expect(punchApi.status).not.toHaveBeenCalled();
  });

  it('says Time in, then Time out once clocked in', async () => {
    const { unmount } = renderAction();
    expect(await screen.findByRole('button', { name: 'Time in' })).toBeInTheDocument();
    unmount();

    vi.mocked(punchApi.status).mockResolvedValue({ ...STATUS, next_action: 'out' });
    renderAction();
    expect(await screen.findByRole('button', { name: 'Time out' })).toBeInTheDocument();
  });

  it('punches with the GPS fix in one tap and confirms with a toast', async () => {
    geolocation((ok) => ok({ coords: { latitude: 14.286, longitude: 120.9345, accuracy: 18 } } as GeolocationPosition));
    vi.mocked(punchApi.punch).mockResolvedValue({
      direction: 'in',
      punched_at: '2026-10-07T06:02:00+08:00',
      distance_m: '4.00',
      status: { ...STATUS, next_action: 'out' },
    });
    renderAction();

    fireEvent.click(await screen.findByRole('button', { name: 'Time in' }));

    await waitFor(() => expect(punchApi.punch).toHaveBeenCalledWith({ lat: 14.286, lng: 120.9345, accuracy: 18 }));
    await waitFor(() => expect(toast.success).toHaveBeenCalled());
  });

  it('turns into a check-again control when location is blocked and cannot be spammed', async () => {
    const getCurrentPosition = geolocation((_ok, err) =>
      err({ code: 1, message: 'denied', PERMISSION_DENIED: 1 } as GeolocationPositionError),
    );
    renderAction();

    fireEvent.click(await screen.findByRole('button', { name: 'Time in' }));
    const blocked = await screen.findByRole('button', { name: /location blocked/i });

    fireEvent.click(blocked);
    fireEvent.click(blocked);

    expect(getCurrentPosition).toHaveBeenCalledTimes(1);
    expect(punchApi.punch).not.toHaveBeenCalled();
  });
});
