<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_contract_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('realm', 24);
            $table->string('file_path');
            $table->string('filename', 255);
            $table->unsignedBigInteger('size');
            $table->json('pdf_fields')->default('[]');
            $table->json('field_mappings')->default('{}');
            $table->json('required_fields')->default('[]');
            $table->unsignedInteger('version')->default(1);
            $table->string('source_sha256', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['realm', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_contract_templates');
    }
};
