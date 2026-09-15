<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RequestForQuoteItem;
use App\Modules\Purchasing\Models\RfqAward;
use App\Modules\Purchasing\Models\SupplierQuote;
use App\Modules\Purchasing\Models\SupplierQuoteItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RfqAward> */
class RfqAwardFactory extends Factory
{
    protected $model = RfqAward::class;
    public function definition(): array
    {
        return ['request_for_quote_id' => RequestForQuote::factory(), 'request_for_quote_item_id' => RequestForQuoteItem::factory(), 'supplier_quote_id' => SupplierQuote::factory(), 'supplier_quote_item_id' => SupplierQuoteItem::factory(), 'vendor_id' => Vendor::factory(), 'awarded_quantity' => '1.0000', 'awarded_unit_price' => '1.0000', 'awarded_total_delivered_cost' => '1.00', 'award_reason' => 'Best compliant offer', 'awarded_by' => User::factory(), 'awarded_at' => now()];
    }
}
