<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\SupplyChain\Models\Container;
use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Enums\ShipmentStatus;
use App\Common\Support\TrashedFilter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Basic CRUD for shipment containers. */
class ContainerService
{
    public function listByShipment(Shipment $shipment, array $filters = []): LengthAwarePaginator
    {
        $q = Container::query()->where('shipment_id', $shipment->id);

        TrashedFilter::apply($q, $filters);

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
        return DB::transaction(function () use ($shipment, $data): Container {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            $this->assertMutable($locked);
            $data['shipment_id'] = $locked->id;
            return Container::create($data);
        });
    }

    public function update(Container $container, array $data): Container
    {
        return DB::transaction(function () use ($container, $data): Container {
            $locked = Container::query()->lockForUpdate()->findOrFail($container->id);
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($locked->shipment_id);
            $this->assertMutable($shipment);
            $locked->update($data);
            return $locked->fresh('shipment');
        });
    }

    public function delete(Container $container): void
    {
        DB::transaction(function () use ($container): void {
            $locked = Container::query()->lockForUpdate()->findOrFail($container->id);
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($locked->shipment_id);
            $this->assertMutable($shipment);
            $locked->delete();
        });
    }

    public function restore(Container $container): Container
    {
        return DB::transaction(function () use ($container): Container {
            $locked = Container::withTrashed()->lockForUpdate()->findOrFail($container->id);
            $shipment = Shipment::withTrashed()->lockForUpdate()->findOrFail($locked->shipment_id);
            $this->assertMutable($shipment);
            $locked->restore();
            return $locked->fresh('shipment');
        });
    }

    private function assertMutable(Shipment $shipment): void
    {
        $status = $shipment->status instanceof ShipmentStatus
            ? $shipment->status
            : ShipmentStatus::from((string) $shipment->status);
        if (in_array($status, [ShipmentStatus::Cleared, ShipmentStatus::Received, ShipmentStatus::Cancelled], true)) {
            throw new BusinessRuleException('Shipment containers are frozen after customs clearance.');
        }
    }
}
