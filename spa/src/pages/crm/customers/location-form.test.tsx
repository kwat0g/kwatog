import { FormProvider, useForm } from 'react-hook-form';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { CustomerForm, customerCreateResolver, type CustomerFormInput, type CustomerFormValues } from './form';

function CustomerFormHarness({ withAddress = false }: { withAddress?: boolean }) {
  const form = useForm<CustomerFormInput, unknown, CustomerFormValues>({
    resolver: customerCreateResolver,
    defaultValues: {
      name: 'Honda Demo',
      contact_person: 'Mika Sato',
      contact_person_role: 'Sales Officer',
      email: 'mika@example.test',
      phone: '',
      address: '',
      delivery_address: withAddress ? 'Honda Plant, Santa Rosa' : '',
      delivery_contact_person: '',
      delivery_contact_phone: '',
      delivery_latitude: withAddress ? '' : '14.2860000',
      delivery_longitude: withAddress ? '' : '120.9345000',
      credit_limit: undefined,
      payment_terms_days: 30,
      allowed_payment_methods: [],
      shipping_payer: '',
      agreement_starts_on: '',
      agreement_ends_on: '',
      warranty_duration_days: 0,
      warranty_report_window_days: 0,
      warranty_start_basis: '',
      warranty_covered_issues: [],
      warranty_remedies: [],
      is_active: true,
    },
  });

  return (
    <FormProvider {...form}>
      <form onSubmit={form.handleSubmit(() => undefined)}>
        <CustomerForm requireWarrantyDefaults />
        <button type="submit">Save customer</button>
      </form>
    </FormProvider>
  );
}

describe('CRM customer delivery location form', () => {
  it('captures the contact person role and keeps saved map coordinates visible while editing', () => {
    render(<CustomerFormHarness />);

    expect(screen.getByRole('textbox', { name: /contact role/i })).toHaveProperty('value', 'Sales Officer');
    expect(screen.getByRole('region', { name: /delivery location map/i })).toBeTruthy();
    expect(screen.getByRole('textbox', { name: /delivery latitude/i })).toHaveProperty('value', '14.2860000');
    expect(screen.getByRole('textbox', { name: /delivery longitude/i })).toHaveProperty('value', '120.9345000');
  });

  it('requires a map pin when a delivery address is entered', async () => {
    render(<CustomerFormHarness withAddress />);

    fireEvent.click(screen.getByRole('button', { name: 'Save customer' }));

    expect(await screen.findByText('Pin the delivery address on the map.')).toBeTruthy();
  });
});
