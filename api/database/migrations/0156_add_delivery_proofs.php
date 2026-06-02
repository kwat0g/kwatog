<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADV7 — Proof of Delivery.
 *
 *  - Add receiver capture fields directly to deliveries.
 *  - New delivery_proofs table for one-to-many proof attachments
 *    (signed DRs, photos, customer PO confirmations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->string('receiver_name', 200)->nullable()->after('receipt_photo_path');
            $table->string('receiver_position', 100)->nullable()->after('receiver_name');
            $table->timestamp('received_at')->nullable()->after('receiver_position');
            $table->text('delivery_remarks')->nullable()->after('received_at');
        });

        Schema::create('delivery_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->string('proof_type', 30); // signed_dr, photo, customer_po_confirmation, other
            $table->string('file_name', 200);
            $table->string('file_path', 500);
            $table->unsignedInteger('file_size')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('delivery_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_proofs');

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['receiver_name', 'receiver_position', 'received_at', 'delivery_remarks']);
        });
    }
};
