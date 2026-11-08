<?php

declare(strict_types=1);

namespace App\Modules\CRM\Observers;

use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Services\ProductRealtimeService;

final class ProductRealtimeObserver
{
    public function created(Product $product): void
    {
        $this->stage($product, 'created');
    }

    public function updated(Product $product): void
    {
        $this->stage($product, 'updated');
    }

    public function deleted(Product $product): void
    {
        $this->stage($product, 'deleted');
    }

    public function restored(Product $product): void
    {
        $this->stage($product, 'restored');
    }

    private function stage(Product $product, string $action): void
    {
        app(ProductRealtimeService::class)->stage($product, $action);
    }
}
