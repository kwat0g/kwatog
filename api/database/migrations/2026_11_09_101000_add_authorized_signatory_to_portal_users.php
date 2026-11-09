<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_portal_users', function (Blueprint $table): void {
            $table->boolean('is_authorized_signatory')->default(false)->after('is_active');
        });

        Schema::table('supplier_portal_users', function (Blueprint $table): void {
            $table->boolean('is_authorized_signatory')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('customer_portal_users', function (Blueprint $table): void {
            $table->dropColumn('is_authorized_signatory');
        });

        Schema::table('supplier_portal_users', function (Blueprint $table): void {
            $table->dropColumn('is_authorized_signatory');
        });
    }
};
