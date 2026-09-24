<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PreflightMrpReleaseMigrations extends Command
{
    protected $signature = 'mrp:preflight-release-migrations';

    protected $description = 'Read-only preflight for the duplicate guards in migrations 0501 and 0531';

    public function handle(): int
    {
        $bomDuplicates = DB::table('bom_items')
            ->select('bom_id', 'item_id')
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->groupBy('bom_id', 'item_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $productDuplicates = DB::table('products')
            ->selectRaw('UPPER(part_number) AS normalized_part_number')
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->groupByRaw('UPPER(part_number)')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($bomDuplicates->isEmpty() && $productDuplicates->isEmpty()) {
            $this->info('PASS: migrations 0501 and 0531 have no duplicate data to reject.');

            return self::SUCCESS;
        }

        foreach ($bomDuplicates as $duplicate) {
            $this->error(sprintf(
                'FAIL 0501: bom_id=%d/item_id=%d occurs %d times.',
                $duplicate->bom_id,
                $duplicate->item_id,
                $duplicate->duplicate_count,
            ));
        }

        foreach ($productDuplicates as $duplicate) {
            $this->error(sprintf(
                'FAIL 0531: UPPER(part_number)=%s occurs %d times.',
                json_encode($duplicate->normalized_part_number, JSON_THROW_ON_ERROR),
                $duplicate->duplicate_count,
            ));
        }

        $this->error('No rows were changed. Resolve or disposition the reported duplicates before running the migrations.');

        return self::FAILURE;
    }
}
