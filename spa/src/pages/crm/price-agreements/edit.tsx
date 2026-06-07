import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { priceAgreementsApi } from '@/api/crm/priceAgreements';
import { PriceAgreementForm } from './form';

export default function EditPriceAgreementPage() {
  const { id } = useParams<{ id: string }>();
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['crm', 'price-agreements', 'detail', id],
    queryFn: () => priceAgreementsApi.show(id as string),
    enabled: !!id,
  });

  return (
    <div>
      <PageHeader
        title="Edit price agreement"
        backTo="/crm/price-agreements"
        backLabel="Price agreements"
        breadcrumbs={[
          { label: 'CRM' },
          { label: 'Price agreements', href: '/crm/price-agreements' },
          { label: 'Edit' },
        ]}
      />
      {isLoading && <SkeletonForm />}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load price agreement"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}
      {data && <PriceAgreementForm mode="edit" initial={data} />}
    </div>
  );
}
