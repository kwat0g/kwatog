import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { punchApi } from '@/api/attendance/punch';
import { PunchAuditModal } from './PunchAuditModal';

vi.mock('@/api/attendance/punch', () => ({ punchApi: { audit: vi.fn() } }));

const wrap = (ui: React.ReactNode) =>
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      {ui}
    </QueryClientProvider>
  );

describe('PunchAuditModal', () => {
  it('lists each punch with distance and shows a dash once coordinates are purged', async () => {
    vi.mocked(punchApi.audit).mockResolvedValue([
      {
        id: 'a',
        direction: 'in',
        punched_at: '2026-10-07T06:02:00+08:00',
        latitude: '14.286000',
        longitude: '120.934500',
        accuracy_m: '18.00',
        distance_m: '4.00',
        ip: '10.0.0.1',
      },
      {
        id: 'b',
        direction: 'out',
        punched_at: '2026-10-07T14:05:00+08:00',
        latitude: null,
        longitude: null,
        accuracy_m: '12.00',
        distance_m: '9.00',
        ip: null,
      },
    ]);
    wrap(<PunchAuditModal attendanceId="x" onClose={() => {}} />);

    expect(await screen.findByText('14.286000, 120.934500')).toBeInTheDocument();
    expect(screen.getAllByText('—').length).toBeGreaterThan(0);
    expect(screen.getByText(/4\.00 m/)).toBeInTheDocument();
  });

  it('shows an empty message when the day has no app punches', async () => {
    vi.mocked(punchApi.audit).mockResolvedValue([]);
    wrap(<PunchAuditModal attendanceId="x" onClose={() => {}} />);
    expect(await screen.findByText(/no app punches/i)).toBeInTheDocument();
  });
});
