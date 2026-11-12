import { fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it } from 'vitest';
import { PortalWalkthrough } from './PortalWalkthrough';
import type { PortalGuideSection } from './portalWalkthroughs';
import { Link } from 'react-router-dom';

const customerSections: PortalGuideSection[] = [
  { label: 'Overview', items: [
    { to: '/portal/customer', label: 'Dashboard' },
    { to: '/portal/customer/notifications', label: 'Notifications' },
    { to: '/portal/customer/agreements', label: 'Agreements' },
  ] },
  { label: 'Orders', items: [
    { to: '/portal/customer/orders', label: 'Orders' },
    { to: '/portal/customer/orders/new', label: 'Place Order' },
    { to: '/portal/customer/pricing', label: 'Pricing agreements' },
  ] },
  { label: 'Billing', items: [
    { to: '/portal/customer/invoices', label: 'Invoices' },
    { to: '/portal/customer/statement-of-account', label: 'Account Statement' },
  ] },
  { label: 'Logistics', items: [{ to: '/portal/customer/deliveries', label: 'Deliveries' }] },
  { label: 'Quality', items: [
    { to: '/portal/customer/returns', label: 'Returns' },
    { to: '/portal/customer/problems', label: 'Problem reports' },
  ] },
];

const supplierSections: PortalGuideSection[] = [
  { label: 'Overview', items: [{ to: '/portal/supplier', label: 'Dashboard' }] },
  { label: 'Orders', items: [
    { to: '/portal/supplier/purchase-orders', label: 'Purchase Orders' },
    { to: '/portal/supplier/rfqs', label: 'Supplier RFQs' },
    { to: '/portal/supplier/listings', label: 'Item Listings' },
  ] },
  { label: 'Billing', items: [
    { to: '/portal/supplier/invoices', label: 'Invoices' },
    { to: '/portal/supplier/statement-of-account', label: 'Account Statement' },
  ] },
  { label: 'Logistics', items: [
    { to: '/portal/supplier/deliveries', label: 'Deliveries' },
    { to: '/portal/supplier/problems', label: 'Problem reports' },
  ] },
];

afterEach(() => localStorage.clear());

it('starts for a new customer and lets them skip and replay it from Help', () => {
  render(<MemoryRouter initialEntries={['/portal/customer']}>
    <PortalWalkthrough realm="customer" userId="customer-hash-1" sections={customerSections} />
  </MemoryRouter>);

  expect(screen.getByRole('dialog')).toBeVisible();
  expect(screen.getByRole('dialog')).toHaveClass('inset-x-3', 'sm:right-6');
  expect(screen.getByText(/orders, invoices, deliveries, and returns/i)).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Skip walkthrough' }));

  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Help' }));
  expect(screen.getByRole('dialog')).toBeVisible();
});

it('follows portal navigation changes while the guide stays open', () => {
  render(<MemoryRouter initialEntries={['/portal/customer']}>
    <PortalWalkthrough realm="customer" userId="customer-route" sections={customerSections} />
    <Link to="/portal/customer/invoices">Open invoices</Link>
  </MemoryRouter>);

  fireEvent.click(screen.getByRole('button', { name: 'Start walkthrough' }));
  fireEvent.click(screen.getByRole('link', { name: 'Open invoices' }));

  expect(within(screen.getByRole('dialog'))
    .getByRole('heading', { name: 'Billing' })).toBeVisible();
});

it('covers all customer portal sections and completes the guide', () => {
  render(<MemoryRouter initialEntries={['/portal/customer']}>
    <PortalWalkthrough realm="customer" userId="customer-hash-2" sections={customerSections} />
  </MemoryRouter>);
  fireEvent.click(screen.getByRole('button', { name: 'Start walkthrough' }));

  for (const [index, section] of customerSections.entries()) {
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByRole('heading', { name: section.label })).toBeVisible();
    for (const item of section.items) {
      expect(within(dialog).getByRole('link', { name: item.label })).toHaveAttribute('href', item.to);
    }
    fireEvent.click(within(dialog).getByRole('button', { name: index === customerSections.length - 1 ? 'Finish' : 'Next' }));
  }

  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  expect(localStorage.getItem('ogami:portal-walkthrough:customer:customer-hash-2:v1')).toBe('completed');
});

it('uses an independent supplier guide and starts it for a different portal user', () => {
  localStorage.setItem('ogami:portal-walkthrough:customer:customer-hash-3:v1', 'completed');
  render(<MemoryRouter initialEntries={['/portal/supplier']}>
    <PortalWalkthrough realm="supplier" userId="supplier-hash-3" sections={supplierSections} />
  </MemoryRouter>);

  fireEvent.click(screen.getByRole('button', { name: 'Start walkthrough' }));
  const dialog = screen.getByRole('dialog');
  for (const [index, section] of supplierSections.entries()) {
    expect(within(dialog).getByRole('heading', { name: section.label })).toBeVisible();
    for (const item of section.items) expect(within(dialog).getByRole('link', { name: item.label })).toBeVisible();
    fireEvent.click(within(dialog).getByRole('button', { name: index === supplierSections.length - 1 ? 'Finish' : 'Next' }));
  }
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  expect(localStorage.getItem('ogami:portal-walkthrough:supplier:supplier-hash-3:v1')).toBe('completed');
});
