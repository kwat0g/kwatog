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
        Schema::create('price_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('requested_by')->nullable()->constrained('customer_portal_users');
            $table->foreignId('proposed_by')->nullable()->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->foreignId('agreement_id')->nullable()->constrained('product_price_agreements');
            $table->unsignedInteger('quantity')->nullable();
            $table->decimal('requested_price', 15, 2)->nullable();
            $table->text('notes');
            $table->string('status', 20)->default('requested');
            $table->unsignedInteger('version')->default(0);
            $table->jsonb('offer')->nullable();
            $table->jsonb('history')->default('[]');
            $table->string('cost_review_signature', 64)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
        });
        DB::statement("CREATE UNIQUE INDEX price_requests_one_open_pair ON price_requests(customer_id, product_id) WHERE status IN ('requested', 'pending_finance', 'offered')");
        Schema::create('item_cost_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('items');
            $table->foreignId('grn_item_id')->unique()->constrained('grn_items');
            $table->decimal('previous_cost', 15, 4);
            $table->decimal('proposed_cost', 15, 4);
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        foreach (['crm.price_agreements.propose' => ['sales_officer', 'customer_service_officer'], 'crm.price_agreements.approve' => ['finance_officer'], 'inventory.costs.review' => ['finance_officer', 'ppc_head', 'purchasing_officer'], 'inventory.costs.approve' => ['finance_officer']] as $slug => $roles) {
            DB::table('permissions')->insertOrIgnore([
                'slug' => $slug,
                'name' => str_replace('.', ' ', $slug),
                'module' => explode('.', $slug)[0],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $id = DB::table('permissions')->where('slug', $slug)->value('id');
            if ($id === null) {
                throw new RuntimeException("Could not resolve permission {$slug} after inserting it.");
            }

            foreach (DB::table('roles')->whereIn('slug', $roles)->pluck('id') as $roleId) {
                if (DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $id]) === 1) {
                    Cache::forget("auth:role_perms:{$roleId}");
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_cost_reviews');
        Schema::dropIfExists('price_requests');

        // Keep permission rows and grants: they may predate this migration or be customized.
    }
};
