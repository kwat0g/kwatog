import { PageHeader } from '@/components/layout/PageHeader';
import { PriceAgreementForm } from './form';

export default function CreatePriceAgreementPage() {
 return (
 <div>
 <PageHeader
 title="Propose price agreement"
 subtitle="Set customer pricing with clear limits and delivery-date validity."
 backTo="/crm/price-agreements"
 backLabel="Price agreements"
 />
 <PriceAgreementForm mode="create" />
 </div>
 );
}
