import { PageHeader } from '@/components/layout/PageHeader';
import { PriceAgreementForm } from './form';

export default function CreatePriceAgreementPage() {
  return (
    <div>
      <PageHeader
        title="New price agreement"
        backTo="/crm/price-agreements"
        backLabel="Price agreements"
        breadcrumbs={[
          { label: 'CRM' },
          { label: 'Price agreements', href: '/crm/price-agreements' },
          { label: 'New price agreement' },
        ]}
      />
      <PriceAgreementForm mode="create" />
    </div>
  );
}
