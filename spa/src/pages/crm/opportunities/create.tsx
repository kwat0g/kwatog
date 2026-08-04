import { PageHeader } from '@/components/layout/PageHeader';
import { OpportunityForm } from './form';

export default function CreateOpportunityPage() {
 return (
 <div>
 <PageHeader title="New opportunity" backTo="/crm/opportunities" backLabel="Opportunities"
 breadcrumbs={[
 { label: 'CRM' },
 { label: 'Opportunities', href: '/crm/opportunities' },
 { label: 'New opportunity' },
 ]} />
 <OpportunityForm mode="create" />
 </div>
 );
}
