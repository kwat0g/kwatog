<?php

declare(strict_types=1);

namespace App\Modules\CRM\Observers;

use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Services\PriceAgreementRealtimeService;

final class PriceAgreementRealtimeObserver
{
    public function created(PriceAgreement $agreement): void
    {
        $this->stage($agreement, 'created');
    }

    public function updated(PriceAgreement $agreement): void
    {
        $this->stage($agreement, 'updated');
    }

    public function deleted(PriceAgreement $agreement): void
    {
        $this->stage($agreement, 'deleted');
    }

    public function restored(PriceAgreement $agreement): void
    {
        $this->stage($agreement, 'restored');
    }

    private function stage(PriceAgreement $agreement, string $action): void
    {
        app(PriceAgreementRealtimeService::class)->stage($agreement, $action);
    }
}
