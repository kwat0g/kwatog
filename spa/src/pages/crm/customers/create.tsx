import { PageHeader } from '@/components/layout/PageHeader';
import { CustomerForm } from './form';

export default function CrmCustomerCreatePage() {
  return (
    <div>
      <PageHeader
        title="New customer"
        backTo="/crm/customers"
        backLabel="Customers"
        breadcrumbs={[
          { label: 'CRM' },
          { label: 'Customers', href: '/crm/customers' },
          { label: 'New customer' },
        ]}
      />
      <CustomerForm mode="create" />
    </div>
  );
}
