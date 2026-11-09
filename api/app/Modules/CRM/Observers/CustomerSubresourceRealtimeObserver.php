<?php

declare(strict_types=1);

namespace App\Modules\CRM\Observers;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Services\CustomerRealtimeService;
use App\Modules\CRM\Models\CustomerAgreementDocument;
use App\Modules\CRM\Models\CustomerProductWarrantyOverride;
use Illuminate\Support\Carbon;

final class CustomerSubresourceRealtimeObserver
{
    public function created(CustomerAgreementDocument|CustomerProductWarrantyOverride $record): void
    {
        $this->stageCustomerRefresh($record, $record->created_at);
    }

    public function updated(CustomerAgreementDocument|CustomerProductWarrantyOverride $record): void
    {
        if ($record->wasChanged()) {
            $this->stageCustomerRefresh($record, $record->updated_at);
        }
    }

    public function deleted(CustomerProductWarrantyOverride $record): void
    {
        $this->stageCustomerRefresh($record, now());
    }

    private function stageCustomerRefresh(CustomerAgreementDocument|CustomerProductWarrantyOverride $record, ?Carbon $changedAt): void
    {
        $customer = $record instanceof CustomerProductWarrantyOverride
            ? $record->customer
            : Customer::query()->find($record->customer_id);

        if (! $customer instanceof Customer) {
            return;
        }

        $version = $changedAt?->format('Y-m-d\TH:i:s.uP') ?? now()->format('Y-m-d\TH:i:s.uP');
        app(CustomerRealtimeService::class)->stage(
            $customer,
            'updated',
            $version,
            includeCustomerProfile: false,
        );
    }
}
