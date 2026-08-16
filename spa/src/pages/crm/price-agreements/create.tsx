import { PageHeader } from '@/components/layout/PageHeader';
import { PriceAgreementForm } from './form';

export default function CreatePriceAgreementPage() {
 return (
 <div>
 <PageHeader
 title="New price agreement"
 backTo="/crm/price-agreements"
 backLabel="Price agreements"
 />
 <PriceAgreementForm mode="create" />
 </div>
 );
}
