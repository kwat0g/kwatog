import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { productsApi } from '@/api/crm/products';
import { ProductForm } from './form';

export default function EditProductPage() {
  const { id } = useParams<{ id: string }>();
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['crm', 'products', 'detail', id],
    queryFn: () => productsApi.show(id!),
    enabled: !!id,
  });

  return (
    <div>
      <PageHeader
        title={data ? `Edit ${data.part_number}` : 'Edit product'}
        backTo={data ? `/crm/products/${data.id}` : '/crm/products'}
        backLabel={data?.part_number ?? 'Products'}
      />
      {isLoading && <SkeletonForm />}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load product"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}
      {data && <ProductForm mode="edit" initial={data} />}
    </div>
  );
}
