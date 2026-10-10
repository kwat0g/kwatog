<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_payment_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('customer_portal_users')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_at_submission', 15, 2);
            $table->string('sending_bank', 100);
            $table->string('sending_bank_normalized', 100);
            $table->string('reference_number', 50);
            $table->string('reference_normalized', 50);
            $table->date('payment_date');
            $table->string('status', 20)->default('pending');
            $table->string('proof_path');
            $table->string('proof_file_name');
            $table->string('proof_mime', 100);
            $table->char('proof_sha256', 64);
            $table->unsignedBigInteger('proof_size');
            $table->uuid('idempotency_key');
            $table->char('request_fingerprint', 64);
            $table->foreignId('collection_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'idempotency_key'], 'invoice_payment_idempotency');
        });
        DB::statement("CREATE UNIQUE INDEX invoice_payment_pending ON invoice_payment_submissions (invoice_id) WHERE status = 'pending'");
        DB::statement("CREATE UNIQUE INDEX invoice_payment_reference ON invoice_payment_submissions (customer_id, sending_bank_normalized, reference_normalized) WHERE status IN ('pending', 'verified')");
        DB::statement("ALTER TABLE invoice_payment_submissions ADD CONSTRAINT invoice_payment_amount CHECK (amount > 0 AND amount = balance_at_submission), ADD CONSTRAINT invoice_payment_state CHECK ((status = 'pending' AND collection_id IS NULL AND reviewed_by IS NULL AND reviewed_at IS NULL AND rejection_reason IS NULL) OR (status = 'verified' AND collection_id IS NOT NULL AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND rejection_reason IS NULL) OR (status = 'rejected' AND collection_id IS NULL AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND rejection_reason IS NOT NULL AND length(trim(rejection_reason)) > 0))");
        Schema::table('collections', fn (Blueprint $table) => $table->string('manual_reason', 500)->nullable());
        DB::table('permissions')->insertOrIgnore([
            'slug' => 'accounting.invoices.collect_manual', 'name' => 'Record Exceptional Collections',
            'module' => 'accounting', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $permission = DB::table('permissions')->where('slug', 'accounting.invoices.collect_manual')->value('id');
        foreach (DB::table('roles')->where('slug', 'system_admin')->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permission]);
            Cache::forget("auth:role_perms:{$roleId}");
        }
    }

    public function down(): void
    {
        if (DB::table('invoice_payment_submissions')->exists() || DB::table('collections')->whereNotNull('manual_reason')->exists()) {
            throw new RuntimeException('Payment submissions exist. Preserve evidence and use a compatibility rollback.');
        }
        Schema::dropIfExists('invoice_payment_submissions');
        Schema::table('collections', fn (Blueprint $table) => $table->dropColumn('manual_reason'));
        $id = DB::table('permissions')->where('slug', 'accounting.invoices.collect_manual')->value('id');
        foreach (DB::table('role_permissions')->where('permission_id', $id)->pluck('role_id') as $roleId) {
            Cache::forget("auth:role_perms:{$roleId}");
        }
        DB::table('role_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};
