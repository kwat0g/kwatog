<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SystemUserResolver;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderAgreement;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SalesOrderAgreementService
{
    public function publish(SalesOrder $order, User $actor, string $terms, ?UploadedFile $file): SalesOrderAgreement
    {
        $path = null;
        try {
            return DB::transaction(function () use ($order, $actor, $terms, $file, &$path): SalesOrderAgreement {
                $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
                if (! $locked->status->isBeforeConfirmation()) {
                    throw new BusinessRuleException('Terms can only be published before order confirmation.');
                }
                $path = $file?->store('sales-order-agreements', 'local');
                if ($file && ! is_string($path)) throw new \RuntimeException('Could not store the agreement attachment.');
                return $locked->agreements()->create([
                    'revision' => ((int) $locked->agreements()->max('revision')) + 1,
                    'terms' => trim($terms), 'snapshot' => $this->snapshot($locked),
                    'file_path' => $path, 'file_name' => $file?->getClientOriginalName(),
                    'file_mime_type' => $file?->getMimeType(), 'published_by' => $actor->id,
                    'published_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            if ($path !== null) Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function acknowledgePortal(SalesOrder $order, SalesOrderAgreement $agreement, CustomerPortalUser $actor): SalesOrderAgreement
    {
        return app(SystemUserResolver::class)->impersonate(fn () => DB::transaction(function () use ($order, $agreement, $actor): SalesOrderAgreement {
            $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless((int) $actor->customer_id === (int) $locked->customer_id, 403);
            $row = $locked->agreements()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentAndUnstale($locked, $row);
            if ($row->acknowledgment_method !== null) return $row;
            $row->forceFill(['acknowledgment_method' => 'portal', 'representative' => $actor->name,
                'acknowledged_at' => now(), 'recorded_at' => now(), 'portal_user_id' => $actor->id])->save();
            return $row;
        }, 3));
    }

    public function acknowledgeExternal(SalesOrder $order, SalesOrderAgreement $agreement, User $actor, array $data): SalesOrderAgreement
    {
        return DB::transaction(function () use ($order, $agreement, $actor, $data): SalesOrderAgreement {
            $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            $row = $locked->agreements()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentAndUnstale($locked, $row);
            if ($row->acknowledgment_method !== null) return $row;
            $row->forceFill(['acknowledgment_method' => 'external', 'representative' => trim($data['representative']),
                'acknowledged_at' => $data['acknowledged_at'], 'recorded_at' => now(),
                'reference' => trim($data['reference']), 'recorded_by' => $actor->id])->save();
            return $row;
        }, 3);
    }

    public function assertConfirmable(SalesOrder $order): void
    {
        $latest = $order->agreements()->lockForUpdate()->first();
        if (! $latest) return;
        if ($latest->acknowledgment_method === null || ! $this->isCurrent($order, $latest)) {
            throw new BusinessRuleException('The latest agreed terms must be acknowledged and match current order details before confirmation.');
        }
    }

    public function summary(SalesOrder $order, Request $request, bool $readOnly = false): array
    {
        $rows = $order->relationLoaded('agreements') ? $order->agreements : $order->agreements()->with(['publisher:id,name', 'recorder:id,name'])->get();
        $order->loadMissing('items.product:id,part_number,name');
        $latest = $rows->first();
        $currentSnapshot = $this->snapshot($order);
        $internal = $request->is('api/v1/b2b/customer/*') ? null : auth('sanctum')->user();
        $canView = $internal instanceof User && $internal->hasPermission('crm.sales_orders.view');
        $canPublish = ! $readOnly && $internal instanceof User && $internal->hasPermission('crm.sales_orders.update') && $order->status->isBeforeConfirmation();
        $canExternal = ! $readOnly && $internal instanceof User && $internal->hasPermission('crm.sales_orders.confirm') && $order->status->isBeforeConfirmation();
        $portal = $request->is('api/v1/b2b/customer/*') ? auth('customer_portal')->user() : null;
        $ownsOrder = $portal && (int) $portal->customer_id === (int) $order->customer_id;
        $canAcknowledge = ! $readOnly && $ownsOrder && $order->status->isBeforeConfirmation() && $latest && $latest->acknowledgment_method === null && $this->snapshotsMatch($latest->snapshot, $currentSnapshot);
        return [
            'sales_order_id' => $order->hash_id, 'so_number' => $order->so_number,
            'payment_method' => $order->payment_method,
            'customer_terms_snapshot' => $order->customer_terms_snapshot,
            'warranty_terms' => $order->items->map(fn ($item): array => [
                'product_id' => $item->product?->hash_id ?? app('hashids')->encode($item->product_id),
                'product_label' => trim(($item->product?->part_number ?? '').' '.($item->product?->name ?? '')),
                'terms' => $item->warranty_terms_snapshot,
            ])->values()->all(),
            'can_publish' => (bool) $canPublish, 'can_record_external' => (bool) $canExternal, 'can_acknowledge' => (bool) $canAcknowledge,
            'revisions' => $rows->map(function (SalesOrderAgreement $row) use ($latest, $canView, $ownsOrder, $currentSnapshot, $readOnly): array {
                $current = $row->is($latest); $stale = ! $this->snapshotsMatch($row->snapshot, $currentSnapshot);
                return ['id' => $row->hash_id, 'revision' => (int) $row->revision, 'terms' => $row->terms,
                    'snapshot' => $row->snapshot, 'published_at' => $row->published_at?->toIso8601String(),
                    'published_by' => $row->publisher?->name ?? '', 'file_name' => ($canView || $ownsOrder || $readOnly) ? $row->file_name : null,
                    'acknowledgment' => $row->acknowledgment_method === null ? null : ['method' => $row->acknowledgment_method,
                        'representative' => $row->representative, 'acknowledged_at' => $row->acknowledged_at?->toIso8601String(),
                        'recorded_at' => $row->recorded_at?->toIso8601String(), 'recorded_by' => $row->recorder?->name ?? ($row->acknowledgment_method === 'portal' ? $row->representative : ''), 'reference' => $row->reference],
                    'is_current' => $current, 'is_stale' => $stale];
            })->all(),
        ];
    }

    public function snapshot(SalesOrder $order): array
    {
        $order = SalesOrder::query()->with(['customer', 'items.product' => fn ($query) => $query->withTrashed()])->findOrFail($order->id);
        $items = $order->items->map(fn ($item): array => ['product_id' => $item->product?->hash_id ?? app('hashids')->encode($item->product_id),
            'product_label' => trim(($item->product?->part_number ?? '').' '.($item->product?->name ?? '')), 'quantity' => bcadd((string) $item->quantity, '0', 2),
            'unit_price' => bcadd((string) $item->unit_price, '0', 2), 'delivery_date' => $item->delivery_date?->toDateString()])
            ->sortBy(fn ($item) => implode('|', [$item['product_id'], $item['quantity'], $item['unit_price'], $item['delivery_date'] ?? '']))->values()->all();
        return ['customer_id' => $order->customer?->hash_id ?? app('hashids')->encode($order->customer_id),
            'payment_method' => $order->payment_method,
            'payment_terms_days' => (int) $order->payment_terms_days, 'delivery_terms' => $order->delivery_terms,
            'incoterm' => $order->incoterm?->value, 'subtotal' => bcadd((string) $order->subtotal, '0', 2),
            'vat_amount' => bcadd((string) $order->vat_amount, '0', 2), 'total_amount' => bcadd((string) $order->total_amount, '0', 2), 'items' => $items];
    }

    private function isCurrent(SalesOrder $order, SalesOrderAgreement $agreement): bool
    {
        $saved = $agreement->snapshot;
        $current = $this->snapshot($order);
        return $this->snapshotsMatch($saved, $current);
    }

    private function snapshotsMatch(array $saved, array $current): bool
    {
        if (! array_key_exists('payment_method', $saved) && ($current['payment_method'] ?? null) === null) {
            $saved['payment_method'] = null;
        }
        foreach ($saved['items'] as &$item) unset($item['product_label']);
        foreach ($current['items'] as &$item) unset($item['product_label']);
        return $this->sortObjectKeys($saved) === $this->sortObjectKeys($current);
    }

    private function sortObjectKeys(array $value): array
    {
        foreach ($value as &$child) if (is_array($child)) $child = $this->sortObjectKeys($child);
        unset($child);
        if (! array_is_list($value)) ksort($value);
        return $value;
    }

    private function assertCurrentAndUnstale(SalesOrder $order, SalesOrderAgreement $agreement): void
    {
        if (! $order->status->isBeforeConfirmation() || (int) $order->agreements()->max('revision') !== (int) $agreement->revision || ! $this->isCurrent($order, $agreement)) {
            throw new BusinessRuleException('Only current, unchanged terms can be acknowledged before confirmation.');
        }
    }
}
