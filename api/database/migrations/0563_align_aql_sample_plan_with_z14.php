<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * O2C audit 2026-09-25 — align the AQL 0.65 / General Level II plan with
 * ANSI/ASQ Z1.4 Table II-A.
 *
 * At AQL 0.65 the Ac0/Re1 plan sits at code F (n=20). Codes A–E arrow down to
 * F, G arrows up to F, H arrows down to J (n=80, Ac1/Re2). 0409 seeded lots up
 * to 280 at n=32 (code G, stricter than the standard) and lots 281–500 at
 * n=50 Ac1/Re2 (code H), which is LOOSER than the standard's n=80 Ac1/Re2 —
 * the band a Certificate of Conformance claimed Z1.4 compliance for.
 *
 * The setting is operator-editable, so this reconciles first: the seeded 0409
 * plan is replaced, an already-aligned plan is left alone, and a customised
 * plan stops the migration rather than being overwritten silently.
 */
return new class extends Migration
{
    private const KEY = 'quality.aql.sample_plan';

    public function up(): void
    {
        $this->swap(from: $this->seededPlan(), to: $this->z14Plan());
    }

    public function down(): void
    {
        $this->swap(from: $this->z14Plan(), to: $this->seededPlan());
    }

    private function swap(array $from, array $to): void
    {
        $raw = DB::table('settings')->where('key', self::KEY)->value('value');
        if ($raw === null) {
            throw new RuntimeException(self::KEY.' is missing; restore it before migrating the AQL plan.');
        }
        $current = json_decode((string) $raw, true);
        if ($current == $to) {
            return;
        }
        if ($current != $from) {
            throw new RuntimeException(
                self::KEY.' has been customised and does not match the plan this migration replaces. '
                .'Review it against ANSI/ASQ Z1.4 Table II-A (AQL 0.65, Level II) and set it by hand.'
            );
        }

        DB::table('settings')->where('key', self::KEY)->update(['value' => json_encode($to), 'updated_at' => now()]);
        Cache::forget('settings:'.self::KEY);
    }

    /** Standard plan. `code` is the letter of the sampling plan actually used. */
    private function z14Plan(): array
    {
        $f = ['code' => 'F', 'sample_size' => 20, 'accept' => 0, 'reject' => 1];
        $j = ['code' => 'J', 'sample_size' => 80, 'accept' => 1, 'reject' => 2];

        return [
            'tiny_batch' => ['code' => 'A', 'accept' => 0, 'reject' => 1],
            'rows' => [
                ['max_lot' => 8] + $f,
                ['max_lot' => 15] + $f,
                ['max_lot' => 25] + $f,
                ['max_lot' => 50] + $f,
                ['max_lot' => 90] + $f,
                ['max_lot' => 150] + $f,
                ['max_lot' => 280] + $f,
                ['max_lot' => 500] + $j,
                ['max_lot' => 1200] + $j,
                ['max_lot' => 3200, 'code' => 'K', 'sample_size' => 125, 'accept' => 2, 'reject' => 3],
                ['max_lot' => 10000, 'code' => 'L', 'sample_size' => 200, 'accept' => 3, 'reject' => 4],
                ['max_lot' => 35000, 'code' => 'M', 'sample_size' => 315, 'accept' => 5, 'reject' => 6],
                ['max_lot' => 150000, 'code' => 'N', 'sample_size' => 500, 'accept' => 7, 'reject' => 8],
                ['max_lot' => 500000, 'code' => 'P', 'sample_size' => 800, 'accept' => 10, 'reject' => 11],
            ],
            'overflow' => ['code' => 'Q', 'sample_size' => 1250, 'accept' => 14, 'reject' => 15],
        ];
    }

    /** The plan 0409 seeded, verbatim. */
    private function seededPlan(): array
    {
        $g = ['code' => 'G', 'sample_size' => 32, 'accept' => 0, 'reject' => 1];

        return [
            'tiny_batch' => ['code' => 'A', 'accept' => 0, 'reject' => 1],
            'rows' => [
                ['max_lot' => 8] + $g,
                ['max_lot' => 15] + $g,
                ['max_lot' => 25] + $g,
                ['max_lot' => 50] + $g,
                ['max_lot' => 90] + $g,
                ['max_lot' => 150] + $g,
                ['max_lot' => 280] + $g,
                ['max_lot' => 500, 'code' => 'H', 'sample_size' => 50, 'accept' => 1, 'reject' => 2],
                ['max_lot' => 1200, 'code' => 'J', 'sample_size' => 80, 'accept' => 1, 'reject' => 2],
                ['max_lot' => 3200, 'code' => 'K', 'sample_size' => 125, 'accept' => 2, 'reject' => 3],
                ['max_lot' => 10000, 'code' => 'L', 'sample_size' => 200, 'accept' => 3, 'reject' => 4],
                ['max_lot' => 35000, 'code' => 'M', 'sample_size' => 315, 'accept' => 5, 'reject' => 6],
                ['max_lot' => 150000, 'code' => 'N', 'sample_size' => 500, 'accept' => 7, 'reject' => 8],
                ['max_lot' => 500000, 'code' => 'P', 'sample_size' => 800, 'accept' => 10, 'reject' => 11],
            ],
            'overflow' => ['code' => 'Q', 'sample_size' => 1250, 'accept' => 14, 'reject' => 15],
        ];
    }
};
