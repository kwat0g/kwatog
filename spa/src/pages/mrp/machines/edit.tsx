import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { machinesApi } from '@/api/mrp/machines';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { MachineForm } from './form';

export default function EditMachinePage() {
 const { id } = useParams<{ id: string }>();
 const { data, isLoading, isError } = useQuery({
 queryKey: ['mrp', 'machines', 'detail', id],
 queryFn: () => machinesApi.show(id!),
 enabled: !!id,
 });

 if (isLoading) return <div><PageHeader title="Edit machine" backTo="/mrp/machines" backLabel="Machines"
 /><SkeletonDetail /></div>;

 return (
 <div>
 <PageHeader title={`Edit ${data?.machine_code ?? 'machine'}`} backTo={`/mrp/machines/${id}`} backLabel="Machine"
 />
 {isError || !data ? (
 <div className="px-5 py-4 text-sm text-muted">Could not load machine.</div>
 ) : (
 <MachineForm initial={data} mode="edit" />
 )}
 </div>
 );
}
