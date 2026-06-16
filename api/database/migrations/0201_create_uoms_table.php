<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OGAMI-004 — Multi-UOM conversion.
 *
 * `uoms` is the canonical catalog of units of measure (KG, BAG, PALLET, …).
 * Item base UOM continues to be expressed as the existing
 * `items.unit_of_measure` string column (matched against `uoms.code`); this
 * table does NOT replace it. It exists so conversion rows can FK both ends of
 * a conversion to a known unit and so the UI can offer a managed dropdown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uoms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 80);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uoms');
    }
};
