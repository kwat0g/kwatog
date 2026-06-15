<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_records', function (Blueprint $table) {
            $table->timestamp('auto_resolved_at')->nullable()->after('escalated_to_user_id');
            $table->index(['action', 'auto_resolved_at'], 'approvals_auto_resolved_idx');
        });
    }

    public function down(): void
    {
        Schema::table('approval_records', function (Blueprint $table) {
            $table->dropIndex('approvals_auto_resolved_idx');
            $table->dropColumn('auto_resolved_at');
        });
    }
};
