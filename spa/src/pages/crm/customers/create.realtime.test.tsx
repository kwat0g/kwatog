import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { businessPoliciesApi } from '@/api/businessPolicies';
import { portalAccessApi } from '@/api/b2b/portal-access';
import { crmCustomersApi } from '@/api/crm/customers';
import type { Customer } from '@/types/accounting';
import CrmCustomerCreatePage from './create';

const { readAgreementPdf, productOptions } = vi.hoisted(() => ({ readAgreementPdf: vi.fn(), productOptions: vi.fn() }));

vi.mock('./readCustomerAgreementPdf', () => ({
  CUSTOMER_AGREEMENT_PDF_MAX_BYTES: 10 * 1024 * 1024,
  readCustomerAgreementPdf: (file: File) => readAgreementPdf(file),
}));
vi.mock('@/api/businessPolicies', () => ({ businessPoliciesApi: { get: vi.fn() } }));
vi.mock('@/api/b2b/portal-access', () => ({ portalAccessApi: { inviteCustomer: vi.fn() } }));
vi.mock('@/api/crm/products', () => ({ productsApi: { options: productOptions } }));
vi.mock('@/api/crm/customers', () => ({ crmCustomersApi: {
  create: vi.fn(), show: vi.fn(), checkName: vi.fn(), productAccess: vi.fn(), productOrderLimitCapability: vi.fn(), warrantyOptions: vi.fn(),
} }));
vi.mock('@/hooks/useFormSafety', () => ({
  useFormSafety: () => ({
    allowNavigationAfterSave: vi.fn(),
    draftState: { hasDraft: false, draftAge: 0, restore: vi.fn(), discard: vi.fn() },
  }),
}));
vi.mock('@/components/ui/FormDraftBanner', () => ({ FormDraftBanner: () => null }));
vi.mock('@/components/crm/CustomerProductAccessManager', () => ({ CustomerProductAccessManager: () => <div>Portal product access setup</div> }));
vi.mock('@/components/crm/CustomerWarrantyOptionsManager', () => ({ CustomerWarrantyOptionsManager: () => <div>Product warranty choices setup</div> }));
vi.mock('@/components/contracts/AgreementDocumentManager', () => ({
  AgreementDocumentManager: ({ previewValues, pendingFiles }: { previewValues: Record<string, string | null | undefined>; pendingFiles?: File[] }) => (
    <div><span>Agreement documents setup</span>{pendingFiles?.map((file) => <span key={file.name}>Queued PDF: {file.name}</span>)}<pre data-testid="agreement-preview">{JSON.stringify(previewValues)}</pre></div>
  ),
}));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const createdCustomer = {
  id: 'customer-new',
  name: 'Makati Automotive Components, Inc.',
  is_active: true,
  payment_terms_days: 30,
  warranty_duration_days: 180,
  warranty_report_window_days: 30,
  contact_person: 'Mina Santos',
  contact_person_role: 'Procurement Manager',
  email: 'mina@example.test',
  phone: '+63 2 8123 4567',
  address: 'Billing address, Makati',
  delivery_address: 'Receiving gate, Laguna',
  delivery_latitude: '14.2500000',
  delivery_longitude: '121.1000000',
  allowed_payment_methods: null,
  shipping_payer: 'customer',
  agreement_starts_on: '2026-01-01',
  agreement_ends_on: '2026-12-31',
} as Customer;

