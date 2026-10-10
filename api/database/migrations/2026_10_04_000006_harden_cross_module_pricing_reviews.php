<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->jsonb('financial_change_request')->nullable());
        Schema::table('item_cost_reviews', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
            $table->jsonb('history')->default('[]');
        });
        foreach (['crm.price_agreements.view', 'crm.products.view'] as $slug) {
            $id = DB::table('permissions')->where('slug', $slug)->value('id');
            foreach (DB::table('roles')->where('slug', 'finance_officer')->pluck('id') as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $id]);
                Cache::forget("auth:role_perms:{$roleId}");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('products')->whereNotNull('financial_change_request')->exists()
            || DB::table('item_cost_reviews')->where('version', '>', 1)->exists()) {
            throw new RuntimeException('Keep review histories and financial proposals before rolling back this migration.');
        }
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('financial_change_request'));
        Schema::table('item_cost_reviews', fn (Blueprint $table) => $table->dropColumn(['version', 'history']));
        // Preserve existing and customized read permissions.
    }
};
