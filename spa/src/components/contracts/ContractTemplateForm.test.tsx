import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ContractTemplateForm } from './ContractTemplateForm';

describe('ContractTemplateForm', () => {
  it('maps detected fields to approved record values and previews the values staff will review', async () => {
    const onSubmit = vi.fn();
    render(
      <ContractTemplateForm
        realm="customer"
        previewValues={{ customer_name: 'Honda Philippines' }}
        onInspect={async () => [{ name: 'Buyer Name', type: 'Text' }]}
        onSubmit={onSubmit}
      />,
    );

    fireEvent.change(screen.getByLabelText('PDF template'), { target: { files: [new File(['%PDF-1.4'], 'honda.pdf', { type: 'application/pdf' })] } });
    fireEvent.click(screen.getByRole('button', { name: 'Read PDF fields' }));
    await screen.findByRole('combobox', { name: 'Map “Buyer Name” to' });
    fireEvent.change(screen.getByRole('textbox', { name: 'Template name' }), { target: { value: 'Honda supply terms' } });
    expect(screen.getByRole('button', { name: 'Save contract template' })).toBeDisabled();
    expect(screen.getByRole('note')).toHaveTextContent(/map at least one pdf field/i);
    fireEvent.change(screen.getByRole('combobox', { name: 'Map “Buyer Name” to' }), { target: { value: 'customer_name' } });

    expect(screen.getByText(/Honda Philippines/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Save contract template' })).toBeEnabled();
    fireEvent.submit(screen.getByRole('button', { name: 'Save contract template' }).closest('form')!);
    await waitFor(() => expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({
      name: 'Honda supply terms',
      realm: 'customer',
      file: expect.any(File),
      field_mappings: { 'Buyer Name': 'customer_name' },
    })));
  });

  it('allows an ordinary PDF with no fillable fields to be attached without auto-fill mapping', async () => {
    render(<ContractTemplateForm realm="supplier" onInspect={async () => []} onSubmit={vi.fn()} />);

    fireEvent.change(screen.getByLabelText('PDF template'), { target: { files: [new File(['%PDF-1.4'], 'terms.pdf', { type: 'application/pdf' })] } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Template name' }), { target: { value: 'Plain terms PDF' } });
    fireEvent.click(screen.getByRole('button', { name: 'Read PDF fields' }));

    expect(await screen.findByText(/no fillable fields were found/i)).toBeTruthy();
    expect(screen.getByText(/attach this pdf as-is/i)).toBeTruthy();
    expect(screen.queryByRole('combobox')).toBeNull();
    expect(screen.getByRole('button', { name: 'Save contract template' })).toBeDisabled();
  });
});
