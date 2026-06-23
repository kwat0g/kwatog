<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Modules\SupplyChain\Models\Container;
use App\Modules\SupplyChain\Models\Shipment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** Basic CRUD for shipment containers. */
class ContainerService
{
    public function listByShipment(Shipment $shipment, array $filters = []): LengthAwarePaginator
    {
        $q = Container::query()->where('shipment_id', $shipment->id);

        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $q->where(fn (Builder $b) => $b
                ->where('container_number', 'ilike', $term)
                ->orWhere('seal_number', 'ilike', $term));
        }

        return $q->orderBy('id')->paginate(min((int) ($filters['per_page'] ?? 50), 100));
    }

    public function show(Container $container): Container
    {
        return $container->load('shipment');
    }

    public function create(Shipment $shipment, array $data): Container
    {
        $data['shipment_id'] = $shipment->id;
        return Container::create($data);
    }

    public function update(Container $container, array $data): Container
    {
        $container->update($data);
        return $container;
    }

    public function delete(Container $container): void
    {
        $container->delete();
    }
}
