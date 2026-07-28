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
        Schema::table('inspections', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->foreignId('product_id')->nullable()->change();
            $table->foreignId('item_id')->nullable()->after('product_id')
                ->constrained('items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Raw-material inspections cannot be represented by the legacy schema.
        DB::table('inspections')->whereNotNull('item_id')->delete();

        Schema::table('inspections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_id');
            $table->foreignId('product_id')->nullable(false)->change();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }
};
