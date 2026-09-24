<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_requests', 'required_delivery_date')) {
                $table->date('required_delivery_date')->nullable()->after('date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('purchase_requests', 'required_delivery_date')) {
                $table->dropColumn('required_delivery_date');
            }
        });
    }
};
