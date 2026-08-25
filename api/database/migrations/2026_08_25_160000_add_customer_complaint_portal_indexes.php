<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_complaints', function (Blueprint $table): void {
            $table->index(
                ['customer_id', 'created_at', 'id'],
                'ix_complaints_portal_customer_created',
            );
            $table->index(
                ['customer_id', 'received_date', 'id'],
                'ix_complaints_portal_customer_received',
            );
        });
    }

    public function down(): void
    {
        Schema::table('customer_complaints', function (Blueprint $table): void {
            $table->dropIndex('ix_complaints_portal_customer_created');
            $table->dropIndex('ix_complaints_portal_customer_received');
        });
    }
};
