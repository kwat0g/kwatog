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
        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('file_path', 500);
            $table->string('filename', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('alt_text', 255)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();
            $table->index(['product_id', 'position']);
            $table->index(['product_id', 'is_primary']);
        });

        Schema::create('product_blueprints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('revision', 40);
            $table->text('notes')->nullable();
            $table->string('file_path', 500);
            $table->string('filename', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['product_id', 'title', 'revision']);
            $table->index(['product_id', 'created_at']);
        });

        if (Schema::hasColumn('products', 'image_path')) {
            DB::table('products')->whereNotNull('image_path')->orderBy('id')->each(function (object $product): void {
                $extension = strtolower(pathinfo((string) $product->image_path, PATHINFO_EXTENSION));
                $mime = match ($extension) {
                    'png' => 'image/png',
                    'webp' => 'image/webp',
                    'jpg', 'jpeg' => 'image/jpeg',
                    default => 'application/octet-stream',
                };
                DB::table('product_images')->insert([
                    'product_id' => $product->id,
                    'file_path' => $product->image_path,
                    'filename' => basename((string) $product->image_path),
                    'mime' => $mime,
                    'size' => 0,
                    'position' => 0,
                    'alt_text' => null,
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_blueprints');
        Schema::dropIfExists('product_images');
    }
};
