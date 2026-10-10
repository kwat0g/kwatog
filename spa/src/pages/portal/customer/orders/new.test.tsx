import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, useParams } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { customerPortalApi } from '@/api/b2b/customer';
import { localIsoDate } from '@/lib/formatDate';
import type { PortalCatalogItem } from '@/types/b2b';
import type { BusinessPolicies } from '@/api/businessPolicies';
import CustomerPlaceOrderPage from './new';
const orderDate = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
const dateAfterToday = (days: number) => {
  const [year, month, day] = orderDate().split('-').map(Number);
  const date = new Date(year, month - 1, day, 12);
  date.setDate(date.getDate() + days);
  return localIsoDate(date);
};

const navigate = vi.hoisted(() => vi.fn());
vi.mock('react-router-dom', async (importOriginal) => ({
  ...(await importOriginal<typeof import('react-router-dom')>()),
  useNavigate: () => navigate,
  useParams: vi.fn(() => ({})),
}));
vi.mock('@/api/b2b/customer', () => ({
  customerPortalApi: { listCatalog: vi.fn(), createOrder: vi.fn(), getOrder: vi.fn(), updateOrder: vi.fn(), businessPolicies: vi.fn(), quoteOrder: vi.fn() },
}));

const product: PortalCatalogItem = {
  id: 'product-1', part_number: 'WB-001', name: 'Wiper Bushing (Standard)',
  unit_of_measure: 'pcs', unit_price: '22.50', pricing_method: 'fixed',
};

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><CustomerPlaceOrderPage /></MemoryRouter></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(useParams).mockReturnValue({});
  vi.mocked(customerPortalApi.listCatalog).mockResolvedValue([product]);
  vi.mocked(customerPortalApi.quoteOrder).mockImplementation(async (form) => {
    const items = form.items.map((item) => ({ ...item, unit_price: '22.50', total: (Number(item.quantity) * 22.5).toFixed(2), price_source: 'base' as const }));
    const subtotal = items.reduce((sum, item) => sum + Number(item.total), 0);
    return { items, subtotal: subtotal.toFixed(2), vat_amount: (subtotal * .12).toFixed(2), total_amount: (subtotal * 1.12).toFixed(2), signature: 'a'.repeat(64) };
  });
  vi.mocked(customerPortalApi.businessPolicies).mockResolvedValue({
    customer_payment_terms_days: 30, vendor_payment_terms_days: 30, sales_delivery_lead_days: 7,
    mrp_default_lead_time_days: 7, mrp_work_order_normal_priority: 1, purchase_order_vp_threshold: 100000,
    functional_currency_code: 'PHP', reporting_currency_code: 'PHP', translation_adjustment_account_code: '',
    vat_rate: '0.12', vat_status: 'VAT Registered',
  } satisfies BusinessPolicies);
});
afterEach(cleanup);

