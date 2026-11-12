import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { customerPortalApi } from '@/api/b2b/customer';
import type { PortalCatalogItem } from '@/types/b2b';
import CustomerPricingPage from './index';

vi.mock('@/api/b2b/customer', () => ({
  customerPortalApi: {
    priceRequests: vi.fn(),
    openPriceRequests: vi.fn(),
    listCatalog: vi.fn(),
    requestPrice: vi.fn(),
    respondPrice: vi.fn(),
    cancelPriceRequest: vi.fn(),
  },
}));

const product: PortalCatalogItem = {
  id: 'product-1',
  part_number: 'WB-001',
  name: 'Wiper Bushing',
  unit_of_measure: 'pcs',
  unit_price: '25.00',
  pricing_method: 'fixed',
  minimum_order_quantity: '5.000',
  maximum_order_quantity: '20.000',
  availability_mode: 'stock_limited',
  available_quantity: '20.000',
};

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <CustomerPricingPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(customerPortalApi.priceRequests).mockReturnValue(new Promise(() => {}));
  vi.mocked(customerPortalApi.openPriceRequests).mockResolvedValue([]);
  vi.mocked(customerPortalApi.listCatalog).mockResolvedValue([product]);
  vi.mocked(customerPortalApi.requestPrice).mockResolvedValue({ id: 'request-1' } as never);
});

afterEach(cleanup);

async function startRequest(quantity: string, targetPrice = '24.50', requestHigherLimit = false) {
  renderPage();
  fireEvent.click(screen.getByRole('button', { name: 'Request pricing' }));
  const productSelect = await screen.findByRole('combobox', { name: 'Product' });
  await waitFor(() => expect(productSelect).toBeEnabled());
  fireEvent.click(productSelect);
  fireEvent.click(await screen.findByRole('option', { name: 'WB-001 · Wiper Bushing' }));
  fireEvent.change(screen.getByLabelText(/quantity you plan to order/i), {
    target: { value: quantity },
  });
  if (targetPrice !== '') {
    fireEvent.change(screen.getByLabelText(/target unit price/i), {
      target: { value: targetPrice },
    });
  }
  fireEvent.change(screen.getByRole('textbox', { name: 'Reason for request' }), {
    target: { value: 'Planning a quarterly replenishment order.' },
  });
  if (requestHigherLimit) {
    fireEvent.click(screen.getByRole('checkbox', { name: /review a higher per-order limit/i }));
  }
  fireEvent.click(screen.getByRole('button', { name: 'Submit request' }));
}

