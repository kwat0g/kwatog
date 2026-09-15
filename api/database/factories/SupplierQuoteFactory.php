<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RequestForQuoteInvitation;
use App\Modules\Purchasing\Models\SupplierQuote;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplierQuote> */
class SupplierQuoteFactory extends Factory
{
    protected $model = SupplierQuote::class;
    public function definition(): array
    {
        return ['request_for_quote_id' => RequestForQuote::factory(), 'vendor_id' => Vendor::factory(), 'invitation_id' => RequestForQuoteInvitation::factory(), 'version' => 1, 'is_current' => true];
    }
    public function configure(): static
    {
        return $this->afterMaking(fn (SupplierQuote $quote) => $quote->forceFill(['status' => 'draft']));
    }
}