describe('customer order entry', () => {
  it('reuses the order form to edit a customer draft without changing its order date', async () => {
    vi.mocked(useParams).mockReturnValue({ id: 'so-1' });
    const requestedDate = dateAfterToday(7);
    const existing = { id: 'so-1', so_number: 'SO-1', date: '2026-10-01', status: 'draft', total_amount: '22.50', created_at: '2026-10-01',
      notes: 'Original note', requested_delivery_dates: [requestedDate], delivery_dates: [requestedDate], capabilities: { can_edit: true, can_cancel: true, can_respond: false, can_confirm: false },
      items: [{ id: 'item-1', product, quantity: '1', unit_price: '22.50', total: '22.50', delivery_date: requestedDate }],
    };
    vi.mocked(customerPortalApi.getOrder).mockResolvedValue(existing);
    vi.mocked(customerPortalApi.updateOrder).mockResolvedValue({ data: existing });
    renderPage();
    const quantity = await screen.findByRole('spinbutton', { name: 'Quantity for WB-001' });
    expect(screen.getByLabelText('Order date')).toHaveValue('2026-10-01');
    expect(screen.getByLabelText('Order date')).toHaveAttribute('readonly');
    expect(screen.getByLabelText('Requested delivery date')).toHaveValue(requestedDate);
    fireEvent.change(quantity, { target: { value: '50' } });
    await waitFor(() => expect(screen.getByRole('button', { name: 'Save changes' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(navigate).toHaveBeenCalledWith('/portal/customer/orders/so-1'));
    expect(customerPortalApi.updateOrder).toHaveBeenCalledWith('so-1', { notes: 'Original note', quote_signature: 'a'.repeat(64), items: [
      { product_id: product.id, quantity: '50', delivery_date: requestedDate },
    ] });
    expect(customerPortalApi.createOrder).not.toHaveBeenCalled();
  });

  it('does not label a non-VAT order as VAT included', async () => {
    const policies = await customerPortalApi.businessPolicies();
    vi.mocked(customerPortalApi.businessPolicies).mockResolvedValue({ ...policies, vat_status: 'Non-VAT', vat_rate: null });
    renderPage();
    fireEvent.click(screen.getByRole('button', { name: 'Add products' }));
    expect(screen.getByRole('textbox', { name: 'Search products' })).toHaveFocus();
    fireEvent.click(await screen.findByRole('button', { name: `Add ${product.name} to order` }));
    expect(screen.getByTestId('order-vat')).toHaveTextContent('0.00');
    expect(screen.getByTestId('order-total')).toHaveTextContent('22.50');
    expect(screen.getByText('No VAT applied')).toBeInTheDocument();
  });

  it('keeps the total pending and submission blocked when tax information cannot load', async () => {
    vi.mocked(customerPortalApi.businessPolicies).mockRejectedValue(new Error('Unavailable'));
    renderPage();
    fireEvent.click(screen.getByRole('button', { name: 'Add products' }));
    fireEvent.click(await screen.findByRole('button', { name: `Add ${product.name} to order` }));
    expect(await screen.findByText(/Unable to load tax information/)).toBeVisible();
    expect(screen.getByTestId('order-total')).toHaveTextContent('Pending');
    expect(screen.getByRole('button', { name: /Submit order for review/ })).toBeDisabled();
  });

  it('collapses the catalog after selection and keeps all edited items available', async () => {
    vi.mocked(customerPortalApi.listCatalog).mockResolvedValue([
      product, { ...product, id: 'product-2', part_number: 'WB-002', name: 'Heavy Duty Bushing' },
    ]);
    renderPage();
    fireEvent.click(screen.getByRole('button', { name: 'Add products' }));
    fireEvent.click(await screen.findByRole('button', { name: `Add ${product.name} to order` }));
    fireEvent.click(screen.getByRole('button', { name: 'Add Heavy Duty Bushing to order' }));
    fireEvent.click(screen.getByRole('button', { name: 'Done selecting products' }));
    expect(screen.getByRole('button', { name: 'Add products' })).toHaveFocus();
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Quantity for WB-001' }), { target: { value: '100' } });
    expect(screen.queryByRole('list', { name: 'Available products' })).not.toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Order items' })).toBeVisible();
    expect(screen.getByRole('table', { name: 'Selected order items' })).toBeVisible();
    expect(screen.getByRole('spinbutton', { name: 'Quantity for WB-001' })).toHaveValue(100);
    expect(screen.getByRole('spinbutton', { name: 'Quantity for WB-002' })).toHaveValue(1);
    expect(screen.getByTestId('order-subtotal')).toHaveTextContent('2,272.50');
    expect(screen.getByTestId('order-vat')).toHaveTextContent('272.70');
    expect(screen.getByTestId('order-total')).toHaveTextContent('2,545.20');
    expect(screen.getByRole('button', { name: 'Add products' })).toHaveAttribute('aria-expanded', 'false');
  });

  it('shows selected products and labeled editing fields, updates subtotal, and allows removal', async () => {
    renderPage();
    fireEvent.click(screen.getByRole('button', { name: 'Add products' }));
    fireEvent.click(await screen.findByRole('button', { name: `Add ${product.name} to order` }));
    expect(screen.getByRole('button', { name: `${product.name} added to order` })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Done selecting products' }));
    const quantity = screen.getByRole('spinbutton', { name: 'Quantity for WB-001' });
    expect(screen.getAllByLabelText('Requested delivery date')).toHaveLength(1);
    fireEvent.change(quantity, { target: { value: '100' } });
    expect(screen.getByTestId('order-subtotal')).toHaveTextContent('2,250.00');
    fireEvent.click(screen.getByRole('button', { name: 'Add products' }));
    fireEvent.keyDown(screen.getByRole('textbox', { name: 'Search products' }), { key: 'Enter' });
    fireEvent.click(screen.getByRole('button', { name: 'Done selecting products' }));
    expect(customerPortalApi.createOrder).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: `Remove ${product.name}` }));
    expect(screen.getByText('Your order starts here')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Submit order for review/ })).toBeDisabled();
  });

  it('fixes order date to today and explains invalid quantity and delivery date', async () => {
    renderPage();
    fireEvent.click(screen.getByRole('button', { name: 'Add products' }));
    fireEvent.click(await screen.findByRole('button', { name: `Add ${product.name} to order` }));
    expect(screen.getByLabelText('Order date')).toHaveAttribute('readonly');
    expect(screen.getByLabelText('Order date')).toHaveValue(orderDate());
    expect(screen.getByLabelText('Requested delivery date')).toHaveValue('');
    const earliestDate = new Date(`${orderDate()}T00:00:00Z`); earliestDate.setUTCDate(earliestDate.getUTCDate() + 4);
    expect(screen.getByLabelText('Requested delivery date')).toHaveAttribute('min', new Intl.DateTimeFormat('en-CA', { timeZone: 'UTC', year: 'numeric', month: '2-digit', day: '2-digit' }).format(earliestDate));
    fireEvent.change(screen.getByLabelText('Requested delivery date'), { target: { value: '2000-01-01' } });
    fireEvent.click(screen.getByRole('button', { name: 'Done selecting products' }));
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Quantity for WB-001' }), { target: { value: '0' } });
    expect(screen.getByText('Enter a whole number of at least 1.')).toBeInTheDocument();
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Quantity for WB-001' }), { target: { value: '1.5' } });
    expect(screen.getByText('Enter a whole number of at least 1.')).toBeInTheDocument();
    expect(screen.getByRole('spinbutton', { name: 'Quantity for WB-001' })).toHaveAttribute('step', '1');
    expect(screen.getByText('Choose a date more than 3 days from today.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Submit order for review/ })).toBeDisabled();
    expect(customerPortalApi.createOrder).not.toHaveBeenCalled();
  });

  it('applies one delivery date to all items and preserves the request key on retry', async () => {
    const requestedDate = dateAfterToday(7);
    vi.mocked(customerPortalApi.listCatalog).mockResolvedValue([
      product, { ...product, id: 'product-2', part_number: 'WB-002', name: 'Heavy Duty Bushing' },
    ]);
    vi.mocked(customerPortalApi.createOrder)
      .mockRejectedValueOnce({ response: { data: { message: 'Connection interrupted. Please retry.' } } })
      .mockResolvedValueOnce({ message: 'Order submitted.', data: {
        id: 'so-1', so_number: 'SO-1', date: '2026-10-03', total_amount: '2250.00', status: 'draft', created_at: '2026-10-03',
      } });
    renderPage();
    fireEvent.click(screen.getByRole('button', { name: 'Add products' }));
    fireEvent.click(await screen.findByRole('button', { name: `Add ${product.name} to order` }));
    fireEvent.click(screen.getByRole('button', { name: 'Add Heavy Duty Bushing to order' }));
    fireEvent.click(screen.getByRole('button', { name: 'Done selecting products' }));
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Quantity for WB-001' }), { target: { value: '100' } });
    expect(screen.getByLabelText('Order date')).toHaveAttribute('readonly');
    expect(screen.getByLabelText('Order date')).toHaveValue(orderDate());
    fireEvent.change(screen.getByLabelText('Requested delivery date'), { target: { value: requestedDate } });
    expect(screen.getAllByLabelText('Requested delivery date')).toHaveLength(1);
    const notes = screen.getByRole('textbox', { name: 'Order note (optional)' });
    expect(notes).toBeVisible();
    expect(notes.compareDocumentPosition(screen.getByRole('button', { name: /Submit order for review/ })) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    fireEvent.change(notes, { target: { value: '  Test delivery  ' } });
    await waitFor(() => expect(screen.getByRole('button', { name: /Submit order for review/ })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: /Submit order for review/ }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Connection interrupted. Please retry.');
    expect(screen.getByRole('spinbutton', { name: 'Quantity for WB-001' })).toHaveValue(100);
    fireEvent.click(screen.getByRole('button', { name: /Submit order for review/ }));
    await waitFor(() => expect(navigate).toHaveBeenCalledWith('/portal/customer/orders/so-1'));
    const calls = vi.mocked(customerPortalApi.createOrder).mock.calls;
    expect(calls[0][0]).toEqual({ notes: 'Test delivery', quote_signature: 'a'.repeat(64), items: [{ product_id: product.id, quantity: '100', delivery_date: requestedDate }, { product_id: 'product-2', quantity: '1', delivery_date: requestedDate }] });
    expect(calls[1]).toEqual(calls[0]);
    expect(calls[0][1]).toMatch(/^[0-9a-f-]{36}$/);
  });
  it('displays server volume quote instead of multiplying the catalog price', async () => {
    const requestedDate = dateAfterToday(7);
    vi.mocked(customerPortalApi.quoteOrder).mockResolvedValue({ items: [{ product_id: product.id, quantity: '100', delivery_date: requestedDate, unit_price: '20.00', total: '2000.00', price_source: 'agreement' }], subtotal: '2000.00', vat_amount: '240.00', total_amount: '2240.00', signature: 'b'.repeat(64) });
    renderPage();
    fireEvent.change(screen.getByLabelText('Requested delivery date'), { target: { value: requestedDate } });
    fireEvent.click(screen.getByRole('button', { name: 'Add products' }));
    fireEvent.click(await screen.findByRole('button', { name: `Add ${product.name} to order` }));
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Quantity for WB-001' }), { target: { value: '100' } });
    await waitFor(() => expect(screen.getByTestId('order-total')).toHaveTextContent('2,240.00'));
    expect(screen.getByText('Agreed price')).toBeVisible();
  });
});
