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
        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->boolean('verification_required')->default(false);
            $table->text('diagnosis')->nullable();
            $table->text('repair_action')->nullable();
            $table->text('functional_test_result')->nullable();
            $table->foreignId('repair_submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('repair_submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
        });

        DB::table('settings')->insertOrIgnore([
            'key' => 'maintenance.closed_loop.enabled',
            'value' => 'false',
            'group' => 'maintenance',
            'label' => 'Require independent maintenance verification',
            'description' => 'Apply independent repair verification to newly created maintenance orders.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'maintenance.closed_loop.enabled')->delete();
        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropConstrainedForeignId('repair_submitted_by');
            $table->dropColumn([
                'verification_required', 'diagnosis', 'repair_action', 'functional_test_result',
                'repair_submitted_at', 'verified_at',
            ]);
        });
    }
};
