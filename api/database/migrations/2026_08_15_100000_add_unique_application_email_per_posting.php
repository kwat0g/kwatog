<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'job_applications_posting_email_unique';

    public function up(): void
    {
        // Do not silently discard or merge historical applications. Resolve
        // any existing logical duplicates before this constraint is deployed.
        $duplicates = DB::table('job_applications')
            ->select('job_posting_id')
            ->selectRaw('LOWER(TRIM(email)) AS normalized_email')
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->groupBy('job_posting_id')
            ->groupByRaw('LOWER(TRIM(email))')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $details = $duplicates
                ->map(static fn (object $row): string => sprintf(
                    'posting %d/email %s (%d rows)',
                    $row->job_posting_id,
                    $row->normalized_email,
                    $row->duplicate_count,
                ))
                ->implode(', ');

            throw new RuntimeException(
                'Cannot enforce recruitment application email uniqueness: duplicate logical rows exist for '.$details.'. Resolve the business records before retrying; this migration does not delete or merge applications.'
            );
        }

        // Canonicalize existing rows so the regular unique constraint has the
        // same case-insensitive semantics as new public applications.
        DB::statement('UPDATE job_applications SET email = LOWER(TRIM(email)) WHERE email <> LOWER(TRIM(email))');

        Schema::table('job_applications', function (Blueprint $table): void {
            $table->unique(['job_posting_id', 'email'], self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('job_applications', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX);
        });
    }
};
