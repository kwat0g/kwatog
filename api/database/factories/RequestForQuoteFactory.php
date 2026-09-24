<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\RequestForQuote;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RequestForQuote> */
class RequestForQuoteFactory extends Factory
{
    protected $model = RequestForQuote::class;
    public function definition(): array
    {
        return [
            'rfq_number' => 'RFQ-'.now()->format('Ym').'-'.fake()->unique()->numerify('####'),
            'purchase_request_id' => PurchaseRequest::factory(), 'created_by' => User::factory(),
            'title' => 'Production resin sourcing',
            'closes_at' => now()->addDays(7),
        ];
    }
    public function configure(): static
    {
        return $this->afterMaking(fn (RequestForQuote $rfq) => $rfq->forceFill(['status' => 'draft']));
    }
}
