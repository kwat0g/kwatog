import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { notificationsApi, type NotificationListResponse } from '@/api/notifications';
import { NotificationBell } from './NotificationBell';
import NotificationsListPage from '@/pages/notifications';

vi.mock('@/api/notifications', () => ({ notificationsApi: { list: vi.fn(), markRead: vi.fn(), markAllRead: vi.fn(), remove: vi.fn(), clearRead: vi.fn() } }));
vi.mock('@/lib/echo', () => ({ getEcho: vi.fn().mockRejectedValue(new Error('Unavailable in test')) }));
afterEach(() => { cleanup(); vi.clearAllMocks(); });

const response: NotificationListResponse = {
  data: [{ id: 'notice-1', type: 'sales_order.delivery_proposed', data: { title: 'Review delivery date', message: 'Accept or decline proposed date.', link_to: '/portal/customer/orders/order-1' }, read_at: null, created_at: '2026-10-04T12:00:00Z' }],
  meta: { current_page: 1, last_page: 1, per_page: 8, total: 1, unread_count: 1 },
};

function api() {
  return { list: vi.fn().mockResolvedValue(response), markRead: vi.fn().mockResolvedValue({ id: 'notice-1', read_at: '2026-10-04' }), markAllRead: vi.fn().mockResolvedValue({ marked_read: 1 }), remove: vi.fn().mockResolvedValue({ deleted: true }), clearRead: vi.fn().mockResolvedValue({ deleted: 0 }) };
}

it('portal bell renders portal notices, opens portal inbox, and keeps internal cache separate', async () => {
  const portalApi = api();
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  queryClient.setQueryData(['notifications', 'peek'], { ...response, data: [{ ...response.data[0], data: { title: 'Internal payroll secret' } }] });
  render(<QueryClientProvider client={queryClient}><MemoryRouter>
  <NotificationBell api={portalApi} queryKey={['portal', 'customer', 'reader-1', 'notifications']} inboxPath="/portal/customer/notifications" realtime={false} linkPrefix="/portal/customer" />
    <Routes><Route path="/" element={null} /><Route path="/portal/customer/notifications" element={<p>Customer inbox opened</p>} /></Routes>
  </MemoryRouter></QueryClientProvider>);
  fireEvent.click(await screen.findByRole('button', { name: 'Notifications, 1 unread' }));
  expect(await screen.findByText('Review delivery date')).toBeVisible();
  expect(screen.getByRole('link', { name: 'Review delivery date: Accept or decline proposed date.' }))
    .toHaveAttribute('href', '/portal/customer/orders/order-1');
  expect(screen.queryByText('Internal payroll secret')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('link', { name: 'View all notifications' }));
  expect(await screen.findByText('Customer inbox opened')).toBeVisible();
  expect(notificationsApi.list).not.toHaveBeenCalled();
});

it('portal bell marks its own notice read and follows the same-realm record link', async () => {
  const portalApi = api();
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>
    <NotificationBell api={portalApi} queryKey={['portal', 'customer', 'reader-1', 'notifications']} inboxPath="/portal/customer/notifications" realtime={false} linkPrefix="/portal/customer" />
    <Routes><Route path="/" element={null} /><Route path="/portal/customer/orders/order-1" element={<p>Customer order opened</p>} /></Routes>
  </MemoryRouter></QueryClientProvider>);
  fireEvent.click(await screen.findByRole('button', { name: 'Notifications, 1 unread' }));
  const recordLink = await screen.findByRole('link', { name: 'Review delivery date: Accept or decline proposed date.' });
  fireEvent.click(recordLink);
  expect(await screen.findByText('Customer order opened')).toBeVisible();
  await waitFor(() => expect(portalApi.markRead).toHaveBeenCalledWith('notice-1'));
});

it('portal inbox marks its own notice read and navigates to customer next step', async () => {
  const portalApi = api();
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>
    <NotificationsListPage api={portalApi} queryKey={['portal', 'customer', 'reader-1', 'notifications']} linkPrefix="/portal/customer" />
    <Routes><Route path="/" element={null} /><Route path="/portal/customer/orders/order-1" element={<p>Customer order opened</p>} /></Routes>
  </MemoryRouter></QueryClientProvider>);
  const recordLink = await screen.findByRole('link', { name: 'Review delivery date: Accept or decline proposed date.' });
  expect(recordLink).toHaveAttribute('href', '/portal/customer/orders/order-1');
  fireEvent.click(recordLink);
  expect(await screen.findByText('Customer order opened')).toBeVisible();
  await waitFor(() => expect(portalApi.markRead).toHaveBeenCalledWith('notice-1'));
  expect(notificationsApi.list).not.toHaveBeenCalled();
});

it('portal inbox never follows internal or other realm links', async () => {
  const portalApi = api();
  portalApi.list.mockResolvedValue({ ...response, data: [{ ...response.data[0], data: { title: 'Malformed destination', link_to: '/crm/sales-orders/order-1' } }] });
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>
    <NotificationsListPage api={portalApi} queryKey={['portal', 'customer', 'reader-1', 'notifications']} linkPrefix="/portal/customer" />
    <Routes><Route path="/" element={null} /><Route path="/crm/sales-orders/order-1" element={<p>Internal order opened</p>} /></Routes>
  </MemoryRouter></QueryClientProvider>);
  fireEvent.click(await screen.findByRole('button', { name: 'Malformed destination' }));
  await waitFor(() => expect(portalApi.markRead).toHaveBeenCalled());
  expect(screen.queryByText('Internal order opened')).not.toBeInTheDocument();
});
