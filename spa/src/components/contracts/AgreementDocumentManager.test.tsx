import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { portalContractsApi } from '@/api/b2b/contracts';
import { vendorsApi } from '@/api/accounting/vendors';
import { usePermission } from '@/hooks/usePermission';
import { AgreementDocumentManager } from './AgreementDocumentManager';

vi.mock('@/api/b2b/contracts', () => ({ portalContractsApi: {
  listCustomerDocuments: vi.fn(), listSupplierDocuments: vi.fn(), attachCustomerPdf: vi.fn(), listTemplates: vi.fn(), inspectTemplate: vi.fn(),
  createTemplate: vi.fn(), issueCustomerTemplate: vi.fn(), issueSupplierTemplate: vi.fn(),
} }));
vi.mock('@/api/accounting/vendors', () => ({ vendorsApi: { show: vi.fn() } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: vi.fn() }));

function renderManager(pendingFiles: File[] = []) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <AgreementDocumentManager
        realm="customer"
        entityId="customer-2"
        entityName="Customer 2"
        allowTemplateCreation
        pendingFiles={pendingFiles}
        previewValues={{ customer_name: 'Customer 2', payment_terms: '45 days' }}
      />
    </QueryClientProvider>,
  );
}

function renderSupplierManager() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <AgreementDocumentManager realm="supplier" entityId="supplier-2" entityName="Supplier 2" />
    </QueryClientProvider>,
  );
}

describe('AgreementDocumentManager template setup', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(usePermission).mockReturnValue({ can: (permission: string) => permission !== 'denied' } as never);
    vi.mocked(portalContractsApi.listCustomerDocuments).mockResolvedValue([]);
    vi.mocked(portalContractsApi.listSupplierDocuments).mockResolvedValue([]);
    vi.mocked(portalContractsApi.attachCustomerPdf).mockResolvedValue({
      id: 'document-new', file_name: 'customer-agreement.pdf', file_size: 1024, signature_status: 'draft',
    });
    vi.mocked(portalContractsApi.listTemplates).mockResolvedValue([]);
    vi.mocked(portalContractsApi.inspectTemplate).mockResolvedValue({
      pdf_fields: [{ name: 'Buyer name', type: 'Text' }], auto_fill_available: true, realm: 'customer',
    });
    vi.mocked(portalContractsApi.createTemplate).mockResolvedValue({
      id: 'template-2', name: 'Customer supply agreement', realm: 'customer', filename: 'agreement.pdf',
      pdf_fields: [{ name: 'Buyer name', type: 'Text' }], field_mappings: { 'Buyer name': 'customer_name' },
      required_fields: ['Buyer name'], version: 1, auto_fill_available: true,
    });
    vi.mocked(portalContractsApi.issueCustomerTemplate).mockResolvedValue({
      id: 'draft-2', file_name: 'Customer supply agreement.pdf', file_size: 1024, signature_status: 'draft',
    });
    vi.mocked(portalContractsApi.issueSupplierTemplate).mockResolvedValue({
      id: 'draft-supplier', file_name: 'Supplier agreement.pdf', file_size: 1024, signature_status: 'draft',
    });
    vi.mocked(vendorsApi.show).mockResolvedValue({
      id: 'supplier-2', name: 'Supplier 2', contact_person: 'Mina Lee', email: 'mina@example.test',
      phone: '+63 912 345 6789', address: 'Supplier Road, Laguna', payment_terms_days: 30,
    } as never);
  });

  it('maps an uploaded fillable agreement to saved customer data and creates an auto-filled draft', async () => {
    renderManager();

    fireEvent.click(await screen.findByRole('button', { name: 'Create and fill from a PDF' }));
    fireEvent.change(screen.getByRole('textbox', { name: 'Template name' }), { target: { value: 'Customer supply agreement' } });
    const file = new File(['%PDF-1.4'], 'customer-agreement.pdf', { type: 'application/pdf' });
    fireEvent.change(screen.getByLabelText('PDF template'), { target: { files: [file] } });
    fireEvent.click(screen.getByRole('button', { name: 'Read PDF fields' }));
    fireEvent.change(await screen.findByRole('combobox', { name: 'Map “Buyer name” to' }), { target: { value: 'customer_name' } });
    expect(await screen.findByText('Preview: Customer 2')).toBeVisible();

    fireEvent.submit(screen.getByRole('button', { name: 'Save contract template' }).closest('form')!);

    await waitFor(() => expect(portalContractsApi.createTemplate).toHaveBeenCalledWith(expect.objectContaining({
      name: 'Customer supply agreement', realm: 'customer', file,
      field_mappings: { 'Buyer name': 'customer_name' }, required_fields: [],
    })));
    await waitFor(() => expect(portalContractsApi.issueCustomerTemplate).toHaveBeenCalledWith('customer-2', 'template-2'));
  });

  it('explains attachment-only behavior when the user cannot manage mapped templates', async () => {
    vi.mocked(usePermission).mockReturnValue({ can: (permission: string) => permission === 'accounting.customers.manage' } as never);
    renderManager();

    expect(await screen.findByRole('note')).toHaveTextContent(/Automatic filling at this step requires a mapped fillable template/);
    expect(screen.getByLabelText('Attach a PDF')).toBeEnabled();
    expect(screen.queryByRole('button', { name: 'Create and fill from a PDF' })).not.toBeInTheDocument();
    expect(portalContractsApi.listTemplates).not.toHaveBeenCalled();
  });

  it('attaches PDFs selected during customer creation without asking staff to select them again', async () => {
    const file = new File(['%PDF-1.4'], 'customer-agreement.pdf', { type: 'application/pdf' });
    renderManager([file]);

    expect(await screen.findByText('customer-agreement.pdf')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Attach customer-agreement.pdf' }));

    await waitFor(() => expect(portalContractsApi.attachCustomerPdf).toHaveBeenCalledWith('customer-2', file));
    expect(await screen.findByRole('button', { name: 'Attached customer-agreement.pdf' })).toBeDisabled();
  });

  it('collects required supplier agreement dates before generating a mapped draft', async () => {
    vi.mocked(portalContractsApi.listTemplates).mockResolvedValue([{
      id: 'supplier-template-1', name: 'Supplier agreement', realm: 'supplier', filename: 'supplier.pdf',
      pdf_fields: [{ name: 'StartDate', type: 'Text' }, { name: 'EndDate', type: 'Text' }],
      field_mappings: { StartDate: 'effective_date', EndDate: 'expiry_date' },
      required_fields: ['StartDate', 'EndDate'], version: 1, auto_fill_available: true,
    }]);
    renderSupplierManager();

    const templateSelector = await screen.findByRole('combobox', { name: 'Generate from a mapped template' });
    await screen.findByRole('option', { name: 'Supplier agreement · v1' });
    await waitFor(() => expect(templateSelector).toBeEnabled());
    fireEvent.change(templateSelector, {
      target: { value: 'supplier-template-1' },
    });
    fireEvent.change(await screen.findByLabelText(/Agreement start date/), { target: { value: '2026-11-01' } });
    fireEvent.change(screen.getByLabelText(/Agreement end date/), { target: { value: '2027-10-31' } });
    fireEvent.click(screen.getByRole('button', { name: 'Generate PDF draft' }));

    await waitFor(() => expect(portalContractsApi.issueSupplierTemplate).toHaveBeenCalledWith(
      'supplier-2',
      'supplier-template-1',
      { effective_date: '2026-11-01', expiry_date: '2027-10-31' },
    ));
  });
});
