import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { pricingRequestsApi } from '@/api/crm/pricingRequests';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { PriceAgreementForm } from './form';

export default function PricingOfferPage() {
  const { id } = useParams();
  const query = useQuery({
    queryKey: ['pricing-requests', id],
    queryFn: () => pricingRequestsApi.show(id!),
    enabled: !!id,
  });
  return (
    <div>
      <PageHeader
        title="Propose pricing terms"
        backTo="/crm/price-agreements/requests"
        backLabel="Pricing requests"
      />
      {query.isLoading && <SkeletonForm />}
      {query.isError && (
        <EmptyState
          icon="alert-circle"
          title="Cannot load request"
          action={<Button onClick={() => query.refetch()}>Retry</Button>}
        />
      )}
      {query.data && (
        <PriceAgreementForm
          mode="create"
          negotiationId={query.data.id}
          initial={{
            product: query.data.product,
            customer: query.data.customer,
            price: query.data.offer?.price ?? query.data.requested_price ?? '',
            pricing_method: query.data.offer?.pricing_method ?? 'flat',
            tiers: query.data.offer?.tiers ?? null,
            effective_from: query.data.offer?.effective_from ?? '',
            effective_to: query.data.offer?.effective_to ?? '',
          }}
        />
      )}
    </div>
  );
}
