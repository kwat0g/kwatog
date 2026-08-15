<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['supplier_portal_users', 'customer_portal_users'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                // Existing and seeded accounts remain exempt. New invitations
                // explicitly enable this flag when issuing a temporary password.
                $blueprint->boolean('must_change_password')->default(false)->after('is_active');
            });
        }
    }

    public function down(): void
    {
        foreach (['supplier_portal_users', 'customer_portal_users'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('must_change_password');
            });
        }
    }
};
