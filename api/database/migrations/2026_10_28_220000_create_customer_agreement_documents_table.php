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
        Schema::create('customer_agreement_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('file_path');
            $table->string('filename');
            $table->unsignedBigInteger('size');
            $table->string('mime');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('customer_agreement_documents')->exists()) {
            throw new RuntimeException('Cannot roll back customer agreement documents while records exist. Export or migrate that data first.');
        }

        Schema::dropIfExists('customer_agreement_documents');
    }
};
