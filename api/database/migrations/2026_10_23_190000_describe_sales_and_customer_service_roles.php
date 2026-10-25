<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DESCRIPTIONS = [
        'customer_service_officer' => [
            ['Manages customer relationships, inquiries, complaints, returns, and customer records.'],
            'Owns customer records, inquiries, complaints and return cases; reads product, price and sales-order records to support customers. Does not assign products to customers, set selling prices, propose price offers or create sales orders.',
            'Manages customer relationships, inquiries, complaints, returns, and customer records.',
        ],
        'sales_officer' => [
            [
                'Creates, negotiates, and confirms sales orders; does not manage customer service records.',
                'Owns customer product access, pricing proposals, and sales orders; does not manage customer service records.',
            ],
            'Owns customer product access, selling prices, customer-specific price proposals and sales orders. Reads customer records; does not manage inquiries, complaints or return cases. Finance approves customer price offers.',
            'Owns customer product access, pricing proposals, and sales orders; does not manage customer service records.',
        ],
    ];

    public function up(): void
    {
        foreach (self::DESCRIPTIONS as $slug => [$previous, $current]) {
            DB::table('roles')->where('slug', $slug)->whereIn('description', $previous)
                ->update(['description' => $current]);
        }
    }

    public function down(): void
    {
        foreach (self::DESCRIPTIONS as $slug => [$previous, $current, $rollback]) {
            DB::table('roles')->where('slug', $slug)->where('description', $current)
                ->update(['description' => $rollback]);
        }
    }
};
