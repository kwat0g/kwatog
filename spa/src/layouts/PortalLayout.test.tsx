import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { landingApi } from '@/api/landing';
import { customerPortalApi } from '@/api/b2b/customer';
import PortalLayout from './PortalLayout';

const realtimeSignalSpy = vi.hoisted(() => vi.fn());

vi.mock('@/api/b2b/customer', () => ({
  customerPortalApi: { businessPolicies: vi.fn(), invoiceSignals: vi.fn() },
}));
vi.mock('@/api/b2b/supplier', () => ({ supplierPortalApi: { businessPolicies: vi.fn() } }));
vi.mock('@/api/b2b/notifications', () => ({ portalNotificationsApi: { customer: {}, supplier: {} } }));
vi.mock('@/api/landing', () => ({ landingApi: { contact: vi.fn() } }));
vi.mock('@/hooks/useRealtimeSignal', () => ({ useRealtimeSignal: realtimeSignalSpy }));
vi.mock('@/hooks/useRealtimeEvent', () => ({ useRealtimeEvent: vi.fn() }));
vi.mock('@/components/layout/NotificationBell', () => ({ NotificationBell: () => null }));
vi.mock('@/components/ui/OfflineBanner', () => ({ OfflineBanner: () => null }));
vi.mock('@/components/ui/Avatar', () => ({ Avatar: () => null }));
vi.mock('@/components/brand/BrandLogo', () => ({ BrandLogo: () => <span>Logo</span> }));
vi.mock('@/components/ui/Button', () => ({
  Button: ({ children, onClick }: { children?: ReactNode; onClick?: () => void }) => (
    <button onClick={onClick}>{children}</button>
  ),
}));

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(customerPortalApi.businessPolicies).mockResolvedValue({
    functional_currency_code: 'PHP',
  } as Awaited<ReturnType<typeof customerPortalApi.businessPolicies>>);
  vi.mocked(customerPortalApi.invoiceSignals).mockResolvedValue({ unpaid: 5, overdue: 2 });
  vi.mocked(landingApi.contact).mockResolvedValue({
    legal_name: null,
    address: null,
    phone: null,
    sales_email: null,
    company_email: null,
    public_url: null,
    latitude: null,
    longitude: null,
  });
});

it('binds customer return refreshes to the exact customer account and signal map', () => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/portal/customer/returns']}>
        <PortalLayout
          type="customer"
          user={{ id: 'customer-user', customer_id: 'customer-hash', name: 'Customer', email: 'customer@example.test' }}
          onLogout={() => {}}
          title="Customer Portal"
          subtitle="Returns"
        >
          <div>Returns</div>
        </PortalLayout>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  expect(realtimeSignalSpy).toHaveBeenCalledWith(
    'customer_returns_changed',
    'customer.account.customer-hash',
    '.return.request.refresh',
  );
});

it('shows separate customer AR signals with scoped invoice filters', async () => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/portal/customer']}>
        <PortalLayout
          type="customer"
          user={{ id: 'customer-user', name: 'Customer', email: 'customer@example.test' }}
          onLogout={() => {}}
          title="Dashboard"
          subtitle="Customer portal"
        >
          <div>Page</div>
        </PortalLayout>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  expect(await screen.findByRole('link', { name: 'Invoices: 2 overdue, 5 unpaid' })).toHaveAttribute(
    'href',
    '/portal/customer/invoices?signal=overdue',
  );
  const signalButton = screen.getByRole('button', { name: 'View all Invoices signals: 2 overdue, 5 unpaid' });
  expect(signalButton).toHaveTextContent('2');
  expect(signalButton).toHaveTextContent('+1');
  fireEvent.click(signalButton);
  expect(screen.getByRole('link', { name: 'Invoices: 2 overdue' })).toHaveAttribute(
    'href',
    '/portal/customer/invoices?signal=overdue',
  );
  expect(screen.getByRole('link', { name: 'Invoices: 5 unpaid' })).toHaveAttribute(
    'href',
    '/portal/customer/invoices?signal=unpaid',
  );
});

it('revalidates cached customer invoice signals when the portal shell mounts', async () => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  client.setQueryData(['portal', 'customer', 'customer-user', 'invoice-signals'], { unpaid: 1, overdue: 0 });
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/portal/customer']}>
        <PortalLayout
          type="customer"
          user={{ id: 'customer-user', name: 'Customer', email: 'customer@example.test' }}
          onLogout={() => {}}
          title="Dashboard"
          subtitle="Customer portal"
        >
          <div>Page</div>
        </PortalLayout>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  await waitFor(() => expect(customerPortalApi.invoiceSignals).toHaveBeenCalledTimes(1));
  client.clear();
});
