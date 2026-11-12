import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { pricingRequestsApi } from '@/api/crm/pricingRequests';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { PriceAgreementForm } from './form';
import { useRealtimeRecord } from '@/hooks/useRealtimeRecord';
import { formatPeso, formatQuantity } from '@/lib/formatNumber';

export default function PricingOfferPage() {
  const { id } = useParams();
  useRealtimeRecord('price_request', id);
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
      {query.data && !query.data.available_actions?.can_propose && (
        <EmptyState
          icon="alert-circle"
          title="This pricing request is no longer open for a proposal"
          description="Return to the pricing request list and review its current status."
          action={<Button onClick={() => query.refetch()}>Refresh request</Button>}
        />
      )}
      {query.data && query.data.available_actions?.can_propose && (
        <div className="space-y-4 p-5">
          {query.data.requested_price && (
            <div className="rounded-md border border-default bg-elevated p-4 text-sm">
              <p className="font-medium">
                Customer target: {formatPeso(query.data.requested_price)} per{' '}
                {query.data.product.unit_of_measure}
                {query.data.quantity !== null && query.data.quantity !== undefined && (
                  <> at planned quantity {formatQuantity(query.data.quantity)} {query.data.product.unit_of_measure}</>
                )}
                {' '}· VAT excluded
              </p>
              <p className="mt-1 text-muted">
                You may counteroffer a different unit price. The requested quantity does not set a
                discount threshold: choose tiered pricing and set the qualifying minimum explicitly.
                A flat price applies at every quantity. Finance review and customer acceptance still
                apply.
              </p>
            </div>
          )}
          <PriceAgreementForm
            mode="create"
            negotiationId={query.data.id}
            expectedVersion={query.data.version}
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
        </div>
      )}
    </div>
  );
}
