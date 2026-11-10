<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_item_listings', function (Blueprint $table): void {
            $table->string('image_path')->nullable();
            $table->string('image_original_filename', 255)->nullable();
            $table->string('image_mime_type', 120)->nullable();
            $table->unsignedBigInteger('image_size_bytes')->nullable();
            $table->char('image_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_item_listings', function (Blueprint $table): void {
            $table->dropColumn([
                'image_path', 'image_original_filename', 'image_mime_type', 'image_size_bytes', 'image_sha256',
            ]);
        });
    }
};
