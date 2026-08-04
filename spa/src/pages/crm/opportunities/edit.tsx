import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { opportunitiesApi } from '@/api/crm/opportunities';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { OpportunityForm } from './form';

export default function EditOpportunityPage() {
 const { id } = useParams<{ id: string }>();
 const { data, isLoading, isError } = useQuery({
 queryKey: ['crm', 'opportunities', 'detail', id],
 queryFn: () => opportunitiesApi.show(id!),
 enabled: !!id,
 });

 if (isLoading) return <div><PageHeader title="Edit opportunity" backTo="/crm/opportunities" backLabel="Opportunities"
 breadcrumbs={[{ label: 'CRM', href: '/crm' }, { label: 'Opportunities', href: '/crm/opportunities' }, { label: 'Loading…' }]} /><SkeletonDetail /></div>;

 return (
 <div>
 <PageHeader title={`Edit ${data?.opportunity_number ?? 'opportunity'}`} backTo={`/crm/opportunities/${id}`} backLabel="Opportunity"
 breadcrumbs={[
 { label: 'CRM' },
 { label: 'Opportunities', href: '/crm/opportunities' },
 { label: data?.opportunity_number ?? 'Edit' },
 ]} />
 {isError || !data ? (
 <div className="px-5 py-4 text-sm text-muted">Could not load opportunity.</div>
 ) : (
 <OpportunityForm initial={data} mode="edit" />
 )}
 </div>
 );
}
