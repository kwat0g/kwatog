<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RequestForQuoteInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RequestForQuoteInvitation> */
class RequestForQuoteInvitationFactory extends Factory
{
    protected $model = RequestForQuoteInvitation::class;
    public function definition(): array
    {
        return ['request_for_quote_id' => RequestForQuote::factory(), 'vendor_id' => Vendor::factory(), 'invited_by' => User::factory(), 'invited_at' => now()];
    }
}
