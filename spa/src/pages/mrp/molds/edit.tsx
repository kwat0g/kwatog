import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { moldsApi } from '@/api/mrp/molds';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { MoldForm } from './form';

export default function EditMoldPage() {
 const { id } = useParams<{ id: string }>();
 const { data, isLoading, isError } = useQuery({
 queryKey: ['mrp', 'molds', 'detail', id],
 queryFn: () => moldsApi.show(id!),
 enabled: !!id,
 });

 if (isLoading) return <div><PageHeader title="Edit mold" backTo="/mrp/molds" backLabel="Molds"
 /><SkeletonDetail /></div>;

 return (
 <div>
 <PageHeader title={`Edit ${data?.mold_code ?? 'mold'}`} backTo={`/mrp/molds/${id}`} backLabel="Mold"
 />
 {isError || !data ? (
 <div className="px-5 py-4 text-sm text-muted">Could not load mold.</div>
 ) : (
 <MoldForm initial={data} mode="edit" />
 )}
 </div>
 );
}
