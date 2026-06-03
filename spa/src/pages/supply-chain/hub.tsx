import { useQuery } from '@tanstack/react-query';
import { Truck, Package, Car, RotateCcw } from 'lucide-react';
import { HubPage, HubCard, NavTile, type HubStat } from '@/components/hub';
import { Chip } from '@/components/ui/Chip';
import { Spinner } from '@/components/ui/Spinner';
import { Link } from 'react-router-dom';
import { deliveriesApi, shipmentsApi, vehiclesApi } from '@/api/supply-chain';

export default function SupplyChainHubPage() {
  const { data: deliveries, isLoading: loadingDel } = useQuery({
    queryKey: ['supply-chain', 'deliveries', 'hub'],
    queryFn: () => deliveriesApi.list({ per_page: 5, status: 'in_transit' }),
    refetchInterval: 60_000,
  });

  const { data: shipments, isLoading: loadingShip } = useQuery({
    queryKey: ['supply-chain', 'shipments', 'hub'],
    queryFn: () => shipmentsApi.list({ per_page: 5 }),
    refetchInterval: 60_000,
  });

  const { data: vehicles, isLoading: loadingVeh } = useQuery({
    queryKey: ['supply-chain', 'vehicles', 'hub'],
    queryFn: () => vehiclesApi.list({ per_page: 100 }),
    refetchInterval: 60_000,
  });

  const isLoading = loadingDel || loadingShip || loadingVeh;

  const inTransit = deliveries?.meta?.total ?? 0;
  const deliveredMtd = 0; // Would need dedicated endpoint
  const fleetVehicles = vehicles?.meta?.total ?? 0;
  const totalShipments = shipments?.meta?.total ?? 0;

  const stats: HubStat[] = [
    { label: 'In-Transit', value: inTransit, linkTo: '/supply-chain/deliveries' },
    { label: 'Delivered MTD', value: deliveredMtd },
    { label: 'Fleet Vehicles', value: fleetVehicles, linkTo: '/supply-chain/fleet' },
    { label: 'Shipments', value: totalShipments, linkTo: '/supply-chain/shipments' },
  ];

  return (
    <HubPage title="Supply Chain" subtitle="Logistics, deliveries, fleet management, and returns" breadcrumbs={[{ label: 'Supply Chain' }]} stats={isLoading ? undefined : stats}>
      {isLoading ? (
        <div className="flex justify-center py-12"><Spinner /></div>
      ) : (
        <>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <HubCard title="Active Deliveries" icon={Truck} viewAllHref="/supply-chain/deliveries">
              {!deliveries?.data || deliveries.data.length === 0 ? (
                <p className="text-sm text-muted">No active deliveries.</p>
              ) : (
                <div className="space-y-2">
                  {deliveries.data.slice(0, 5).map((delivery: any) => (
                    <div key={delivery.id} className="flex items-center justify-between text-sm">
                      <Link to={`/supply-chain/deliveries/${delivery.id}`} className="text-accent hover:underline">{delivery.delivery_no || delivery.id}</Link>
                      <Chip variant={delivery.status === 'delivered' ? 'success' : delivery.status === 'in_transit' ? 'info' : 'warning'} >{delivery.status}</Chip>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>

            <HubCard title="Recent Shipments" icon={Package} viewAllHref="/supply-chain/shipments">
              {!shipments?.data || shipments.data.length === 0 ? (
                <p className="text-sm text-muted">No recent shipments.</p>
              ) : (
                <div className="space-y-2">
                  {shipments.data.slice(0, 5).map((shipment: any) => (
                    <div key={shipment.id} className="flex items-center justify-between text-sm">
                      <Link to={`/supply-chain/shipments/${shipment.id}`} className="text-accent hover:underline">{shipment.shipment_no || shipment.id}</Link>
                      <Chip variant={shipment.status === 'received' ? 'success' : shipment.status === 'in_transit' ? 'info' : 'warning'} >{shipment.status}</Chip>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>
          </div>

          <div>
            <h3 className="text-xs font-medium text-muted uppercase tracking-wider mb-3">All Sections</h3>
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
              <NavTile to="/supply-chain/deliveries" icon={Truck} label="Deliveries" description="Outbound customer deliveries" />
              <NavTile to="/supply-chain/shipments" icon={Package} label="Shipments" description="Inbound supplier shipments" />
              <NavTile to="/supply-chain/fleet" icon={Car} label="Fleet" description="Company vehicles" />
              <NavTile to="/supply-chain/returns" icon={RotateCcw} label="Return Management" description="RMA and return requests" />
            </div>
          </div>
        </>
      )}
    </HubPage>
  );
}
