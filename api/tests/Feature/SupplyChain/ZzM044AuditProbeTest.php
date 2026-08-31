<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionParameterType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Services\DeliveryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * M044 re-audit probe. MEASUREMENT ONLY — records the observed behaviour of the
 * outgoing-QC -> delivery -> confirm -> invoice last mile. Assertions encode
 * what the code does TODAY so the audit report can cite a measured result;
 * failures here are findings, not regressions.
 */
class ZzM044AuditProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(SettingsSeeder::class);
        Storage::fake('local');
    }

    // ── P1. Confirm without CoC-worthy evidence ──────────────────────────────

    /**
     * A delivery whose linked outgoing inspection is `passed` but whose stored
     * evidence no longer supports a certificate: does confirm() refuse, or does
     * it ship + invoice with no certificate?
     */
    public function test_probe_confirm_when_coc_generation_is_refused(): void
    {
        $ctx = $this->scenario(status: DeliveryStatus::Delivered);
        // Falsify the evidence the way M056's guard is designed to catch.
        InspectionMeasurement::query()->where('inspection_id', $ctx['inspection']->id)->delete();

        $this->addProof($ctx['delivery'], $ctx['officer']);
        $confirmed = app(DeliveryService::class)->confirm($ctx['delivery'], $ctx['officer']);

        // MEASURED: confirmation succeeds anyway.
        $this->assertSame('confirmed', $confirmed->status->value,
            'PROBE: confirm() succeeds even when the CoC cannot be issued.');
        $this->assertSame(0, DeliveryProof::query()
            ->where('delivery_id', $ctx['delivery']->id)->where('proof_type', 'coc')->count(),
            'PROBE: no CoC is attached.');
        // And the delivery carries NO field recording that the certificate was refused.
        $row = DB::table('deliveries')->where('id', $ctx['delivery']->id)->first();
        $this->assertNotNull($row->invoice_id,
            'PROBE: an invoice is still raised for the uncertified shipment.');
    }

    // ── P2. CoC can be deleted / replaced after confirmation ─────────────────

    public function test_probe_coc_can_be_deleted_and_replaced_after_confirmation(): void
    {
        $ctx = $this->scenario(status: DeliveryStatus::Delivered);
        $this->addProof($ctx['delivery'], $ctx['officer']);
        app(DeliveryService::class)->confirm($ctx['delivery'], $ctx['officer']);

        $coc = DeliveryProof::query()
            ->where('delivery_id', $ctx['delivery']->id)->where('proof_type', 'coc')->firstOrFail();

        $officer = $this->userWith(['supply_chain.deliveries.create', 'supply_chain.view']);

        // Delete the certificate — another proof remains, so the "last proof"
        // guard does not fire.
        $this->actingAs($officer)->deleteJson(
            "/api/v1/supply-chain/deliveries/{$ctx['delivery']->hash_id}/proofs/{$coc->hash_id}"
        )->assertStatus(204);
        $this->assertSame(0, DeliveryProof::query()
            ->where('delivery_id', $ctx['delivery']->id)->where('proof_type', 'coc')->count(),
            'PROBE: a CoC can be deleted from a confirmed delivery.');

        // Replace it with arbitrary operator-supplied bytes under proof_type=coc.
        $this->actingAs($officer)->post(
            "/api/v1/supply-chain/deliveries/{$ctx['delivery']->hash_id}/proofs",
            ['proof_type' => 'coc', 'file' => UploadedFile::fake()->create('anything.pdf', 4, 'application/pdf')]
        )->assertStatus(201);
        $this->assertSame(1, DeliveryProof::query()
            ->where('delivery_id', $ctx['delivery']->id)->where('proof_type', 'coc')->count(),
            'PROBE: an arbitrary file can be posted as the replacement CoC after confirmation.');
    }

    // ── P3. Proof mutation after confirmation ────────────────────────────────

    public function test_probe_receipt_photo_can_be_replaced_after_confirmation(): void
    {
        $ctx = $this->scenario(status: DeliveryStatus::Delivered);
        $this->addProof($ctx['delivery'], $ctx['officer']);
        app(DeliveryService::class)->confirm($ctx['delivery'], $ctx['officer']);

        $officer = $this->userWith(['supply_chain.deliveries.create']);
        $this->actingAs($officer)->post(
            "/api/v1/supply-chain/deliveries/{$ctx['delivery']->hash_id}/receipt",
            ['file' => UploadedFile::fake()->image('late.jpg')]
        )->assertOk();

        $this->assertNotNull(Delivery::find($ctx['delivery']->id)->receipt_photo_path,
            'PROBE: receipt_photo_path is rewritten on a confirmed, invoiced delivery.');
    }

    // ── P4. Invoiced quantity is the delivered quantity, not the ordered one ──

    public function test_probe_partial_delivery_invoices_only_the_delivered_quantity(): void
    {
        // SO line orders 10; deliver 4.
        $ctx = $this->scenario(deliverQuantity: '4', status: DeliveryStatus::Delivered);
        $this->addProof($ctx['delivery'], $ctx['officer']);
        app(DeliveryService::class)->confirm($ctx['delivery'], $ctx['officer']);

        $invoiceId = Delivery::find($ctx['delivery']->id)->invoice_id;
        $this->assertNotNull($invoiceId);
        $lines = DB::table('invoice_items')->where('invoice_id', $invoiceId)->get();
        $this->assertCount(1, $lines);
        $this->assertSame('4.00', (string) $lines->first()->quantity,
            'Invoice must bill the delivered 4, not the ordered 10.');
        $this->assertSame('4.00', (string) SalesOrderItem::find($ctx['soItem']->id)->quantity_delivered,
            'The SO ledger must record the 4 that landed.');
    }

    // ── P5. Illegal status transitions ───────────────────────────────────────

    public function test_probe_illegal_status_transitions(): void
    {
        $svc = app(DeliveryService::class);
        $results = [];

        // dispatch (loading) straight from scheduled requires a vehicle
        $ctx = $this->scenario();
        $results['loading_without_vehicle'] = $this->attempt(fn () => $svc->updateStatus($ctx['delivery'], DeliveryStatus::Loading));

        // scheduled -> in_transit (skip loading)
        $ctx2 = $this->scenario();
        $results['scheduled_to_in_transit'] = $this->attempt(fn () => $svc->updateStatus($ctx2['delivery'], DeliveryStatus::InTransit));

        // scheduled -> delivered (skip everything)
        $ctx3 = $this->scenario();
        $results['scheduled_to_delivered'] = $this->attempt(fn () => $svc->updateStatus($ctx3['delivery'], DeliveryStatus::Delivered));

        // confirm an unconfirmed (scheduled) delivery
        $ctx4 = $this->scenario();
        $this->addProof($ctx4['delivery'], $ctx4['officer']);
        $results['confirm_scheduled'] = $this->attempt(fn () => $svc->confirm($ctx4['delivery'], $ctx4['officer']));

        // confirm a delivered delivery with no proof at all
        $ctx5 = $this->scenario(status: DeliveryStatus::Delivered);
        $results['confirm_without_proof'] = $this->attempt(fn () => $svc->confirm($ctx5['delivery'], $ctx5['officer']));

        // status route may not be used to reach confirmed
        $ctx6 = $this->scenario(status: DeliveryStatus::Delivered);
        $results['status_route_to_confirmed'] = $this->attempt(fn () => $svc->updateStatus($ctx6['delivery'], DeliveryStatus::Confirmed));

        // cancel a delivered delivery
        $ctx7 = $this->scenario(status: DeliveryStatus::Delivered);
        $results['cancel_delivered'] = $this->attempt(fn () => $svc->updateStatus($ctx7['delivery'], DeliveryStatus::Cancelled));

        // cancel then re-open a cancelled delivery
        $ctx8 = $this->scenario();
        $svc->updateStatus($ctx8['delivery'], DeliveryStatus::Cancelled);
        $results['reopen_cancelled'] = $this->attempt(fn () => $svc->updateStatus($ctx8['delivery']->fresh(), DeliveryStatus::Loading));

        // cancel a confirmed delivery
        $ctx9 = $this->scenario(status: DeliveryStatus::Delivered);
        $this->addProof($ctx9['delivery'], $ctx9['officer']);
        $svc->confirm($ctx9['delivery'], $ctx9['officer']);
        $results['cancel_confirmed'] = $this->attempt(fn () => $svc->updateStatus($ctx9['delivery']->fresh(), DeliveryStatus::Cancelled));

        // confirm twice
        $results['confirm_twice'] = $this->attempt(fn () => $svc->confirm($ctx9['delivery']->fresh(), $ctx9['officer']));

        // delete a confirmed delivery
        $results['delete_confirmed'] = $this->attempt(fn () => $svc->delete($ctx9['delivery']->fresh()));

        // reassign a confirmed delivery
        $results['assign_confirmed'] = $this->attempt(fn () => $svc->assign($ctx9['delivery']->fresh(), [
            'vehicle_id' => 0, 'driver_id' => 0, 'reason' => 'probe reason',
        ], $ctx9['officer']));

        fwrite(STDERR, "\n[M044 transitions] ".json_encode($results, JSON_PRETTY_PRINT)."\n");

        foreach (['loading_without_vehicle', 'scheduled_to_in_transit', 'scheduled_to_delivered',
            'confirm_scheduled', 'confirm_without_proof', 'status_route_to_confirmed', 'cancel_delivered',
            'reopen_cancelled', 'cancel_confirmed', 'delete_confirmed', 'assign_confirmed'] as $key) {
            $this->assertNotSame('OK', $results[$key], "Illegal transition {$key} must be refused.");
        }
        $this->assertSame('OK', $results['confirm_twice'], 'Re-confirm must be an idempotent no-op.');
    }

    // ── P6. Delivery against a cancelled sales order ─────────────────────────

    public function test_probe_delivery_against_a_cancelled_sales_order(): void
    {
        $ctx = $this->scenario(createDelivery: false);
        DB::table('sales_orders')->where('id', $ctx['so']->id)->update(['status' => 'cancelled']);

        $result = $this->attempt(fn () => app(DeliveryService::class)->create([
            'sales_order_id' => $ctx['so']->id,
            'scheduled_date' => '2026-09-10',
            'items' => [[
                'sales_order_item_id' => $ctx['soItem']->id,
                'quantity' => '5',
                'inspection_id' => $ctx['inspection']->id,
            ]],
        ], $ctx['officer']));

        fwrite(STDERR, "\n[M044 cancelled-SO create] {$result}\n");
        // M044-F011, FIXED this session: the gate lives in
        // assertDeliveryQuantitiesAvailable(), the one seam the manual create
        // path and the outgoing-QC auto-draft listener both pass through.
        $this->assertStringContainsString('is cancelled', $result,
            'A delivery must not be creatable against a cancelled sales order.');
    }

    // ── P7. Upload hardening — real UploadedFile, content-sniffed MIME ────────

    public function test_probe_upload_mime_is_validated_from_bytes_not_the_filename(): void
    {
        $ctx = $this->scenario(status: DeliveryStatus::Delivered);
        $officer = $this->userWith(['supply_chain.deliveries.create']);

        // A real UploadedFile whose BYTES are a PHP script but whose name is .jpg.
        $tmp = tempnam(sys_get_temp_dir(), 'probe').'.jpg';
        file_put_contents($tmp, "<?php echo 'pwned'; ?>\n");
        $evil = new UploadedFile($tmp, 'evil.jpg', 'image/jpeg', null, true);

        $this->actingAs($officer)->post(
            "/api/v1/supply-chain/deliveries/{$ctx['delivery']->hash_id}/receipt",
            ['file' => $evil]
        )->assertStatus(422);

        // Same bytes against the multi-proof endpoint.
        $tmp2 = tempnam(sys_get_temp_dir(), 'probe').'.pdf';
        file_put_contents($tmp2, "<?php echo 'pwned'; ?>\n");
        $evil2 = new UploadedFile($tmp2, 'evil.pdf', 'application/pdf', null, true);
        $this->actingAs($officer)->post(
            "/api/v1/supply-chain/deliveries/{$ctx['delivery']->hash_id}/proofs",
            ['proof_type' => 'photo', 'file' => $evil2]
        )->assertStatus(422);

        @unlink($tmp);
        @unlink($tmp2);
    }

    public function test_probe_stored_filename_is_random_and_traversal_is_neutralised(): void
    {
        $ctx = $this->scenario(status: DeliveryStatus::Delivered);
        $officer = $this->userWith(['supply_chain.deliveries.create', 'supply_chain.view']);

        $tmp = sys_get_temp_dir().'/m044-traversal.jpg';
        $image = imagecreatetruecolor(8, 8);
        imagejpeg($image, $tmp);
        imagedestroy($image);
        $file = new UploadedFile($tmp, '../../../../etc/passwd.jpg', 'image/jpeg', null, true);

        $this->actingAs($officer)->post(
            "/api/v1/supply-chain/deliveries/{$ctx['delivery']->hash_id}/proofs",
            ['proof_type' => 'photo', 'file' => $file]
        )->assertStatus(201);

        $proof = DeliveryProof::query()->where('delivery_id', $ctx['delivery']->id)->latest('id')->firstOrFail();
        fwrite(STDERR, "\n[M044 upload] file_path={$proof->file_path} file_name={$proof->file_name}\n");

        $this->assertStringStartsWith("deliveries/{$ctx['delivery']->id}/proofs/", $proof->file_path);
        $this->assertStringNotContainsString('..', $proof->file_path, 'Stored path must not contain traversal.');
        $this->assertStringNotContainsString('passwd', $proof->file_path, 'Stored path must be a random hash name.');
        // The DISPLAY name is stored verbatim from the client.
        $this->assertSame('passwd.jpg', $proof->file_name,
            'PROBE: Symfony basenames the client name, so traversal segments are dropped from file_name too.');
        @unlink($tmp);
    }

    public function test_probe_content_disposition_header_with_a_quoted_client_filename(): void
    {
        $ctx = $this->scenario(status: DeliveryStatus::Delivered);
        $officer = $this->userWith(['supply_chain.deliveries.create', 'supply_chain.view']);

        $proof = DeliveryProof::create([
            'delivery_id' => $ctx['delivery']->id,
            'proof_type' => 'photo',
            'file_name' => 'a".jpg',
            'file_path' => "deliveries/{$ctx['delivery']->id}/proofs/x.jpg",
            'mime_type' => 'image/jpeg',
            'uploaded_by' => $officer->id,
        ]);
        Storage::disk('local')->put($proof->file_path, 'bytes');

        $response = $this->actingAs($officer)->get(
            "/api/v1/supply-chain/deliveries/{$ctx['delivery']->hash_id}/proofs/{$proof->hash_id}/view"
        );
        $header = $response->headers->get('Content-Disposition');
        fwrite(STDERR, "\n[M044 disposition] ".var_export($header, true)."\n");
        // M044-F010, FIXED this session: the quote is stripped from the ASCII
        // parameter and the real name is carried in filename* instead.
        $this->assertSame('inline; filename="a.jpg"; filename*=UTF-8\'\'a.jpg', $header,
            'The proof stream must not let a client filename forge the header.');
    }

    // ── P8. Files after a delivery is archived ───────────────────────────────

    public function test_probe_archive_deletes_the_files_and_restore_cannot_bind(): void
    {
        $ctx = $this->scenario();
        $officer = $this->userWith(['supply_chain.deliveries.create']);
        $this->actingAs($officer)->post(
            "/api/v1/supply-chain/deliveries/{$ctx['delivery']->hash_id}/proofs",
            ['proof_type' => 'photo', 'file' => UploadedFile::fake()->image('dr.jpg')]
        )->assertStatus(201);
        $proof = DeliveryProof::query()->where('delivery_id', $ctx['delivery']->id)->firstOrFail();
        $this->assertTrue(Storage::disk('local')->exists($proof->file_path));

        $this->actingAs($officer)
            ->deleteJson("/api/v1/supply-chain/deliveries/{$ctx['delivery']->hash_id}")
            ->assertStatus(204);

        // MEASURED: the physical artifact is gone, the proof row is NOT archived
        // with its parent, and the restore route cannot bind the archived row.
        $this->assertFalse(Storage::disk('local')->exists($proof->file_path),
            'PROBE: archiving a delivery permanently deletes its proof files.');
        $this->assertNull(DeliveryProof::find($proof->id)?->deleted_at,
            'PROBE: the proof row stays live and now points at a missing file.');

        $restore = $this->actingAs($officer)
            ->patchJson("/api/v1/supply-chain/deliveries/{$ctx['delivery']->hash_id}/restore");
        fwrite(STDERR, "\n[M044 restore] status={$restore->status()} body={$restore->getContent()}\n");
        $this->assertSame(404, $restore->status(),
            'PROBE: the restore route lacks withTrashed(), so it 404s for every valid target.');
        $message = (string) ($restore->json('message') ?? '');
        fwrite(STDERR, "[M044 restore message] {$message}\n");
        $this->assertStringNotContainsString((string) $ctx['delivery']->id, $message,
            'A 404 message must not leak the raw primary key.');
    }

    // ── P9. Quantity / money validation ──────────────────────────────────────

    public function test_probe_quantity_validation_edge_values(): void
    {
        $ctx = $this->scenario(createDelivery: false);
        $officer = $this->userWith(['supply_chain.deliveries.create']);
        $observed = [];

        foreach (['1.999', '1e3', '1e17', '1e20', '10.00005', '-1', '0'] as $value) {
            $response = $this->actingAs($officer)->postJson('/api/v1/supply-chain/deliveries', [
                'sales_order_id' => $ctx['so']->hash_id,
                'scheduled_date' => '2026-09-10',
                'items' => [[
                    'sales_order_item_id' => $ctx['soItem']->hash_id,
                    'quantity' => $value,
                    'inspection_id' => $ctx['inspection']->hash_id,
                ]],
            ]);
            $observed[$value] = $response->status();
        }

        fwrite(STDERR, "\n[M044 quantity validation] ".json_encode($observed)."\n");
        foreach ($observed as $value => $status) {
            $this->assertSame(422, $status, "quantity={$value} must be a 422, not a 500 or a silent accept.");
        }
        $this->assertDatabaseCount('deliveries', 0);
    }

    // ── P10. Archived-row leakage ────────────────────────────────────────────

    public function test_probe_soft_deleted_rows_across_reads_and_aggregates(): void
    {
        $ctx = $this->scenario();
        $officer = $this->userWith(['supply_chain.deliveries.create', 'supply_chain.view']);
        $observed = [];

        // Archived delivery: excluded from the list, and the show route 404s.
        $ctx['delivery']->delete();
        $list = $this->actingAs($officer)->getJson('/api/v1/supply-chain/deliveries')->assertOk();
        $observed['archived_delivery_in_list'] = count($list->json('data'));
        $observed['archived_delivery_show'] = $this->actingAs($officer)
            ->getJson("/api/v1/supply-chain/deliveries/{$ctx['delivery']->hash_id}")->status();
        $ctx['delivery']->restore();

        // Archived customer behind a live delivery: does the list 500 or leak?
        Customer::withTrashed()->find($ctx['customer']->id)->delete();
        $listAfter = $this->actingAs($officer)->getJson('/api/v1/supply-chain/deliveries');
        $observed['archived_customer_list_status'] = $listAfter->status();
        $observed['archived_customer_show_status'] = $this->actingAs($officer)
            ->getJson("/api/v1/supply-chain/deliveries/{$ctx['delivery']->hash_id}")->status();

        // Archived sales order behind a live delivery.
        SalesOrder::withTrashed()->find($ctx['so']->id)->delete();
        $observed['archived_so_list_status'] = $this->actingAs($officer)
            ->getJson('/api/v1/supply-chain/deliveries')->status();
        $observed['archived_so_show_status'] = $this->actingAs($officer)
            ->getJson("/api/v1/supply-chain/deliveries/{$ctx['delivery']->hash_id}")->status();

        // Archived inspection behind an existing delivery line — the manual
        // create path must not accept it.
        $observed['archived_inspection_options'] = count($this->actingAs($officer)
            ->getJson('/api/v1/supply-chain/deliveries/inspection-options?sales_order_id='.$ctx['so']->hash_id)
            ->json('data') ?? []);

        fwrite(STDERR, "\n[M044 archived rows] ".json_encode($observed, JSON_PRETTY_PRINT)."\n");

        $this->assertSame(0, $observed['archived_delivery_in_list'], 'Archived delivery must not appear in the list.');
        $this->assertSame(404, $observed['archived_delivery_show'], 'Archived delivery show must 404.');
        $this->assertLessThan(500, $observed['archived_customer_list_status'], 'Archived customer must not 500 the list.');
        $this->assertLessThan(500, $observed['archived_customer_show_status'], 'Archived customer must not 500 the detail.');
        $this->assertLessThan(500, $observed['archived_so_list_status'], 'Archived SO must not 500 the list.');
        $this->assertLessThan(500, $observed['archived_so_show_status'], 'Archived SO must not 500 the detail.');
    }

    // ── P11. Permission matrix for the six registry roles ────────────────────

    public function test_probe_registry_role_permission_matrix(): void
    {
        $ctx = $this->scenario(status: DeliveryStatus::Delivered);
        $this->addProof($ctx['delivery'], $ctx['officer']);
        $hash = $ctx['delivery']->hash_id;
        $proof = DeliveryProof::query()->where('delivery_id', $ctx['delivery']->id)->firstOrFail();

        $endpoints = [
            'GET  deliveries' => ['get',   '/api/v1/supply-chain/deliveries'],
            'GET  deliveries/options' => ['get',   '/api/v1/supply-chain/deliveries/options'],
            'GET  delivery detail' => ['get',   "/api/v1/supply-chain/deliveries/{$hash}"],
            'GET  inspection-options' => ['get',   '/api/v1/supply-chain/deliveries/inspection-options?sales_order_id='.$ctx['so']->hash_id],
            'GET  driver-options' => ['get',   '/api/v1/supply-chain/deliveries/driver-options'],
            'GET  proofs/options' => ['get',   '/api/v1/supply-chain/deliveries/proofs/options'],
            'GET  proofs index' => ['get',   "/api/v1/supply-chain/deliveries/{$hash}/proofs"],
            'GET  proof view' => ['get',   "/api/v1/supply-chain/deliveries/{$hash}/proofs/{$proof->hash_id}/view"],
            'GET  receipt-photo' => ['get',   "/api/v1/supply-chain/deliveries/{$hash}/receipt-photo"],
            'POST confirm' => ['post',  "/api/v1/supply-chain/deliveries/{$hash}/confirm"],
            'PATCH status' => ['patch', "/api/v1/supply-chain/deliveries/{$hash}/status"],
            'PATCH assignment' => ['patch', "/api/v1/supply-chain/deliveries/{$hash}/assignment"],
            'GET  vehicles' => ['get',   '/api/v1/supply-chain/vehicles'],
            'POST vehicles' => ['post',  '/api/v1/supply-chain/vehicles'],
        ];

        $matrix = [];
        foreach (['system_admin', 'purchasing_officer', 'warehouse_staff', 'impex_officer', 'driver'] as $slug) {
            $role = Role::query()->where('slug', $slug)->firstOrFail();
            $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
            foreach ($endpoints as $label => [$verb, $url]) {
                $matrix[$slug][$label] = $this->actingAs($user)->{$verb.'Json'}($url)->status();
            }
        }
        // Driver PWA surface, own vs another driver's delivery.
        $driverRole = Role::query()->where('slug', 'driver')->firstOrFail();
        $mine = User::factory()->create(['role_id' => $driverRole->id, 'is_active' => true]);
        $other = User::factory()->create(['role_id' => $driverRole->id, 'is_active' => true]);
        $ctx['delivery']->forceFill(['driver_id' => $mine->id])->save();

        $matrix['driver_own']['GET /driver/deliveries/{id}'] = $this->actingAs($mine)
            ->getJson("/api/v1/driver/deliveries/{$hash}")->status();
        $foreign = $this->actingAs($other)->getJson("/api/v1/driver/deliveries/{$hash}");
        $matrix['driver_other']['GET /driver/deliveries/{id}'] = $foreign->status();
        $matrix['driver_other']['body'] = $foreign->getContent();
        $matrix['driver_other']['PATCH status'] = $this->actingAs($other)
            ->patchJson("/api/v1/driver/deliveries/{$hash}/status", ['status' => 'confirmed'])->status();

        fwrite(STDERR, "\n[M044 permission matrix] ".json_encode($matrix, JSON_PRETTY_PRINT)."\n");

        $this->assertSame(404, $matrix['driver_other']['GET /driver/deliveries/{id}'],
            "A driver must not read another driver's delivery.");
        $foreignMessage = (string) ($foreign->json('message') ?? '');
        $this->assertStringNotContainsString((string) $ctx['delivery']->id, $foreignMessage,
            'Refusal message must not echo the raw primary key.');
        $this->assertStringNotContainsString($ctx['delivery']->delivery_number, $foreignMessage,
            "Refusal message must not echo the victim's delivery number.");
    }

    // ── P12. No stock movement is emitted when goods leave ───────────────────

    public function test_probe_delivery_emits_no_finished_goods_stock_movement(): void
    {
        $ctx = $this->scenario(status: DeliveryStatus::Delivered);
        $this->addProof($ctx['delivery'], $ctx['officer']);
        $before = DB::table('stock_movements')->count();
        app(DeliveryService::class)->confirm($ctx['delivery'], $ctx['officer']);
        $after = DB::table('stock_movements')->count();

        fwrite(STDERR, "\n[M044 stock] movements before={$before} after={$after}\n");
        $this->assertSame($before, $after,
            'PROBE: shipping and confirming a delivery creates no stock movement at all.');
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'delivery')->count(),
            'PROBE: StockMovementType::Delivery is never emitted by any code path.');
    }

    // ── P13. The AR side of the proof-of-delivery -> invoice contract ────────

    /**
     * SupplyChain gates invoicing behind confirmation. Accounting's own invoice
     * endpoint also accepts a delivery_id — does IT check the delivery status?
     */
    public function test_probe_ar_can_invoice_an_unconfirmed_or_cancelled_delivery(): void
    {
        $observed = [];

        foreach ([DeliveryStatus::Scheduled, DeliveryStatus::InTransit] as $status) {
            $ctx = $this->scenario(status: $status);
            $observed[$status->value] = $this->invoiceViaAccounting($ctx);
        }

        // And a delivery that was cancelled outright.
        $ctx = $this->scenario();
        app(DeliveryService::class)->updateStatus($ctx['delivery'], DeliveryStatus::Cancelled);
        $observed['cancelled'] = $this->invoiceViaAccounting($ctx);

        fwrite(STDERR, "\n[M044 AR invoice vs delivery status] ".json_encode($observed, JSON_PRETTY_PRINT)."\n");

        // MEASURED DEFECT (M044-F013, owner: accounts-receivable):
        // InvoiceService::resolveSourceChain() locks the delivery and checks its
        // customer/SO, but never reads its status.
        foreach ($observed as $status => $result) {
            $this->assertSame(201, $result['status'],
                "PROBE: AR raises an invoice against a {$status} delivery.");
        }
    }

    /** @param array<string, mixed> $ctx */
    private function invoiceViaAccounting(array $ctx): array
    {
        $clerk = $this->userWith(['accounting.invoices.create', 'accounting.invoices.view']);
        $revenue = DB::table('accounts')->where('code', '4000')->value('id')
            ?? DB::table('accounts')->where('account_type', 'revenue')->value('id');

        $response = $this->actingAs($clerk)->postJson('/api/v1/invoices', [
            'customer_id' => $ctx['customer']->hash_id,
            'date' => '2026-09-06',
            'sales_order_id' => $ctx['so']->hash_id,
            'delivery_id' => $ctx['delivery']->hash_id,
            'items' => [[
                'revenue_account_id' => app('hashids')->encode((int) $revenue),
                'description' => 'Probe line',
                'quantity' => '10',
                'unit_price' => '100.00',
            ]],
        ]);

        return ['status' => $response->status(), 'message' => (string) ($response->json('message') ?? '')];
    }

    // ── P14. Customer portal cross-tenant isolation ──────────────────────────

    public function test_probe_customer_a_cannot_reach_customer_b_delivery(): void
    {
        $victim = $this->scenario(status: DeliveryStatus::Delivered);
        $this->addProof($victim['delivery'], $victim['officer']);
        $proof = DeliveryProof::query()->where('delivery_id', $victim['delivery']->id)->firstOrFail();

        $attacker = Customer::create(['name' => 'Attacker '.uniqid(), 'is_active' => true, 'payment_terms_days' => 30]);
        $portalUser = $this->portalUser($attacker->id);
        $this->actingAs($portalUser, 'customer_portal');

        $observed = [];
        foreach ([
            'detail' => "/api/v1/b2b/customer/deliveries/{$victim['delivery']->hash_id}",
            'proof' => "/api/v1/b2b/customer/deliveries/{$victim['delivery']->hash_id}/proofs/{$proof->hash_id}/view",
        ] as $label => $url) {
            $response = $this->getJson($url);
            $observed[$label] = [
                'status' => $response->status(),
                'body' => substr((string) $response->json('message'), 0, 160),
                'leaks_dr' => str_contains($response->getContent(), $victim['delivery']->delivery_number),
                'leaks_so' => str_contains($response->getContent(), $victim['so']->so_number),
            ];
        }
        // Own-tenant list must not include the victim either.
        $list = $this->getJson('/api/v1/b2b/customer/deliveries');
        $observed['list_count'] = count($list->json('data') ?? []);

        // There is no portal confirm/dispute route at all — record that.
        $observed['confirm_route'] = $this->postJson(
            "/api/v1/b2b/customer/deliveries/{$victim['delivery']->hash_id}/confirm",
        )->status();

        fwrite(STDERR, "\n[M044 portal cross-tenant] ".json_encode($observed, JSON_PRETTY_PRINT)."\n");

        $this->assertSame(403, $observed['detail']['status'], "Customer A must not read B's delivery.");
        // 404 here, not 403: B2BTenancyScopeMiddleware scopes the {delivery}
        // route binding itself, so the proof route cannot even resolve the row.
        $this->assertContains($observed['proof']['status'], [403, 404],
            "Customer A must not read B's proof.");
        $this->assertFalse($observed['detail']['leaks_dr'], 'Refusal must not echo the DR number.');
        $this->assertFalse($observed['detail']['leaks_so'], 'Refusal must not echo the SO number.');
        $this->assertFalse($observed['proof']['leaks_dr'], 'Proof refusal must not echo the DR number.');
        $this->assertSame(0, $observed['list_count'], "A's list must be empty.");
        $this->assertSame(404, $observed['confirm_route'],
            'PROBE: the portal exposes no delivery confirmation route.');
    }

    private function portalUser(int $customerId): CustomerPortalUser
    {
        return CustomerPortalUser::create([
            'customer_id' => $customerId,
            'name' => 'Portal '.uniqid(),
            'email' => 'portal'.substr(uniqid(), -8).'@example.test',
            'password' => bcrypt('Str0ng!Passw0rd'),
            'is_active' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function attempt(callable $fn): string
    {
        try {
            $fn();

            return 'OK';
        } catch (\Throwable $e) {
            return class_basename($e).': '.substr($e->getMessage(), 0, 120);
        }
    }

    /** @param list<string> $slugs */
    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'M044 Probe '.uniqid(),
            'slug' => 'm044_probe_'.uniqid(),
            'description' => 'Probe',
        ]);
        foreach ($slugs as $slug) {
            $perm = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => 'supply_chain']);
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function addProof(Delivery $d, User $by): void
    {
        DeliveryProof::create([
            'delivery_id' => $d->id,
            'proof_type' => 'signed_dr',
            'file_name' => 'dr.pdf',
            'file_path' => "deliveries/{$d->id}/dr.pdf",
            'mime_type' => 'application/pdf',
            'uploaded_by' => $by->id,
        ]);
    }

    /**
     * One fully provenance-linked outgoing-QC scenario: customer, product,
     * confirmed SO ordering 10, completed WO + output, passed outgoing
     * inspection with resolved measurements, and a delivery for `$deliverQuantity`.
     *
     * @return array<string, mixed>
     */
    private function scenario(
        string $deliverQuantity = '10',
        DeliveryStatus $status = DeliveryStatus::Scheduled,
        bool $createDelivery = true,
    ): array {
        $officer = $this->userWith(['supply_chain.deliveries.create', 'supply_chain.deliveries.confirm', 'supply_chain.view']);

        $customer = Customer::create(['name' => 'Cust '.uniqid(), 'is_active' => true, 'payment_terms_days' => 30]);
        $product = Product::create([
            'part_number' => strtoupper(substr(uniqid('PT-'), 0, 12)),
            'name' => 'Wiper Bushing '.uniqid(),
            'unit_of_measure' => 'pcs',
            'standard_cost' => '50.00',
            'is_active' => true,
        ]);
        $so = SalesOrder::create([
            'so_number' => 'SO-P4-'.substr(uniqid(), -10),
            'customer_id' => $customer->id,
            'date' => '2026-09-01',
            'subtotal' => '1000.00', 'vat_amount' => '120.00', 'total_amount' => '1120.00',
            'status' => 'confirmed', 'created_by' => $officer->id,
        ]);
        $soItem = SalesOrderItem::create([
            'sales_order_id' => $so->id, 'product_id' => $product->id,
            'quantity' => '10', 'unit_price' => '100.00', 'total' => '1000.00',
            'quantity_delivered' => 0, 'delivery_date' => '2026-09-08',
        ]);
        $workOrder = WorkOrder::create([
            'wo_number' => 'WO-P4-'.substr(uniqid(), -8),
            'product_id' => $product->id,
            'sales_order_id' => $so->id, 'sales_order_item_id' => $soItem->id,
            'quantity_target' => 10, 'quantity_produced' => 10, 'quantity_good' => 10, 'quantity_rejected' => 0,
            'planned_start' => '2026-08-30 08:00:00', 'planned_end' => '2026-08-31 17:00:00',
            'status' => 'completed', 'created_by' => $officer->id,
        ]);
        $output = WorkOrderOutput::create([
            'work_order_id' => $workOrder->id, 'recorded_by' => $officer->id,
            'recorded_at' => '2026-08-31 17:00:00',
            'good_count' => 10, 'reject_count' => 0,
            'batch_code' => 'P4-'.substr(uniqid(), -8),
        ]);
        $inspection = Inspection::create([
            'inspection_number' => 'QC-P4-'.substr(uniqid(), -8),
            'stage' => InspectionStage::Outgoing->value,
            'status' => InspectionStatus::Passed->value,
            'product_id' => $product->id,
            'entity_type' => InspectionEntityType::WorkOrder->value,
            'entity_id' => $workOrder->id,
            'work_order_output_id' => $output->id,
            'batch_quantity' => 10, 'accepted_quantity' => 10,
            'sample_size' => 5, 'accept_count' => 0, 'reject_count' => 1, 'defect_count' => 0,
            'inspector_id' => $officer->id, 'completed_at' => '2026-08-31 18:00:00',
        ]);
        for ($i = 1; $i <= 5; $i++) {
            InspectionMeasurement::create([
                'inspection_id' => $inspection->id, 'sample_index' => $i,
                'parameter_name' => 'Outer diameter',
                'parameter_type' => InspectionParameterType::Dimensional->value,
                'unit_of_measure' => 'mm', 'nominal_value' => '10.0000',
                'tolerance_min' => '9.9000', 'tolerance_max' => '10.1000',
                'measured_value' => '10.0100', 'is_critical' => true, 'is_pass' => true,
            ]);
        }

        $delivery = null;
        if ($createDelivery) {
            $delivery = app(DeliveryService::class)->create([
                'sales_order_id' => $so->id,
                'scheduled_date' => '2026-09-05',
                'items' => [[
                    'sales_order_item_id' => $soItem->id,
                    'quantity' => $deliverQuantity,
                    'inspection_id' => $inspection->id,
                ]],
            ], $officer);

            if ($status !== DeliveryStatus::Scheduled) {
                $delivery->forceFill(['status' => $status->value, 'delivered_at' => '2026-09-05 14:00:00'])->save();
                if ($status === DeliveryStatus::Delivered) {
                    DB::transaction(fn () => app(DeliveryService::class)
                        ->syncDeliveredQuantities(SalesOrder::query()->lockForUpdate()->find($so->id)));
                }
            }
        }

        return compact('officer', 'customer', 'product', 'so', 'soItem', 'workOrder', 'output', 'inspection', 'delivery');
    }
}