async function advanceToAgreementStep(customerName = 'Makati Automotive Components, Inc.') {
  if (customerName) {
    fireEvent.change(screen.getByRole('textbox', { name: 'Customer name' }), {
      target: { value: customerName },
    });
  }
  if (!inputValue('Contact person')) {
    fireEvent.change(screen.getByRole('textbox', { name: 'Contact person' }), { target: { value: 'Mina Santos' } });
  }
  if (!inputValue('Contact role / title')) {
    fireEvent.change(screen.getByRole('textbox', { name: 'Contact role / title' }), { target: { value: 'Procurement Manager' } });
  }
  if (!inputValue('Email') && !inputValue('Phone')) {
    fireEvent.change(screen.getByRole('textbox', { name: 'Email' }), { target: { value: 'mina@example.test' } });
  }
  await clickContinue();
  expect(await screen.findByRole('heading', { name: 'Billing & payment' })).toBeVisible();
  if (!inputValue('Billing address')) {
    fireEvent.change(screen.getByRole('textbox', { name: 'Billing address' }), { target: { value: '100 Commerce Avenue, Biñan, Laguna' } });
  }
  const cashMethod = screen.getByRole('checkbox', { name: 'Cash' });
  if (!(cashMethod as HTMLInputElement).checked) fireEvent.click(cashMethod);
  await clickContinue();
  expect(await screen.findByRole('heading', { name: 'Delivery' })).toBeVisible();
  if (!inputValue('Delivery address')) {
    fireEvent.change(screen.getByRole('textbox', { name: 'Delivery address' }), { target: { value: 'Receiving gate, Santa Rosa, Laguna' } });
  }
  fireEvent.change(screen.getByRole('textbox', { name: 'Delivery latitude' }), { target: { value: '14.2500000' } });
  fireEvent.change(screen.getByRole('textbox', { name: 'Delivery longitude' }), { target: { value: '121.1000000' } });
  await clickContinue();
  expect(await screen.findByRole('heading', { name: 'Agreement & products' })).toBeVisible();
}

async function clickContinue() {
  const button = await screen.findByRole('button', { name: 'Continue' });
  await waitFor(() => expect(button).toBeEnabled());
  fireEvent.click(button);
}

function inputValue(name: string) {
  return (screen.getByRole('textbox', { name }) as HTMLInputElement).value;
}

async function selectFirstProduct() {
  fireEvent.click(screen.getByRole('checkbox', { name: /PN-900.*Rear lamp assembly/ }));
}

async function addWarrantyForFirstProduct(label = 'Standard product warranty') {
  fireEvent.click(screen.getByRole('button', { name: 'Add warranty choice' }));
  fireEvent.change(screen.getByRole('textbox', { name: /Warranty option name for Rear lamp assembly/ }), {
    target: { value: label },
  });
}

