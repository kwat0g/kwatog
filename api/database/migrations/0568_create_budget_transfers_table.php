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
        Schema::create('budget_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_number', 20)->unique();
            $table->foreignId('from_line_item_id')->constrained('budget_line_items')->cascadeOnDelete();
            $table->foreignId('to_line_item_id')->constrained('budget_line_items')->cascadeOnDelete();
            // Monthly bucket the amount moves in. annual_total is a stored
            // generated column, so allocation only moves through jan..dec.
            $table->string('month', 3);
            $table->decimal('amount', 15, 2);
            $table->text('reason');
            // pending / approved / rejected
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        $raw = DB::table('settings')->where('key', 'documents.sequence_config')->value('value');
        if ($raw !== null) {
            $config = json_decode((string) $raw, true) ?? [];
            $config['budget_transfer'] = ['prefix' => 'BT', 'reset' => 'monthly', 'pad' => 4];
            DB::table('settings')->where('key', 'documents.sequence_config')
                ->update(['value' => json_encode($config), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_transfers');

        $raw = DB::table('settings')->where('key', 'documents.sequence_config')->value('value');
        if ($raw !== null) {
            $config = json_decode((string) $raw, true) ?? [];
            unset($config['budget_transfer']);
            DB::table('settings')->where('key', 'documents.sequence_config')
                ->update(['value' => json_encode($config), 'updated_at' => now()]);
        }
    }
};
