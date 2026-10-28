<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_agreements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->text('terms');
            $table->jsonb('snapshot');
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_mime_type')->nullable();
            $table->unsignedBigInteger('published_by');
            $table->timestampTz('published_at');
            $table->string('acknowledgment_method')->nullable();
            $table->string('representative')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('recorded_at')->nullable();
            $table->string('reference', 2000)->nullable();
            $table->unsignedBigInteger('portal_user_id')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestampsTz();
            $table->unique(['sales_order_id', 'revision']);
            $table->foreign('published_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('portal_user_id')->references('id')->on('customer_portal_users')->restrictOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_agreements');
    }
};
