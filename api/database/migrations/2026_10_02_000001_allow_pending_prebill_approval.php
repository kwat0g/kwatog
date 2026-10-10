<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_prebill_evidence_check');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_prebill_evidence_check CHECK (
            lifecycle_type <> 'prebill' OR (
                prebill_reason IS NOT NULL AND btrim(prebill_reason) <> '' AND (
                    (prebill_approved_by IS NULL AND prebill_approved_at IS NULL AND status IN ('draft', 'cancelled'))
                    OR (prebill_approved_by IS NOT NULL AND prebill_approved_at IS NOT NULL)
                )
            )
        )");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $hasPendingPrebills = DB::table('invoices')
            ->where('lifecycle_type', 'prebill')
            ->where(function ($query): void {
                $query->whereNull('prebill_approved_by')->orWhereNull('prebill_approved_at');
            })
            ->exists();
        if ($hasPendingPrebills) {
            throw new RuntimeException('Cannot roll back pending prebill invoices; approve or cancel them first.');
        }

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_prebill_evidence_check');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_prebill_evidence_check CHECK (
            lifecycle_type <> 'prebill' OR (
                prebill_approved_by IS NOT NULL
                AND prebill_approved_at IS NOT NULL
                AND prebill_reason IS NOT NULL
                AND btrim(prebill_reason) <> ''
            )
        )");
    }
};
