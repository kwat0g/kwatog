import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { crmCustomersApi } from '@/api/crm/customers';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { CustomerForm } from './form';

export default function CrmCustomerEditPage() {
  const { id } = useParams<{ id: string }>();

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['crm', 'customers', 'detail', id],
    queryFn: () => crmCustomersApi.show(id!),
    enabled: !!id,
  });

  return (
    <div>
      <PageHeader
        title={data ? `Edit ${data.name}` : 'Edit customer'}
        backTo={data ? `/crm/customers/${data.id}` : '/crm/customers'}
        backLabel={data?.name ?? 'Customers'}
        breadcrumbs={[
          { label: 'CRM' },
          { label: 'Customers', href: '/crm/customers' },
          { label: data ? `Edit ${data.name}` : 'Edit customer' },
        ]}
      />
      {isLoading && <SkeletonForm />}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load customer"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}
      {data && <CustomerForm mode="edit" initial={data} />}
    </div>
  );
}
