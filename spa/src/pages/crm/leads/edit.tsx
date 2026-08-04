import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { leadsApi } from '@/api/crm/leads';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { LeadForm } from './form';

export default function EditLeadPage() {
 const { id } = useParams<{ id: string }>();
 const { data, isLoading, isError } = useQuery({
 queryKey: ['crm', 'leads', 'detail', id],
 queryFn: () => leadsApi.show(id!),
 enabled: !!id,
 });

 if (isLoading) return <div><PageHeader title="Edit lead" backTo="/crm/leads" backLabel="Leads"
 breadcrumbs={[{ label: 'CRM', href: '/crm' }, { label: 'Leads', href: '/crm/leads' }, { label: 'Loading…' }]} /><SkeletonDetail /></div>;

 return (
 <div>
 <PageHeader title={`Edit ${data?.lead_number ?? 'lead'}`} backTo={`/crm/leads/${id}`} backLabel="Lead"
 breadcrumbs={[
 { label: 'CRM' },
 { label: 'Leads', href: '/crm/leads' },
 { label: data?.lead_number ?? 'Edit' },
 ]} />
 {isError || !data ? (
 <div className="px-5 py-4 text-sm text-muted">Could not load lead.</div>
 ) : (
 <LeadForm initial={data} mode="edit" />
 )}
 </div>
 );
}
