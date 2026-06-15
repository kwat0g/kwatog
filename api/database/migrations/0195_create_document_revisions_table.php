<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')
                ->constrained('controlled_documents')->cascadeOnDelete();
            $table->unsignedSmallInteger('revision_number');
            $table->date('effective_date');
            $table->text('change_reason');
            $table->string('file_path', 255);
            $table->string('file_name', 200);
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->unique(['document_id', 'revision_number'], 'uq_doc_rev_doc_num');
            $table->index(['document_id', 'is_current'], 'ix_doc_rev_current');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_revisions');
    }
};
