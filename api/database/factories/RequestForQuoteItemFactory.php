<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RequestForQuoteItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RequestForQuoteItem> */
class RequestForQuoteItemFactory extends Factory
{
    protected $model = RequestForQuoteItem::class;
    public function definition(): array
    {
        return [
            'request_for_quote_id' => RequestForQuote::factory(), 'purchase_request_item_id' => PurchaseRequestItem::factory(),
            'description' => 'Resin', 'quantity' => '100.0000', 'unit' => 'kg', 'allow_partial_quantity' => true,
        ];
    }
}