describe('CRM customer create freshness', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(crmCustomersApi.checkName).mockImplementation(async (name) => ({
      exists: false, count: 0, matches: [], normalized_name: name,
    }));
    vi.mocked(businessPoliciesApi.get).mockResolvedValue({
      customer_payment_terms_days: 30,
    } as never);
    productOptions.mockResolvedValue({
      data: [
        { id: 'product-1', part_number: 'PN-900', name: 'Rear lamp assembly', image_url: null, primary_image_url: null, images: [], specifications: {}, is_customizable: false, unit_of_measure: 'pcs', include_forecast_in_mrp: false },
        { id: 'product-2', part_number: 'PN-220', name: 'Body wiring harness', image_url: null, primary_image_url: null, images: [], specifications: {}, is_customizable: false, unit_of_measure: 'pcs', include_forecast_in_mrp: false },
      ],
      meta: { current_page: 1, last_page: 1, per_page: 100, total: 2, from: 1, to: 2 },
      links: { first: '', last: '', prev: null, next: null },
    });
    readAgreementPdf.mockResolvedValue([
      'Customer Supply Agreement',
      'Customer legal name: Pacific Automotive Components, Inc.',
      'Customer default payment terms: Net 45 calendar days',
    ].join('\n'));
    vi.mocked(crmCustomersApi.create).mockResolvedValue(createdCustomer);
    vi.mocked(portalAccessApi.inviteCustomer).mockResolvedValue({} as never);
    vi.mocked(crmCustomersApi.show).mockResolvedValue(createdCustomer);
    vi.mocked(crmCustomersApi.productOrderLimitCapability).mockResolvedValue({ supported: false });
    vi.mocked(crmCustomersApi.productAccess).mockResolvedValue({
      portal_catalog_mode: 'restricted', order_limits_supported: false, product_ids: ['product-1'], products: [
        { id: 'product-1', part_number: 'PN-900', name: 'Rear lamp assembly', unit_of_measure: 'pcs', image_url: null, portal_visibility: 'restricted', is_explicitly_authorized: true, is_authorized: true, minimum_order_quantity: '1', maximum_order_quantity: null },
      ],
    });
    vi.mocked(crmCustomersApi.warrantyOptions).mockResolvedValue([{
      id: 'warranty-1', product: { id: 'product-1', part_number: 'PN-900', name: 'Rear lamp assembly', unit_of_measure: 'pcs' },
      label: '12 months', warranty_duration_days: 365, warranty_report_window_days: 30,
      warranty_start_basis: 'delivery', warranty_covered_issues: ['manufacturing_defect'], warranty_remedies: ['repair'], additional_terms: null,
    }]);
  });

  it('blocks an existing customer name in step one and links to the existing record', async () => {
    vi.mocked(crmCustomersApi.checkName).mockResolvedValue({
      exists: true,
      count: 2,
      normalized_name: 'demo automotive components, inc.',
      matches: [
        { id: 'customer-existing', name: 'Demo Automotive Components, Inc.', code: 'CUS-001' },
        { id: 'customer-existing-2', name: 'Demo Automotive Components, Inc.', code: 'CUS-002' },
      ],
    });
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/crm/customers/create']}>
          <Routes>
            <Route path="/crm/customers/create" element={<CrmCustomerCreatePage />} />
            <Route path="/crm/customers/:id" element={<span>Existing customer record</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.change(await screen.findByRole('textbox', { name: 'Customer name' }), {
      target: { value: '  demo automotive components, inc.  ' },
    });

    expect(await screen.findByRole('alert')).toHaveTextContent('2 existing records');
    expect(crmCustomersApi.checkName).toHaveBeenCalledWith('demo automotive components, inc.');
    expect(screen.getAllByRole('link', { name: /Open Demo Automotive Components, Inc\./ })[0])
      .toHaveAttribute('href', '/crm/customers/customer-existing');
    expect(screen.getByRole('button', { name: 'Customer name already exists' })).toBeDisabled();
    expect(crmCustomersApi.create).not.toHaveBeenCalled();
    queryClient.clear();
  });

  it('rejects common placeholder customer names before leaving step one', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/crm/customers/create']}>
          <Routes>
            <Route path="/crm/customers/create" element={<CrmCustomerCreatePage />} />
            <Route path="/crm/customers/:id" element={<span>Existing customer record</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.change(await screen.findByRole('textbox', { name: 'Customer name' }), {
      target: { value: 'Customer 1' },
    });
    await clickContinue();

    expect(await screen.findByText('Enter the actual customer name, not a placeholder.')).toBeVisible();
    expect(screen.getByRole('heading', { name: 'Customer & contact' })).toBeVisible();
    expect(crmCustomersApi.create).not.toHaveBeenCalled();
    queryClient.clear();
  });

  it('guides customer entry step by step and preserves fields when navigating back', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/crm/customers/create']}>
          <Routes>
            <Route path="/crm/customers/create" element={<CrmCustomerCreatePage />} />
            <Route path="/crm/customers/:id" element={<span>CRM customer detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByRole('heading', { name: 'Customer & contact' })).toBeVisible();
    fireEvent.change(screen.getByRole('textbox', { name: 'Customer name' }), {
      target: { value: 'Laguna Automotive Parts, Inc.' },
    });
    fireEvent.change(screen.getByRole('textbox', { name: 'Contact person' }), { target: { value: 'Mina Santos' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Contact role / title' }), { target: { value: 'Sales Officer' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Email' }), { target: { value: 'mina@example.test' } });
    expect(screen.queryByRole('textbox', { name: 'Billing address' })).not.toBeInTheDocument();
    expect(screen.getByLabelText(/Select PDF files/)).toBeVisible();

    await clickContinue();
    expect(await screen.findByRole('heading', { name: 'Billing & payment' })).toBeVisible();
    expect(screen.queryByRole('textbox', { name: 'Customer name' })).not.toBeInTheDocument();
    await waitFor(() => expect(screen.getByRole('spinbutton', { name: 'Default payment terms (days)' })).toHaveValue(30));

    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    expect(screen.getByRole('textbox', { name: 'Customer name' })).toHaveValue('Laguna Automotive Parts, Inc.');
    expect(crmCustomersApi.create).not.toHaveBeenCalled();
    queryClient.clear();
  });

  it('requires customer contact, billing, payment, and delivery details before advancing each step', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/crm/customers/create']}>
          <Routes>
            <Route path="/crm/customers/create" element={<CrmCustomerCreatePage />} />
            <Route path="/crm/customers/:id" element={<span>CRM customer detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.change(await screen.findByRole('textbox', { name: 'Customer name' }), { target: { value: 'Cavite Precision Parts, Inc.' } });
    expect(screen.getByText(/provide an email address or phone number/)).toBeVisible();
    await clickContinue();
    expect(screen.getByRole('heading', { name: 'Customer & contact' })).toBeVisible();
    expect(await screen.findByText('Enter the contact person’s name.')).toBeVisible();
    expect(screen.getByText('Enter the contact person’s role or title.')).toBeVisible();

    fireEvent.change(screen.getByRole('textbox', { name: 'Contact person' }), { target: { value: 'Mina Santos' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Contact role / title' }), { target: { value: 'Sales Officer' } });
    await clickContinue();
    expect(screen.getByRole('heading', { name: 'Customer & contact' })).toBeVisible();
    expect(await screen.findByText('Provide an email address or phone number.')).toBeVisible();

    fireEvent.change(screen.getByRole('textbox', { name: 'Email' }), { target: { value: 'mina@example.test' } });
    await clickContinue();
    expect(await screen.findByRole('heading', { name: 'Billing & payment' })).toBeVisible();
    const paymentTerms = screen.getByRole('spinbutton', { name: 'Default payment terms (days)' });
    await waitFor(() => expect(paymentTerms).toHaveValue(30));
    expect(screen.getByRole('textbox', { name: /Billing address/ })).toBeRequired();
    expect(screen.queryByText('Select at least one allowed payment method.')).not.toBeInTheDocument();
    fireEvent.change(paymentTerms, { target: { value: '' } });
    await clickContinue();
    expect(screen.getByRole('heading', { name: 'Billing & payment' })).toBeVisible();
    expect(await screen.findByText('Enter the billing address.')).toBeVisible();
    expect(screen.getByText('Enter the default payment terms.')).toBeVisible();
    expect(screen.getByText('Select at least one allowed payment method.')).toBeVisible();

    fireEvent.change(screen.getByRole('textbox', { name: 'Billing address' }), { target: { value: '100 Commerce Avenue, Biñan, Laguna' } });
    fireEvent.change(paymentTerms, { target: { value: '30' } });
    fireEvent.click(screen.getByRole('checkbox', { name: 'Cash' }));
    await clickContinue();
    expect(await screen.findByRole('heading', { name: 'Delivery' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    expect(await screen.findByRole('heading', { name: 'Billing & payment' })).toBeVisible();
    fireEvent.click(screen.getByRole('checkbox', { name: 'Cash' }));
    fireEvent.click(screen.getByRole('button', { name: 'Step 3: Delivery' }));
    expect(screen.getByRole('heading', { name: 'Billing & payment' })).toBeVisible();
    expect(await screen.findByText('Select at least one allowed payment method.')).toBeVisible();
    fireEvent.click(screen.getByRole('checkbox', { name: 'Cash' }));
    await clickContinue();
    expect(await screen.findByRole('heading', { name: 'Delivery' })).toBeVisible();
    expect(screen.getByRole('textbox', { name: /Delivery address/ })).toBeRequired();
    await clickContinue();
    expect(await screen.findByText('Enter the delivery address.')).toBeVisible();
    expect(screen.getByText('Select the delivery location on the map.')).toBeVisible();
    fireEvent.change(screen.getByRole('textbox', { name: 'Delivery latitude' }), { target: { value: '14.2500000' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Delivery longitude' }), { target: { value: '121.1000000' } });
    await clickContinue();
    expect(screen.getByRole('heading', { name: 'Delivery' })).toBeVisible();
    expect(screen.getByText('Enter the delivery address.')).toBeVisible();
    expect(crmCustomersApi.create).not.toHaveBeenCalled();

    fireEvent.change(screen.getByRole('textbox', { name: 'Delivery address' }), { target: { value: 'Receiving gate, Santa Rosa, Laguna' } });
    await clickContinue();
    expect(await screen.findByRole('heading', { name: 'Agreement & products' })).toBeVisible();
    queryClient.clear();
  });

  it('uses agreement PDF values during creation and carries the original file into agreement setup', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/crm/customers/create']}>
          <Routes>
            <Route path="/crm/customers/create" element={<CrmCustomerCreatePage />} />
            <Route path="/crm/customers/:id" element={<span>CRM customer detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    const file = new File(['local pdf bytes'], 'customer-agreement.pdf', { type: 'application/pdf' });
    fireEvent.change(screen.getByLabelText(/Select PDF files/), { target: { files: [file] } });
    await waitFor(() => expect(screen.getByRole('textbox', { name: 'Customer name' })).toHaveValue('Pacific Automotive Components, Inc.'));
    expect(await screen.findByText('customer-agreement.pdf')).toBeVisible();
    await advanceToAgreementStep('');

    await selectFirstProduct();
    await addWarrantyForFirstProduct();
    await clickContinue();
    expect(await screen.findByRole('heading', { name: 'Review' })).toBeVisible();
    expect(within(screen.getByRole('region', { name: 'Customer & contact' })).getByText('Pacific Automotive Components, Inc.')).toBeVisible();
    expect(within(screen.getByRole('region', { name: 'Billing & payment' })).getByText('45 days')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Create customer' }));

    await waitFor(() => expect(crmCustomersApi.create).toHaveBeenCalledWith(expect.objectContaining({
      name: 'Pacific Automotive Components, Inc.', payment_terms_days: 45,
    })));
    expect(await screen.findByText('Queued PDF: customer-agreement.pdf')).toBeVisible();
    queryClient.clear();
  });

  it('queues an optional customer portal invitation only after customer creation succeeds', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/crm/customers/create']}>
          <Routes>
            <Route path="/crm/customers/create" element={<CrmCustomerCreatePage />} />
            <Route path="/crm/customers/:id" element={<span>CRM customer detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    await advanceToAgreementStep();
    await selectFirstProduct();
    await addWarrantyForFirstProduct();
    await clickContinue();
    expect(await screen.findByRole('heading', { name: 'Review' })).toBeVisible();
    expect(screen.getByRole('checkbox', { name: /Send portal invitation after creation/i })).not.toBeChecked();
    fireEvent.click(screen.getByRole('checkbox', { name: /Send portal invitation after creation/i }));
    fireEvent.click(screen.getByRole('checkbox', { name: /Authorized signatory/i }));
    fireEvent.click(screen.getByRole('button', { name: 'Create customer' }));

    await waitFor(() => expect(crmCustomersApi.create).toHaveBeenCalled());
    await waitFor(() => expect(portalAccessApi.inviteCustomer).toHaveBeenCalledWith('customer-new', {
      name: 'Mina Santos', email: 'mina@example.test', is_authorized_signatory: true,
    }));
    expect(vi.mocked(portalAccessApi.inviteCustomer).mock.invocationCallOrder[0])
      .toBeGreaterThan(vi.mocked(crmCustomersApi.create).mock.invocationCallOrder[0]);
    queryClient.clear();
  }, 15000);

  it('matches authorized products and per-product warranty choices from an agreement before creating the customer', async () => {
    vi.mocked(crmCustomersApi.productOrderLimitCapability).mockResolvedValue({ supported: true });
    readAgreementPdf.mockResolvedValue([
      'Customer Supply Agreement',
      'Customer legal name: Product Setup Customer',
      'Customer default payment terms: Net 30 calendar days',
      'Products currently authorized for this customer',
      'PN-900 · Rear lamp assembly | Minimum order quantity: 5 units | Maximum order quantity per sales order: 250 units',
      'PN-220 · Body wiring harness | Minimum order quantity: 1 unit | Maximum order quantity per sales order: 80 units',
      'Warranty terms by product and available option',
      'PN-900 · 12-month standard: 365 days from delivery; report within 30 days; manufacturing defects and shipping damage; repair or replacement.',
      'PN-220 · 6-month limited: 180 days from delivery; report within 14 days; manufacturing defects; replacement of affected part.',
    ].join('\n'));
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/crm/customers/create']}>
          <Routes>
            <Route path="/crm/customers/create" element={<CrmCustomerCreatePage />} />
            <Route path="/crm/customers/:id" element={<span>CRM customer detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    const agreementFile = new File(['local PDF bytes'], 'customer-agreement.pdf', { type: 'application/pdf' });
    fireEvent.change(screen.getByLabelText(/Select PDF files/), { target: { files: [agreementFile] } });
    await waitFor(() => expect(screen.getByRole('textbox', { name: 'Customer name' })).toHaveValue('Product Setup Customer'));
    await advanceToAgreementStep('');
    expect(await screen.findByRole('heading', { name: 'Product Ordering Setup' })).toBeVisible();
    expect(await screen.findByRole('checkbox', { name: /PN-900.*Rear lamp assembly/ })).toBeChecked();
    expect(screen.getByRole('checkbox', { name: /PN-220.*Body wiring harness/ })).toBeChecked();
    expect(screen.getByRole('textbox', { name: /Warranty option name for Rear lamp assembly/ })).toHaveValue('12-month standard');
    expect(screen.getByRole('textbox', { name: /Warranty option name for Body wiring harness/ })).toHaveValue('6-month limited');
    expect(screen.getByRole('spinbutton', { name: 'Minimum order quantity for PN-900' })).toHaveValue(5);
    expect(screen.getByRole('spinbutton', { name: 'Maximum order quantity for PN-900 (optional)' })).toHaveValue(250);
    expect(screen.queryByText(/Fallback warranty settings \(optional\)/)).not.toBeInTheDocument();

    await clickContinue();
    expect(await screen.findByRole('heading', { name: 'Review' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Create customer' }));

    await waitFor(() => expect(crmCustomersApi.create).toHaveBeenCalledWith(expect.objectContaining({
      name: 'Product Setup Customer',
      portal_catalog_mode: 'restricted',
      portal_product_ids: ['product-1', 'product-2'],
      product_order_limits: [
        { product_id: 'product-1', minimum_order_quantity: 5, maximum_order_quantity: 250 },
        { product_id: 'product-2', minimum_order_quantity: 1, maximum_order_quantity: 80 },
      ],
      product_warranties: [
        expect.objectContaining({ product_id: 'product-1', label: '12-month standard', warranty_duration_days: 365, warranty_report_window_days: 30, warranty_start_basis: 'delivery' }),
        expect.objectContaining({ product_id: 'product-2', label: '6-month limited', warranty_duration_days: 180, warranty_report_window_days: 14, warranty_start_basis: 'delivery' }),
      ],
    })));
    queryClient.clear();
  }, 15000);

  it('shows unmatched PDF references as a notice and requires products with named warranty choices', async () => {
    readAgreementPdf.mockResolvedValue([
      'Customer Supply Agreement',
      'Customer legal name: Pacific Automotive Components, Inc.',
      'Customer default payment terms: Net 30 calendar days',
      'Products currently authorized for this customer',
      'OLD-900 · Rear lamp assembly',
      'OLD-220 · Body wiring harness',
    ].join('\n'));
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/crm/customers/create']}>
          <Routes>
            <Route path="/crm/customers/create" element={<CrmCustomerCreatePage />} />
            <Route path="/crm/customers/:id" element={<span>CRM customer detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.change(screen.getByLabelText(/Select PDF files/), {
      target: { files: [new File(['agreement'], 'customer-agreement.pdf', { type: 'application/pdf' })] },
    });
    await waitFor(() => expect(screen.getByRole('textbox', { name: 'Customer name' })).toHaveValue('Pacific Automotive Components, Inc.'));
    await advanceToAgreementStep('');

    const mismatchNotice = screen.getByRole('complementary', { name: 'Unmatched PDF part numbers' });
    expect(within(mismatchNotice).getByText('2 PDF part numbers could not be matched automatically.')).toBeVisible();
    expect(within(mismatchNotice).getByText('OLD-900')).toBeVisible();
    expect(within(mismatchNotice).getByText('OLD-220')).toBeVisible();
    expect(screen.queryByRole('button', { name: /Match PDF part number/ })).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/Match PDF part number/)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Select products and configure warranties' })).toBeDisabled();

    await selectFirstProduct();
    expect(screen.getByRole('button', { name: 'Select products and configure warranties' })).toBeDisabled();
    await addWarrantyForFirstProduct();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Continue' })).toBeEnabled());
    await clickContinue();
    expect(await screen.findByRole('heading', { name: 'Review' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Create customer' }));

    await waitFor(() => expect(crmCustomersApi.create).toHaveBeenCalledWith(expect.objectContaining({
      portal_product_ids: ['product-1'],
      product_warranties: [expect.objectContaining({ product_id: 'product-1', label: 'Standard product warranty' })],
    })));
    queryClient.clear();
  });

  it('requires and saves a description when Other is selected for a product warranty issue', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/crm/customers/create']}>
          <Routes>
            <Route path="/crm/customers/create" element={<CrmCustomerCreatePage />} />
            <Route path="/crm/customers/:id" element={<span>CRM customer detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );
    await advanceToAgreementStep();
    await selectFirstProduct();
    await addWarrantyForFirstProduct();

    fireEvent.click(screen.getByRole('checkbox', { name: 'Other covered issue for Rear lamp assembly' }));
    expect(screen.getByRole('textbox', { name: 'Describe other covered issue for Rear lamp assembly' })).toBeRequired();
    expect(screen.getByRole('button', { name: 'Select products and configure warranties' })).toBeDisabled();

    fireEvent.change(screen.getByRole('textbox', { name: 'Describe other covered issue for Rear lamp assembly' }), {
      target: { value: 'Thread corrosion' },
    });
    fireEvent.change(screen.getByRole('textbox', { name: 'Additional terms for Rear lamp assembly (optional)' }), {
      target: { value: 'Replacement parts included.' },
    });
    expect(screen.getByRole('button', { name: 'Continue' })).toBeEnabled();
    await clickContinue();
    expect(await screen.findByRole('heading', { name: 'Review' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Create customer' }));

    await waitFor(() => expect(crmCustomersApi.create).toHaveBeenCalledWith(expect.objectContaining({
      product_warranties: [expect.objectContaining({
        product_id: 'product-1',
        warranty_covered_issues: [],
        additional_terms: 'Other covered issue: Thread corrosion\nReplacement parts included.',
      })],
    })));
    queryClient.clear();
  });

  it('waits for PDF reading to finish before allowing customer creation', async () => {
    let finishReading!: (text: string) => void;
    readAgreementPdf.mockReturnValue(new Promise<string>((resolve) => { finishReading = resolve; }));
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/crm/customers/create']}>
          <Routes>
            <Route path="/crm/customers/create" element={<CrmCustomerCreatePage />} />
            <Route path="/crm/customers/:id" element={<span>CRM customer detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );
    const file = new File(['local pdf bytes'], 'customer-agreement.pdf', { type: 'application/pdf' });
    fireEvent.change(screen.getByLabelText(/Select PDF files/), { target: { files: [file] } });

    expect(await screen.findByRole('status')).toHaveTextContent(/Reading and checking your PDF/);
    expect(screen.getByRole('button', { name: 'Reading PDF…' })).toBeDisabled();
    finishReading('Customer Supply Agreement\nCustomer legal name: Read Customer\nCustomer default payment terms: Net 30 days');
    await waitFor(() => expect(screen.getByRole('textbox', { name: 'Customer name' })).toHaveValue('Read Customer'));
    await advanceToAgreementStep('');
    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    expect(screen.getByRole('textbox', { name: 'Customer name' })).toHaveValue('Read Customer');
    queryClient.clear();
  });

  it('keeps customer creation in a setup flow with product access, warranties, and auto-fill agreement preview', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/crm/customers/create']}>
          <Routes>
            <Route path="/crm/customers/create" element={<CrmCustomerCreatePage />} />
            <Route path="/crm/customers/:id" element={<span>CRM customer detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.change(screen.getByRole('textbox', { name: 'Customer name' }), { target: { value: 'Makati Automotive Components, Inc.' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Contact person' }), { target: { value: 'Mina Santos' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Contact role / title' }), { target: { value: 'Procurement Manager' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Email' }), { target: { value: 'mina@example.test' } });
    await clickContinue();
    expect(await screen.findByRole('heading', { name: 'Billing & payment' })).toBeVisible();
    await waitFor(() => expect(screen.getByRole('spinbutton', { name: 'Default payment terms (days)' }))
      .toHaveValue(30));
    fireEvent.change(screen.getByRole('textbox', { name: 'Billing address' }), { target: { value: '100 Commerce Avenue, Biñan, Laguna' } });
    fireEvent.click(screen.getByRole('checkbox', { name: 'Cash' }));
    await clickContinue();
    expect(await screen.findByRole('heading', { name: 'Delivery' })).toBeVisible();
    fireEvent.change(screen.getByRole('textbox', { name: 'Delivery address' }), { target: { value: 'Receiving gate, Santa Rosa, Laguna' } });
    await clickContinue();
    expect(await screen.findByText('Select the delivery location on the map.')).toBeVisible();
    expect(crmCustomersApi.create).not.toHaveBeenCalled();

    fireEvent.change(screen.getByRole('textbox', { name: 'Delivery latitude' }), {
      target: { value: '14.2500000' },
    });
    fireEvent.change(screen.getByRole('textbox', { name: 'Delivery longitude' }), {
      target: { value: '121.1000000' },
    });
    await clickContinue();
    expect(await screen.findByRole('heading', { name: 'Agreement & products' })).toBeVisible();
    expect(screen.getByRole('button', { name: 'Select products and configure warranties' })).toBeDisabled();
    await selectFirstProduct();
    expect(screen.getByRole('button', { name: 'Select products and configure warranties' })).toBeDisabled();
    await addWarrantyForFirstProduct();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Continue' })).toBeEnabled());
    await clickContinue();
    expect(await screen.findByRole('heading', { name: 'Review' })).toBeVisible();
    expect(crmCustomersApi.create).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Create customer' }));

    await waitFor(() => expect(crmCustomersApi.create).toHaveBeenCalledOnce());
    expect(crmCustomersApi.create).toHaveBeenCalledWith(expect.objectContaining({
      portal_catalog_mode: 'restricted',
      portal_product_ids: ['product-1'],
      product_warranties: [expect.objectContaining({ product_id: 'product-1', label: 'Standard product warranty' })],
      credit_limit: null,
    }));
    expect(crmCustomersApi.create).not.toHaveBeenCalledWith(expect.objectContaining({
      warranty_duration_days: expect.anything(),
      warranty_report_window_days: expect.anything(),
      warranty_start_basis: expect.anything(),
      warranty_covered_issues: expect.anything(),
      warranty_remedies: expect.anything(),
    }));
    expect(screen.queryByText(/Fallback warranty settings \(optional\)/)).not.toBeInTheDocument();
    expect(screen.getByText(/Product access and warranty choices are already set/)).toBeVisible();
    expect(screen.queryByRole('heading', { name: 'Saved product access and warranties' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Save access' })).not.toBeInTheDocument();
    expect(screen.queryByRole('form', { name: 'Add product warranty choice' })).not.toBeInTheDocument();
    expect(screen.getByText('Agreement documents setup')).toBeVisible();
    const preview = JSON.parse(screen.getByTestId('agreement-preview').textContent ?? '{}') as Record<string, string | null>;
    expect(preview.customer_name).toBe('Makati Automotive Components, Inc.');
    expect(preview.customer_contact_role).toBe('Procurement Manager');
    expect(preview.delivery_map_pin).toBe('14.2500000, 121.1000000');
    expect(preview.allowed_products_summary).toContain('PN-900 · Rear lamp assembly');
    expect(preview.warranty_summary).toContain('12 months');
    expect(screen.getByRole('note')).toHaveTextContent(/Agreement files remain drafts until reviewed and issued/i);
    fireEvent.click(screen.getByRole('button', { name: 'Finish and open customer record' }));
    expect(await screen.findByText('CRM customer detail route')).toBeVisible();
    await waitFor(() => expect(queryClient.getQueryData([
      'crm', 'customers', 'detail', 'customer-new',
    ])).toEqual(createdCustomer));
    queryClient.clear();
  });

  it('reopens the setup step after refresh without presenting a blank create form', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/crm/customers/create?setup=customer-new']}>
          <Routes>
            <Route path="/crm/customers/create" element={<CrmCustomerCreatePage />} />
            <Route path="/crm/customers/:id" element={<span>CRM customer detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByRole('heading', { name: 'Customer agreement documents' })).toBeVisible();
    expect(screen.queryByRole('textbox', { name: 'Customer name' })).not.toBeInTheDocument();
    expect(crmCustomersApi.show).toHaveBeenCalledWith('customer-new');
    expect(crmCustomersApi.create).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Finish and open customer record' }));
    expect(await screen.findByText('CRM customer detail route')).toBeVisible();
    queryClient.clear();
  });
});
