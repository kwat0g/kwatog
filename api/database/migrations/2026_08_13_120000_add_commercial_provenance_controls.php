<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'accounting.invoices.prebill_approve' => 'Approve Prebill Invoices',
        'accounting.bills.exception_approve' => 'Approve Service Bill Exceptions',
    ];

    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->decimal('quantity_accepted', 12, 3)->default(0)->after('quantity_received');
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('lifecycle_type', 20)->default('standard')->after('delivery_id');
            $table->foreignId('prebill_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prebill_approved_at')->nullable();
            $table->text('prebill_reason')->nullable();
        });
        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->foreignId('source_delivery_item_id')->nullable()->after('product_id')->constrained('delivery_items')->restrictOnDelete();
        });
        Schema::table('bills', function (Blueprint $table): void {
            $table->string('provenance_type', 20)->default('stock')->after('goods_receipt_note_id');
            $table->text('exception_evidence')->nullable();
            $table->foreignId('exception_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('exception_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('exception_approved_at')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_lifecycle_type_check CHECK (lifecycle_type IN ('standard','prebill'))");
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_prebill_evidence_check CHECK (lifecycle_type <> 'prebill' OR (prebill_approved_by IS NOT NULL AND prebill_approved_at IS NOT NULL AND prebill_reason IS NOT NULL AND btrim(prebill_reason) <> ''))");
            DB::statement("ALTER TABLE bills ADD CONSTRAINT bills_provenance_type_check CHECK (provenance_type IN ('stock','service'))");
            DB::statement("ALTER TABLE bills ADD CONSTRAINT bills_service_evidence_check CHECK (provenance_type <> 'service' OR (exception_evidence IS NOT NULL AND btrim(exception_evidence) <> '' AND exception_owner_id IS NOT NULL AND exception_approved_by IS NOT NULL AND exception_approved_at IS NOT NULL))");
        }

        foreach (self::PERMISSIONS as $slug => $name) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $slug],
                ['name' => $name, 'module' => 'accounting', 'updated_at' => now(), 'created_at' => now()],
            );
            $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');
            $roleId = DB::table('roles')->where('slug', 'finance_officer')->value('id');
            if ($permissionId && $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE bills DROP CONSTRAINT IF EXISTS bills_service_evidence_check');
            DB::statement('ALTER TABLE bills DROP CONSTRAINT IF EXISTS bills_provenance_type_check');
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_prebill_evidence_check');
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_lifecycle_type_check');
        }
        DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->delete();
        Schema::table('bills', function (Blueprint $table): void {
            $table->dropForeign(['exception_approved_by']);
            $table->dropForeign(['exception_owner_id']);
            $table->dropColumn(['provenance_type', 'exception_evidence', 'exception_owner_id', 'exception_approved_by', 'exception_approved_at']);
        });
        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->dropForeign(['source_delivery_item_id']);
            $table->dropColumn('source_delivery_item_id');
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign(['prebill_approved_by']);
            $table->dropColumn(['lifecycle_type', 'prebill_approved_by', 'prebill_approved_at', 'prebill_reason']);
        });
        Schema::table('purchase_order_items', fn (Blueprint $table) => $table->dropColumn('quantity_accepted'));
    }
};
