<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Services\Import\MasterDataImportService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Phase 2 — `approved_suppliers` registered in the REC-03 master-data import
 * pipeline (dry-run, atomic commit, rollback).
 */
class ApprovedSupplierImportTest extends TestCase
{
    use RefreshDatabase;

    private MasterDataImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MasterDataImportService::class);
    }

    private function csv(string $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'approved-suppliers.csv',
            "item_code,vendor_name,lead_time_days,last_price,is_preferred,qualification_status\n".$rows,
        );
    }

    public function test_entity_is_registered_with_required_columns(): void
    {
        $this->assertContains('approved_suppliers', $this->service->entityTypes());

        $schema = $this->service->entitySchemas()['approved_suppliers'];
        $this->assertSame(['item_code', 'vendor_name'], $schema['required']);
    }

    public function test_dry_run_then_commit_imports_a_link(): void
    {
        $item = Item::factory()->create(['code' => 'RM-001']);
        $vendor = Vendor::factory()->create(['name' => 'Acme Plastics']);

        $dry = $this->service->dryRun('approved_suppliers', $this->csv('RM-001,Acme Plastics,5,100.50,false,approved'));
        $this->assertSame(1, $dry['valid']);
        $this->assertSame([], $dry['errors']);
        $this->assertDatabaseCount('approved_suppliers', 0);

        $result = $this->service->commit(
            'approved_suppliers',
            $this->csv('RM-001,Acme Plastics,5,100.50,false,approved'),
            User::factory()->create(),
        );

        $this->assertNotNull($result['batch']);
        $this->assertSame(1, $result['imported']);
        $this->assertSame([], $result['errors']);

        $row = ApprovedSupplier::query()->where('item_id', $item->id)->where('vendor_id', $vendor->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(ApprovedSupplier::QUALIFICATION_APPROVED, $row->qualification_status);
        $this->assertSame(5, $row->lead_time_days);
        $this->assertSame('100.50', (string) $row->last_price);
    }

    public function test_qualification_defaults_to_approved_when_omitted(): void
    {
        $item = Item::factory()->create(['code' => 'RM-002']);
        $vendor = Vendor::factory()->create(['name' => 'Beta Resins']);

        $file = UploadedFile::fake()->createWithContent(
            'approved-suppliers.csv',
            "item_code,vendor_name\nRM-002,Beta Resins\n",
        );

        $result = $this->service->commit('approved_suppliers', $file, User::factory()->create());
        $this->assertSame(1, $result['imported']);

        $this->assertSame(
            ApprovedSupplier::QUALIFICATION_APPROVED,
            ApprovedSupplier::query()->where('item_id', $item->id)->where('vendor_id', $vendor->id)->value('qualification_status'),
        );
    }

    public function test_unknown_item_or_vendor_fails_the_row_with_a_clear_message(): void
    {
        Item::factory()->create(['code' => 'RM-003']);
        Vendor::factory()->create(['name' => 'Gamma']);

        $dry = $this->service->dryRun('approved_suppliers', $this->csv('RM-999,Gamma,5,10,false,approved'));
        $this->assertSame(0, $dry['valid']);
        $this->assertCount(1, $dry['errors']);
        $this->assertStringContainsString('RM-999', $dry['errors'][0]['message']);

        $dry2 = $this->service->dryRun('approved_suppliers', $this->csv('RM-003,No Such Vendor,5,10,false,approved'));
        $this->assertSame(0, $dry2['valid']);
        $this->assertStringContainsString('No Such Vendor', $dry2['errors'][0]['message']);
    }

    public function test_rollback_deletes_only_the_imported_rows(): void
    {
        $item = Item::factory()->create(['code' => 'RM-004']);
        $vendor = Vendor::factory()->create(['name' => 'Delta']);

        $result = $this->service->commit(
            'approved_suppliers',
            $this->csv('RM-004,Delta,5,10,false,approved'),
            User::factory()->create(),
        );

        $this->service->rollback($result['batch'], User::factory()->create());

        $this->assertNull(
            ApprovedSupplier::query()->where('item_id', $item->id)->where('vendor_id', $vendor->id)->first(),
        );
    }
}
