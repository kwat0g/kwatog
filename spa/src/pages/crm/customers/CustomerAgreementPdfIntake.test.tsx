import { FormProvider, useForm } from 'react-hook-form';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { CustomerFormInput } from './form';
import { CustomerAgreementPdfIntake } from './CustomerAgreementPdfIntake';

const { downloadSampleAgreement } = vi.hoisted(() => ({ downloadSampleAgreement: vi.fn() }));
vi.mock('@/api/download', () => ({ downloadAuthenticatedFile: downloadSampleAgreement }));

const readPdf = vi.fn<(file: File) => Promise<string>>();
vi.mock('./readCustomerAgreementPdf', () => ({
  CUSTOMER_AGREEMENT_PDF_MAX_BYTES: 10 * 1024 * 1024,
  readCustomerAgreementPdf: (file: File) => readPdf(file),
}));

function IntakeHarness({ onFilesSelected, onReadingChange }: { onFilesSelected: (files: File[]) => void; onReadingChange: (reading: boolean) => void }) {
  const methods = useForm<CustomerFormInput>({
    defaultValues: { name: '', payment_terms_days: 30, allowed_payment_methods: [] },
  });
  return (
    <FormProvider {...methods}>
      <label>Customer name<input {...methods.register('name')} /></label>
      <label>Payment terms<input type="number" {...methods.register('payment_terms_days')} /></label>
      <CustomerAgreementPdfIntake onFilesSelected={onFilesSelected} onReadingChange={onReadingChange} />
    </FormProvider>
  );
}

describe('customer agreement PDF intake', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    downloadSampleAgreement.mockResolvedValue(true);
    readPdf.mockResolvedValue([
      'Customer Supply Agreement',
      'Customer legal name: Demo Automotive Components, Inc.',
      'Customer default payment terms: Net 45 calendar days',
      'Warranty terms by product and available option',
      'PN-900 · 12-month standard: 365 days from delivery; report within 30 days; manufacturing defects; repair or replacement.',
    ].join('\n'));
  });

  it('requests a fresh authenticated sample agreement for every download click', async () => {
    render(<IntakeHarness onFilesSelected={vi.fn()} onReadingChange={vi.fn()} />);
    const downloadButton = screen.getByRole('button', { name: 'Download sample agreement' });

    fireEvent.click(downloadButton);
    await waitFor(() => expect(downloadSampleAgreement).toHaveBeenCalledWith(
      '/api/v1/crm/customers/sample-agreement',
      { errorMessage: 'Failed to generate the sample customer agreement.' },
    ));

    fireEvent.click(downloadButton);
    await waitFor(() => expect(downloadSampleAgreement).toHaveBeenCalledTimes(2));
  });

  it('fills unedited form values from a local PDF and keeps the selected file queued for attachment', async () => {
    const onFilesSelected = vi.fn();
    const onReadingChange = vi.fn();
    render(<IntakeHarness onFilesSelected={onFilesSelected} onReadingChange={onReadingChange} />);
    const file = new File(['local pdf bytes'], 'customer-agreement.pdf', { type: 'application/pdf' });

    fireEvent.change(screen.getByLabelText(/Select PDF files/), { target: { files: [file] } });

    await waitFor(() => expect(screen.getByLabelText('Customer name')).toHaveValue('Demo Automotive Components, Inc.'));
    expect(screen.getByLabelText('Payment terms')).toHaveValue(45);
    fireEvent.click(screen.getByText(/Review detected agreement terms/));
    expect(await screen.findByText(/PN-900 · 12-month standard/)).toBeVisible();
    expect(onFilesSelected).toHaveBeenCalledWith([file]);
    expect(onReadingChange).toHaveBeenNthCalledWith(1, true);
    await waitFor(() => expect(onReadingChange).toHaveBeenLastCalledWith(false));
  });

  it('ignores an older PDF result when a newer file selection finishes first', async () => {
    let finishOlderRead!: (text: string) => void;
    readPdf.mockImplementation((file) => file.name === 'older.pdf'
      ? new Promise<string>((resolve) => { finishOlderRead = resolve; })
      : Promise.resolve('Customer Supply Agreement\nCustomer legal name: Newer Customer\nCustomer default payment terms: Net 30 days'));
    const onFilesSelected = vi.fn();
    const onReadingChange = vi.fn();
    render(<IntakeHarness onFilesSelected={onFilesSelected} onReadingChange={onReadingChange} />);
    const input = screen.getByLabelText(/Select PDF files/);
    const older = new File(['older pdf'], 'older.pdf', { type: 'application/pdf' });
    const newer = new File(['newer pdf'], 'newer.pdf', { type: 'application/pdf' });

    fireEvent.change(input, { target: { files: [older] } });
    fireEvent.change(input, { target: { files: [newer] } });
    await waitFor(() => expect(screen.getByLabelText('Customer name')).toHaveValue('Newer Customer'));
    await act(async () => finishOlderRead('Customer Supply Agreement\nCustomer legal name: Older Customer\nCustomer default payment terms: Net 30 days'));

    expect(screen.getByLabelText('Customer name')).toHaveValue('Newer Customer');
    fireEvent.click(screen.getByText(/Review detected agreement terms/));
    expect(screen.getByText('Newer Customer')).toBeVisible();
    expect(screen.queryByText('Older Customer')).not.toBeInTheDocument();
    expect(onFilesSelected).toHaveBeenLastCalledWith([newer]);
  });

  it('rejects readable PDFs that do not look like an agreement or warranty schedule', async () => {
    readPdf.mockResolvedValue([
      'Quarterly Operations Summary',
      'Customer legal name: Example Software, Inc.',
      'Customer default payment terms: Net 30 calendar days',
      'This report summarizes internal software subscriptions.',
    ].join('\n'));
    const onFilesSelected = vi.fn();
    const onReadingChange = vi.fn();
    render(<IntakeHarness onFilesSelected={onFilesSelected} onReadingChange={onReadingChange} />);
    const file = new File(['PDF bytes'], 'quarterly-summary.pdf', { type: 'application/pdf' });

    fireEvent.change(screen.getByLabelText(/Select PDF files/), { target: { files: [file] } });

    expect(await screen.findByRole('alert')).toHaveTextContent(/not recognized as a customer agreement or warranty schedule/i);
    expect(screen.getByLabelText('Customer name')).toHaveValue('');
    expect(screen.queryByRole('heading', { name: 'Detected agreement values' })).not.toBeInTheDocument();
    expect(onFilesSelected).toHaveBeenLastCalledWith([]);
    expect(screen.queryByText('quarterly-summary.pdf')).not.toBeInTheDocument();
  });

  it('keeps a recognized generic warranty PDF queued without applying it as a customer fallback', async () => {
    readPdf.mockResolvedValue('Warranty coverage: 12 months from delivery; report within 30 days.');
    const onFilesSelected = vi.fn();
    const onReadingChange = vi.fn();
    render(<IntakeHarness onFilesSelected={onFilesSelected} onReadingChange={onReadingChange} />);
    const file = new File(['PDF bytes'], 'warranty-schedule.pdf', { type: 'application/pdf' });

    fireEvent.change(screen.getByLabelText(/Select PDF files/), { target: { files: [file] } });

    expect(await screen.findByText(/generic warranty terms were recognized/i)).toBeVisible();
    expect(screen.getByText(/will remain queued for attachment/i)).toBeVisible();
    expect(onFilesSelected).toHaveBeenLastCalledWith([file]);
    expect(screen.getByLabelText('Customer name')).toHaveValue('');
  });
});
