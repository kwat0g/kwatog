import { TabNavigation } from '@/components/ui/TabNavigation';
import { usePermission } from '@/hooks/usePermission';

export function PricingNavigation({
  current,
}: {
  current: 'agreements' | 'requests' | 'cost-reviews';
}) {
  const { can, canAny } = usePermission();
  return (
    <TabNavigation
      defaultKey={current}
      tabs={[
        ...(can('crm.price_agreements.view')
          ? [{ key: 'agreements', label: 'Price agreements', to: '/crm/price-agreements' }]
          : []),
        ...(canAny(
          'crm.price_agreements.view',
          'crm.price_agreements.propose',
          'crm.price_agreements.approve',
        )
          ? [{ key: 'requests', label: 'Pricing requests', to: '/crm/price-agreements/requests' }]
          : []),
        ...(can('inventory.costs.review')
          ? [
              {
                key: 'cost-reviews',
                label: 'Supplier cost reviews',
                to: '/crm/price-agreements/cost-reviews',
              },
            ]
          : []),
      ]}
    />
  );
}
