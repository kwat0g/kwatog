<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Modules\Assets\Enums\AssetCategory;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetDepreciation;
use App\Modules\Assets\Resources\AssetResource;
use App\Modules\Assets\Services\AssetService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M031-F20 — asset detail must not resolve one journal entry per history row.
 *
 * `AssetResource` publishes each depreciation row's journal hash. It used to
 * call `JournalEntry::find()` inside the map, which is a fresh query rather
 * than a lazy relation load, so `Model::preventLazyLoading()` never saw it:
 * six months of history cost six extra `select * from journal_entries`, and a
 * five-year asset paid sixty on every detail load. This pins the count so the
 * eager load cannot be dropped again silently.
 */
class AssetDetailEagerLoadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_detail_serialisation_does_not_scale_queries_with_history_length(): void
    {
        $asset = Asset::create([
            'asset_code' => 'AST-EAGER-001',
            'name' => 'Eager load fixture',
            'category' => AssetCategory::Machine->value,
            'acquisition_date' => '2026-01-01',
            'acquisition_cost' => '12000.00',
            'useful_life_years' => 5,
            'salvage_value' => '0.00',
            'status' => AssetStatus::Active->value,
        ]);

        $months = 6;
        for ($month = 1; $month <= $months; $month++) {
            $journalEntryId = DB::table('journal_entries')->insertGetId([
                'entry_number' => sprintf('JE-EAGER-%02d', $month),
                'date' => sprintf('2026-%02d-28', $month),
                'total_debit' => '200.00',
                'total_credit' => '200.00',
                'status' => 'posted',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            AssetDepreciation::create([
                'asset_id' => $asset->id,
                'period_year' => 2026,
                'period_month' => $month,
                'depreciation_amount' => '200.00',
                'accumulated_after' => sprintf('%d.00', 200 * $month),
                'journal_entry_id' => $journalEntryId,
                'created_at' => now(),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $payload = (new AssetResource(app(AssetService::class)->show($asset)))->toArray(request());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount($months, $payload['depreciations']);
        $this->assertNotNull($payload['depreciations'][0]['journal_entry_id']);

        $journalQueries = array_values(array_filter(
            $queries,
            static fn (array $q): bool => str_contains($q['query'], 'journal_entries'),
        ));
        $this->assertCount(
            1,
            $journalQueries,
            'Journal entries must be resolved in one eager load, not one query per depreciation row.',
        );
    }
}
