<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->string('coc_handoff_status', 20)->default('not_started')->after('invoice_handoff_at');
            $table->text('coc_handoff_message')->nullable()->after('coc_handoff_status');
            $table->timestamp('coc_handoff_at')->nullable()->after('coc_handoff_message');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropColumn(['coc_handoff_status', 'coc_handoff_message', 'coc_handoff_at']);
        });
    }
};
