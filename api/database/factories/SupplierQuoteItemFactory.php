<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Purchasing\Models\RequestForQuoteItem;
use App\Modules\Purchasing\Models\SupplierQuote;
use App\Modules\Purchasing\Models\SupplierQuoteItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplierQuoteItem> */
class SupplierQuoteItemFactory extends Factory
{
    protected $model = SupplierQuoteItem::class;
    public function definition(): array
    {
        return ['supplier_quote_id' => SupplierQuote::factory(), 'request_for_quote_item_id' => RequestForQuoteItem::factory(), 'offered_quantity' => '100.0000', 'unit_price' => '100.0000', 'line_total_delivered_cost' => '10000.00'];
    }
}