describe('customer pricing request form', () => {
  it('explains that the planned quantity guides the target and Sales sets any final threshold', async () => {
    renderPage();
    fireEvent.click(screen.getByRole('button', { name: 'Request pricing' }));

    expect(await screen.findByRole('spinbutton', { name: 'Quantity you plan to order' })).toBeVisible();
    expect(screen.getByRole('spinbutton', { name: 'Target unit price at this quantity' })).toBeVisible();
    expect(screen.getByText(/Sales uses this volume to review your target price/i)).toBeInTheDocument();
    expect(screen.getByText(/sales confirms the final price and whether it requires a minimum quantity/i)).toBeInTheDocument();
    expect(screen.getByText(/a discount that applies only at 500 units needs a 500-unit tier/i)).toBeInTheDocument();
  });

  it('lets a customer cancel their own request after confirmation', async () => {
    const row = {
      id: 'request-cancel-1',
      product: { id: product.id, part_number: product.part_number, name: product.name, unit_of_measure: product.unit_of_measure },
      customer: { id: 'customer-1', name: 'Demo Customer' },
      quantity: 10,
      requested_price: '24.50',
      notes: 'Planning an order.',
      status: 'requested',
      version: 1,
      available_actions: { can_propose: false, can_approve: false, can_withdraw: false, can_accept: false, can_reject: false, can_cancel: true },
    } as const;
    vi.mocked(customerPortalApi.priceRequests).mockResolvedValue({
      data: [row],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
      links: { first: '', last: '', prev: null, next: null },
    } as never);
    vi.mocked(customerPortalApi.cancelPriceRequest).mockResolvedValue({
      ...row,
      status: 'customer_cancelled',
      version: 2,
      available_actions: { ...row.available_actions, can_cancel: false },
    } as never);
    renderPage();

    fireEvent.click((await screen.findAllByRole('button', { name: 'Cancel request' }))[0]);
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(/sales will stop reviewing this request/i)).toBeVisible();
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel request' }));

    await waitFor(() => expect(customerPortalApi.cancelPriceRequest).toHaveBeenCalledWith(row.id, row.version));
    expect((await screen.findAllByText('Cancelled by you'))[0]).toBeVisible();
  });

  it('requires Sales limit review before allowing a quantity above the product order limit', async () => {
    await startRequest('21');

    expect(await screen.findByText(/maximum order quantity is 20 pcs/i)).toBeInTheDocument();
    expect(customerPortalApi.requestPrice).not.toHaveBeenCalled();
  });

  it('allows a higher-quantity pricing request only when the customer explicitly requests a limit review', async () => {
    await startRequest('25', '24.50', true);

    await waitFor(() => expect(customerPortalApi.requestPrice).toHaveBeenCalledTimes(1));
    expect(customerPortalApi.requestPrice).toHaveBeenCalledWith({
      product_id: product.id,
      quantity: 25,
      requested_price: '24.50',
      notes: 'Planning a quarterly replenishment order.',
      request_order_limit_increase: true,
    });
  });

  it('does not carry a limit-review choice from one product to another', async () => {
    const secondProduct = {
      ...product,
      id: 'product-2',
      part_number: 'WB-002',
      name: 'Wiper Bushing Type B',
      maximum_order_quantity: '40.000',
    };
    vi.mocked(customerPortalApi.listCatalog).mockResolvedValue([product, secondProduct]);
    renderPage();
    fireEvent.click(screen.getByRole('button', { name: 'Request pricing' }));
    const productSelect = await screen.findByRole('combobox', { name: 'Product' });
    await waitFor(() => expect(productSelect).toBeEnabled());
    fireEvent.click(productSelect);
    fireEvent.click(await screen.findByRole('option', { name: 'WB-001 · Wiper Bushing' }));
    fireEvent.change(screen.getByLabelText(/quantity you plan to order/i), { target: { value: '25' } });
    const limitReview = await screen.findByRole('checkbox', { name: /review a higher per-order limit/i });
    fireEvent.click(limitReview);
    expect(limitReview).toBeChecked();

    fireEvent.click(productSelect);
    fireEvent.click(await screen.findByRole('option', { name: /WB-002 · Wiper Bushing Type B/ }));
    expect(screen.queryByRole('checkbox', { name: /review a higher per-order limit/i })).not.toBeInTheDocument();

    fireEvent.click(productSelect);
    fireEvent.click(await screen.findByRole('option', { name: 'WB-001 · Wiper Bushing' }));
    expect(await screen.findByRole('checkbox', { name: /review a higher per-order limit/i })).not.toBeChecked();
  });

  it('rejects a planned quantity below the product minimum', async () => {
    await startRequest('4');

    expect(await screen.findByRole('alert')).toHaveTextContent('Minimum order quantity is 5 pcs.');
    expect(customerPortalApi.requestPrice).not.toHaveBeenCalled();
  });

  it('requires a target unit price before submitting', async () => {
    await startRequest('20', '');

    expect(await screen.findByText('Enter a target unit price.')).toBeInTheDocument();
    expect(customerPortalApi.requestPrice).not.toHaveBeenCalled();
  });

  it('does not invent a maximum when the customer agreement has no maximum', async () => {
    vi.mocked(customerPortalApi.listCatalog).mockResolvedValue([{
      ...product,
      minimum_order_quantity: '1.000',
      maximum_order_quantity: null,
    }]);
    await startRequest('100');

    await waitFor(() => expect(customerPortalApi.requestPrice).toHaveBeenCalledTimes(1));
    expect(customerPortalApi.requestPrice).toHaveBeenCalledWith({
      product_id: product.id,
      quantity: 100,
      requested_price: '24.50',
      notes: 'Planning a quarterly replenishment order.',
      request_order_limit_increase: false,
    });
  });

  it('shows an availability advisory without blocking a pricing inquiry', async () => {
    vi.mocked(customerPortalApi.listCatalog).mockResolvedValue([{
      ...product,
      available_quantity: '3.000',
    }]);
    renderPage();
    fireEvent.click(screen.getByRole('button', { name: 'Request pricing' }));
    const productSelect = await screen.findByRole('combobox', { name: 'Product' });
    await waitFor(() => expect(productSelect).toBeEnabled());
    fireEvent.click(productSelect);
    fireEvent.click(await screen.findByRole('option', { name: 'WB-001 · Wiper Bushing' }));
    fireEvent.change(screen.getByLabelText(/quantity you plan to order/i), { target: { value: '10' } });
    fireEvent.change(screen.getByLabelText(/target unit price/i), { target: { value: '24.50' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Reason for request' }), {
      target: { value: 'Please confirm replenishment timing.' },
    });

    expect(screen.getByText(/only 3 pcs are currently available/i)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Submit request' }));
    await waitFor(() => expect(customerPortalApi.requestPrice).toHaveBeenCalledTimes(1));
  });

  it('shows existing open requests and prevents selecting their product again', async () => {
    vi.mocked(customerPortalApi.openPriceRequests).mockResolvedValue([{
      id: 'request-open',
      product_id: product.id,
      status: 'requested',
      quantity: 10,
    }]);
    renderPage();
    fireEvent.click(screen.getByRole('button', { name: 'Request pricing' }));

    expect(await screen.findByRole('link', { name: /view open request/i })).toHaveAttribute(
      'href',
      '/portal/customer/pricing?request=request-open',
    );
    const productSelect = await screen.findByRole('combobox', { name: 'Product' });
    fireEvent.click(productSelect);
    expect(await screen.findByRole('option', { name: /WB-001 · Wiper Bushing.*request already open/i })).toBeDisabled();
  });

  it('shows an account-access message when no products are available', async () => {
    vi.mocked(customerPortalApi.listCatalog).mockResolvedValue([]);
    renderPage();
    fireEvent.click(screen.getByRole('button', { name: 'Request pricing' }));

    expect(await screen.findByText('No products are available under your account. Contact Sales.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Submit request' })).toBeDisabled();
  });

  it('keeps submission disabled when the product catalog fails to load', async () => {
    vi.mocked(customerPortalApi.listCatalog).mockRejectedValue(new Error('offline'));
    renderPage();
    fireEvent.click(screen.getByRole('button', { name: 'Request pricing' }));

    expect(await screen.findByText('Cannot load products.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Retry' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Submit request' })).toBeDisabled();
  });
});
