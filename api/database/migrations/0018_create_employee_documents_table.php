<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('document_type', 50);
            $table->string('file_name', 200);
            $table->string('file_path', 500);
            $table->timestamp('uploaded_at');
            $table->timestamp('created_at')->nullable();

            $table->index('employee_id');
            $table->index('document_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
