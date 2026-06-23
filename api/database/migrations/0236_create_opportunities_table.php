<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->string('opportunity_number')->unique();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('title');
            $table->string('stage')->default('prospecting');                 // OpportunityStage enum value
            $table->tinyInteger('probability')->default(0);
            $table->decimal('estimated_value', 15, 2)->default(0);
            $table->date('expected_close_date')->nullable();
            $table->date('actual_close_date')->nullable();
            $table->string('lost_reason')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Now that opportunities table exists, add the FK on leads.
        Schema::table('leads', function (Blueprint $table) {
            $table->foreign('converted_to_opportunity_id')
                  ->references('id')->on('opportunities')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['converted_to_opportunity_id']);
        });
        Schema::dropIfExists('opportunities');
    }
};
