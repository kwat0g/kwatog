import { PageHeader } from '@/components/layout/PageHeader';
import { LeadForm } from './form';

export default function CreateLeadPage() {
 return (
 <div>
 <PageHeader title="New lead" backTo="/crm/leads" backLabel="Leads"
 breadcrumbs={[
 { label: 'CRM' },
 { label: 'Leads', href: '/crm/leads' },
 { label: 'New lead' },
 ]} />
 <LeadForm mode="create" />
 </div>
 );
}
