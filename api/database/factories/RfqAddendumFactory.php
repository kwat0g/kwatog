<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RfqAddendum;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RfqAddendum> */
class RfqAddendumFactory extends Factory
{
    protected $model = RfqAddendum::class;
    public function definition(): array
    {
        return ['request_for_quote_id' => RequestForQuote::factory(), 'published_by' => User::factory(), 'sequence' => 1, 'title' => 'Clarification', 'body' => 'Clarification to the sourcing requirement.', 'published_at' => now()];
    }
}
