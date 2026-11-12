<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE customer_agreement_documents ALTER COLUMN signature_status SET DEFAULT 'draft'");
        DB::table('customer_agreement_documents')
            ->where('signature_status', 'pending')
            ->whereNull('issued_at')
            ->whereNull('signed_at')
            ->update(['signature_status' => 'draft']);
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE customer_agreement_documents ALTER COLUMN signature_status SET DEFAULT 'pending'");
    }
};
