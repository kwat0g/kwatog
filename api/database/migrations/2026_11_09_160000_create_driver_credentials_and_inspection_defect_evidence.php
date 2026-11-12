<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('license_number', 100);
            $table->date('expires_at');
            $table->string('document_path', 500)->nullable();
            $table->string('document_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('expires_at');
        });

        Schema::create('inspection_defect_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inspection_id')->constrained('inspections')->cascadeOnDelete();
            $table->foreignId('grn_item_id')->constrained('grn_items')->restrictOnDelete();
            $table->string('category', 50);
            $table->string('other_category', 100)->nullable();
            $table->string('severity', 20);
            $table->decimal('affected_quantity', 12, 3);
            $table->text('notes')->nullable();
            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['inspection_id', 'created_at']);
            $table->index(['grn_item_id', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_defect_evidence');
        Schema::dropIfExists('driver_credentials');
    }
};
