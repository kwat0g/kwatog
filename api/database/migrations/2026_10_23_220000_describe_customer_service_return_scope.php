<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PREVIOUS_DESCRIPTION = 'Handles customer inquiries, complaints and return cases; reads customer and product records for support. Does not create or change customer master records, access sales orders or view customer price agreements.';

    private const CURRENT_DESCRIPTION = 'Handles customer inquiries, complaints and customer-return cases; reads customer and product records for support. Does not create or change customer master records, access company sales orders, supplier returns or customer price agreements.';

    public function up(): void
    {
        DB::table('roles')->where('slug', 'customer_service_officer')
            ->where('description', self::PREVIOUS_DESCRIPTION)
            ->update(['description' => self::CURRENT_DESCRIPTION]);
    }

    public function down(): void
    {
        DB::table('roles')->where('slug', 'customer_service_officer')
            ->where('description', self::CURRENT_DESCRIPTION)
            ->update(['description' => self::PREVIOUS_DESCRIPTION]);
    }
};
