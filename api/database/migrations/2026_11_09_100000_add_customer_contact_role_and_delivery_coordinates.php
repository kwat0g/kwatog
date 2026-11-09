<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('contact_person_role', 100)->nullable()->after('contact_person');
            $table->decimal('delivery_latitude', 10, 7)->nullable()->after('delivery_address');
            $table->decimal('delivery_longitude', 10, 7)->nullable()->after('delivery_latitude');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['contact_person_role', 'delivery_latitude', 'delivery_longitude']);
        });
    }
};
