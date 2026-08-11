<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table): void {
            $table->string('po_conversion_status', 20)
                ->default('not_started')
                ->after('status');
            $table->text('po_conversion_note')->nullable()->after('po_conversion_status');
            $table->timestamp('po_conversion_at')->nullable()->after('po_conversion_note');
            $table->index('po_conversion_status');
        });

        DB::table('purchase_requests')
            ->where('status', 'converted')
            ->update([
                'po_conversion_status' => 'converted',
                'po_conversion_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table): void {
            $table->dropIndex(['po_conversion_status']);
            $table->dropColumn(['po_conversion_status', 'po_conversion_note', 'po_conversion_at']);
        });
    }
};
