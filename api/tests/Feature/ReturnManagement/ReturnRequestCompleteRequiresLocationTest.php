<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ReturnRequestCompleteRequiresLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_throws_when_location_missing(): void
    {
        $by  = User::factory()->create();

        $customer = Customer::create([
            'name'                => 'Test Customer',
            'payment_terms_days'  => 30,
        ]);

        $rma = ReturnRequest::create([
            'rma_number'  => 'RMA-T-'.substr(uniqid(), -6),
            'type'        => ReturnRequestType::CustomerReturn->value,
            'status'      => ReturnRequestStatus::Inspected->value,
            'customer_id' => $customer->id,
            'reason_code' => 'defective',
            'return_date' => now()->toDateString(),
            'created_by'  => $by->id,
        ]);

        $svc = app(ReturnRequestService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A warehouse location is required to complete a return.');

        $svc->complete($rma, $by, null);
    }
}


