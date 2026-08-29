<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Models\AuditLog;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Models\PortalShippingDocument;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Enums\PpapElementStatus;
use App\Modules\Quality\Enums\PpapElementType;
use App\Modules\Quality\Enums\PpapLevel;
use App\Modules\Quality\Enums\PpapStatus;
use App\Modules\Quality\Models\PpapElement;
use App\Modules\Quality\Models\PpapSubmission;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cross-tenant isolation drill for the supplier B2B portal (M047).
 *
 * The portal is externally facing, so tenancy is the defining risk and it is
 * measured here by real HTTP requests rather than reasoned about: two suppliers
 * are given comparable data and Supplier A is pointed at every one of Supplier
 * B's identifiers, on every route that accepts one.
 *
 * Two independent mechanisms are supposed to stop it, and both are asserted:
 *   1. B2BTenancyScopeMiddleware installs a vendor global scope, so a route
 *      binding for another tenant's HashID never resolves (404 at binding).
 *   2. Every service method re-checks `vendor_id` after binding (403), which is
 *      what protects any future caller that bypasses the middleware.
 * A HashID from another tenant still decodes to a valid row, so (2) is load
 * bearing even though (1) currently fires first.
 */
class SupplierPortalCrossTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class]);
    }

    /* ─── Fixtures ───────────────────────────────────────────────── */

    private function makePortalUser(Vendor $vendor): SupplierPortalUser
    {
        return SupplierPortalUser::create([
            'vendor_id' => $vendor->id,
            'name' => 'SupUser-'.substr(uniqid(), -5),
            'email' => 'su-'.uniqid().'@t.test',
            'password' => bcrypt('Password1!'),
            'is_active' => true,
        ]);
    }

    /**
     * A PO in a supplier-visible lifecycle state. PurchaseOrderFactory defaults
     * to `draft`, which the portal hides on purpose, so every fixture states the
     * state it means.
     */
    private function makePo(Vendor $vendor, string $status = 'sent'): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $po->forceFill(['status' => $status])->save();

        return $po->refresh();
    }

    private function makePoItem(PurchaseOrder $po, string $quantity = '500.00'): PurchaseOrderItem
    {
        return PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => Item::factory()->create()->id,
            'description' => 'Relay Cover',
            'quantity' => $quantity,
            'unit' => 'pcs',
            'unit_price' => '10.00',
            'total' => '5000.00',
            'quantity_received' => '0.00',
        ]);
    }

    private function makeBill(Vendor $vendor, ?PurchaseOrder $po = null, string $status = 'unpaid'): Bill
    {
        $bill = Bill::create([
            'bill_number' => 'BILL-T-'.substr(uniqid(), -5),
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $po?->id,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'is_vatable' => true,
            'subtotal' => '1000.00',
            'vat_amount' => '120.00',
            'total_amount' => '1120.00',
            'amount_paid' => '0.00',
            'balance' => '1120.00',
            'created_by' => User::factory()->create()->id,
        ]);
        $bill->forceFill(['status' => $status])->save();

        return $bill->refresh();
    }

    private function makeDocument(PurchaseOrder $po, SupplierPortalUser $uploader): PortalShippingDocument
    {
        Storage::fake('local');
        $path = "portal/shipping-docs/{$po->id}/secret-".substr(uniqid(), -5).'.pdf';
        Storage::disk('local')->put($path, '%PDF-tenant-b-secret%');

        return PortalShippingDocument::create([
            'purchase_order_id' => $po->id,
            'document_type' => 'packing_list',
            'file_path' => $path,
            'original_filename' => 'tenant-b-packing-list.pdf',
            'file_size_bytes' => 21,
            'content_sha256' => hash('sha256', '%PDF-tenant-b-secret%'.$po->id),
            'mime_type' => 'application/pdf',
            'uploaded_by' => $uploader->id,
            'uploaded_at' => now(),
        ]);
    }

    /**
     * Two fully populated tenants. A is the caller; B is the victim.
     *
     * @return array{a: array<string, mixed>, b: array<string, mixed>}
     */
    private function twoTenants(): array
    {
        $build = function (string $tag): array {
            $vendor = Vendor::factory()->create(['name' => "Vendor-{$tag}"]);
            $user = $this->makePortalUser($vendor);
            $po = $this->makePo($vendor, 'sent');
            $poItem = $this->makePoItem($po);
            $bill = $this->makeBill($vendor, $po);
            $grn = GoodsReceiptNote::factory()->create([
                'vendor_id' => $vendor->id,
                'purchase_order_id' => $po->id,
                'grn_number' => "GRN-{$tag}-".substr(uniqid(), -4),
                'status' => 'accepted',
            ]);
            $document = $this->makeDocument($po, $user);
            $schedule = DeliverySchedule::create([
                'vendor_id' => $vendor->id,
                'purchase_order_id' => $po->id,
                'month' => '2026-09',
                'status' => 'submitted',
                'lines' => [[
                    'purchase_order_item_id' => $poItem->hash_id,
                    'product_name' => "Part-{$tag}",
                    'quantity' => '10.00',
                    'notes' => null,
                ]],
            ]);
            $ppap = PpapSubmission::create([
                'ppap_number' => 'PP-'.$tag.'-'.substr(uniqid(), -5),
                'vendor_id' => $vendor->id,
                'item_id' => Item::factory()->create()->id,
                'ppap_level' => PpapLevel::Level3->value,
                'submission_date' => '2026-06-01',
                'status' => PpapStatus::Submitted->value,
            ]);
            PpapElement::create([
                'ppap_submission_id' => $ppap->id,
                'element_type' => PpapElementType::ControlPlan->value,
                'status' => PpapElementStatus::Submitted->value,
                'document_path' => "ppap/{$tag}/private-control-plan.pdf",
                'notes' => "PPAP note for {$tag}",
            ]);

            return compact('vendor', 'user', 'po', 'poItem', 'bill', 'grn', 'document', 'schedule', 'ppap');
        };

        return ['a' => $build('A'), 'b' => $build('B')];
    }

    private function actAs(SupplierPortalUser $user): void
    {
        Sanctum::actingAs($user, ['*'], 'supplier_portal');
    }

    /* ─── 1. Collection endpoints must scope server-side ─────────── */

    public function test_every_supplier_collection_endpoint_is_scoped_to_the_calling_vendor(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoTenants();
        $this->actAs($a['user']);

        // GET dashboard
        $dashboard = $this->getJson('/api/v1/b2b/supplier/dashboard')->assertOk();
        $this->assertSame(1, $dashboard->json('data.open_po_count'));
        $this->assertSame(1, $dashboard->json('data.unpaid_invoice_count'));
        $dashboard->assertJsonMissing(['po_number' => $b['po']->po_number]);
        $dashboard->assertJsonMissing(['bill_number' => $b['bill']->bill_number]);

        // GET purchase-orders
        $pos = $this->getJson('/api/v1/b2b/supplier/purchase-orders')->assertOk();
        $this->assertSame([$a['po']->hash_id], $this->ids($pos->json('data')));
        $pos->assertJsonMissing(['po_number' => $b['po']->po_number]);

        // GET invoices
        $invoices = $this->getJson('/api/v1/b2b/supplier/invoices')->assertOk();
        $this->assertSame([$a['bill']->hash_id], $this->ids($invoices->json('data')));
        $invoices->assertJsonMissing(['bill_number' => $b['bill']->bill_number]);

        // GET deliveries
        $deliveries = $this->getJson('/api/v1/b2b/supplier/deliveries')->assertOk();
        $this->assertSame([$a['grn']->hash_id], $this->ids($deliveries->json('data')));
        $deliveries->assertJsonMissing(['grn_number' => $b['grn']->grn_number]);

        // GET statement-of-account
        $soa = $this->getJson('/api/v1/b2b/supplier/statement-of-account')->assertOk();
        $this->assertSame('Vendor-A', $soa->json('data.vendor_name'));
        $this->assertSame([$a['bill']->hash_id], $this->ids($soa->json('data.open_bills')));
        $soa->assertJsonMissing(['bill_number' => $b['bill']->bill_number]);
        // B's balance must not be summed into A's aging total.
        $this->assertSame('1120.00', $soa->json('data.total_outstanding'));

        // GET delivery-schedules
        $schedules = $this->getJson('/api/v1/b2b/supplier/delivery-schedules')->assertOk();
        $this->assertSame([$a['schedule']->hash_id], $this->ids($schedules->json('data')));

        // GET ppap-submissions
        $ppap = $this->getJson('/api/v1/b2b/supplier/ppap-submissions')->assertOk();
        $this->assertSame([$a['ppap']->hash_id], $this->ids($ppap->json('data')));
        $ppap->assertJsonMissing(['ppap_number' => $b['ppap']->ppap_number]);

        // GET purchase-orders/{po}/shipping-documents — own PO only.
        $docs = $this->getJson("/api/v1/b2b/supplier/purchase-orders/{$a['po']->hash_id}/shipping-documents")
            ->assertOk();
        $this->assertSame([$a['document']->hash_id], $this->ids($docs->json('data')));
    }

    /**
     * Top-level `id` values of a collection payload.
     *
     * assertJsonMissing(['id' => …]) searches the WHOLE document, including
     * nested objects, and HashIDs are salted globally rather than per model
     * (`app('hashids')->encode(2)` is the same string for a PurchaseOrder and a
     * DeliverySchedule with pk 2). So a document-wide `id` search matches a
     * nested `purchase_order.id` and reports a leak that is not there. Compare
     * the exact top-level id set instead.
     *
     * @param  array<int, array<string, mixed>>|null  $rows
     * @return array<int, string>
     */
    private function ids(?array $rows): array
    {
        return array_values(array_map(static fn (array $row): string => (string) $row['id'], $rows ?? []));
    }

    /* ─── 2. Every {model} binding must refuse another tenant ────── */

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function crossTenantRouteProvider(): array
    {
        return [
            'PO detail' => ['GET', 'purchase-orders/{po}', []],
            'PO pdf' => ['GET', 'purchase-orders/{po}/pdf', []],
            'PO shipping-document list' => ['GET', 'purchase-orders/{po}/shipping-documents', []],
            'PO acknowledge' => ['POST', 'purchase-orders/{po}/acknowledge', ['notes' => 'x']],
            'PO shipment-update' => ['POST', 'purchase-orders/{po}/shipment-update', ['carrier' => 'Maersk']],
            'invoice detail' => ['GET', 'invoices/{bill}', []],
            'invoice pdf' => ['GET', 'invoices/{bill}/pdf', []],
        ];
    }

    /**
     * @dataProvider crossTenantRouteProvider
     *
     * @param  array<string, mixed>  $payload
     */
    public function test_route_bindings_refuse_another_tenants_identifier(string $verb, string $template, array $payload): void
    {
        ['a' => $a, 'b' => $b] = $this->twoTenants();
        $this->actAs($a['user']);

        $path = '/api/v1/b2b/supplier/'.str_replace(
            ['{po}', '{bill}'],
            [$b['po']->hash_id, $b['bill']->hash_id],
            $template,
        );

        $response = $verb === 'GET'
            ? $this->getJson($path)
            : $this->postJson($path, $payload);

        // 404 (global scope refused the binding) or 403 (service ownership
        // check) are both acceptable refusals. 200 is a tenancy breach.
        $this->assertContains(
            $response->status(),
            [403, 404],
            "{$verb} {$template} returned {$response->status()} for another tenant's identifier",
        );

        // Whatever the refusal, it must not echo the victim's data or a raw
        // integer id. A `{"id":42}` body is an existence oracle.
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString($b['po']->po_number, $body);
        $this->assertStringNotContainsString($b['bill']->bill_number, $body);
        $this->assertStringNotContainsString('"id":'.$b['po']->id, $body);
        $this->assertStringNotContainsString('"id":'.$b['bill']->id, $body);
    }

    public function test_cross_tenant_mutations_leave_the_victims_rows_untouched(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoTenants();
        $this->actAs($a['user']);

        $beforeRemarks = $b['po']->remarks;
        $beforeStatus = $b['po']->status->value;

        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$b['po']->hash_id}/acknowledge", [
            'notes' => 'tenant A writing into tenant B',
            'expected_delivery_date' => '2026-12-01',
        ]);
        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$b['po']->hash_id}/shipment-update", [
            'carrier' => 'HijackLine',
            'tracking_number' => 'HIJACK-1',
        ]);

        $fresh = PurchaseOrder::withoutGlobalScope('b2b_tenancy')->findOrFail($b['po']->id);
        $this->assertSame($beforeRemarks, $fresh->remarks);
        $this->assertSame($beforeStatus, $fresh->status->value);
        $this->assertDatabaseMissing('supplier_shipments', ['purchase_order_id' => $b['po']->id]);
    }

    public function test_supplier_cannot_submit_an_invoice_against_another_tenants_purchase_order(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoTenants();
        $this->actAs($a['user']);

        $response = $this->postJson(
            "/api/v1/b2b/supplier/purchase-orders/{$b['po']->hash_id}/submit-invoice",
            [
                'bill_number' => 'HIJ-'.substr(uniqid(), -5),
                'date' => now()->toDateString(),
            ],
        );

        $this->assertContains($response->status(), [403, 404]);
        // No bill may be created for either vendor by this call.
        $this->assertSame(
            2,
            Bill::withoutGlobalScope('b2b_tenancy')->count(),
            'a cross-tenant invoice submission created an extra bill',
        );
    }

    public function test_supplier_cannot_upload_a_shipping_document_onto_another_tenants_purchase_order(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoTenants();
        Storage::fake('local');
        $this->actAs($a['user']);

        $response = $this->post(
            "/api/v1/b2b/supplier/purchase-orders/{$b['po']->hash_id}/shipping-documents",
            [
                'document_type' => 'packing_list',
                'file' => UploadedFile::fake()->createWithContent('inject.pdf', '%PDF-injected%'),
            ],
            ['Accept' => 'application/json'],
        );

        $this->assertContains($response->status(), [403, 404]);
        $this->assertSame(
            0,
            PortalShippingDocument::withoutGlobalScope('b2b_tenancy')
                ->where('purchase_order_id', $b['po']->id)
                ->where('original_filename', 'inject.pdf')
                ->count(),
        );
        // A refused upload must not leave a stored file behind either.
        $this->assertEmpty(Storage::disk('local')->allFiles("portal/shipping-docs/{$b['po']->id}"));
    }

    public function test_supplier_cannot_download_another_tenants_shipping_document(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoTenants();
        $this->actAs($a['user']);

        $response = $this->getJson("/api/v1/b2b/supplier/shipping-documents/{$b['document']->hash_id}/download");

        $this->assertContains($response->status(), [403, 404]);
        $this->assertStringNotContainsString('tenant-b-secret', $response->getContent() ?: '');
        $this->assertStringNotContainsString('tenant-b-packing-list', $response->getContent() ?: '');
    }

    public function test_supplier_cannot_schedule_against_another_tenants_purchase_order_or_line(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoTenants();
        $this->actAs($a['user']);

        // (a) another tenant's PO in the body
        $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $b['po']->hash_id,
            'month' => '2026-10',
            'lines' => [['purchase_order_item_id' => $b['poItem']->hash_id, 'quantity' => 5]],
        ])->assertStatus(422)->assertJsonValidationErrors(['purchase_order_id']);

        // (b) own PO, but a line belonging to another tenant's PO
        $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $a['po']->hash_id,
            'month' => '2026-10',
            'lines' => [['purchase_order_item_id' => $b['poItem']->hash_id, 'quantity' => 5]],
        ])->assertStatus(422);

        $this->assertSame(
            2,
            DeliverySchedule::withoutGlobalScope('b2b_tenancy')->count(),
            'a cross-tenant schedule was persisted',
        );
    }

    /* ─── 3. File handling ───────────────────────────────────────── */

    public function test_download_refuses_traversal_and_garbage_identifiers(): void
    {
        ['a' => $a] = $this->twoTenants();
        $this->actAs($a['user']);

        foreach (['..%2F..%2Fetc%2Fpasswd', '0', 'not-a-hash', '%2E%2E%2F%2E%2E%2Fstorage'] as $candidate) {
            $response = $this->getJson("/api/v1/b2b/supplier/shipping-documents/{$candidate}/download");
            $this->assertContains(
                $response->status(),
                [403, 404, 405],
                "download accepted the identifier '{$candidate}' with {$response->status()}",
            );
            $this->assertStringNotContainsString('root:', $response->getContent() ?: '');
        }
    }

    public function test_uploaded_files_get_random_names_outside_the_public_root_and_reject_bad_mime(): void
    {
        ['a' => $a] = $this->twoTenants();
        Storage::fake('local');
        $this->actAs($a['user']);

        // A path-traversal client filename must not become a storage path.
        $this->post(
            "/api/v1/b2b/supplier/purchase-orders/{$a['po']->hash_id}/shipping-documents",
            [
                'document_type' => 'bill_of_lading',
                'file' => UploadedFile::fake()->createWithContent('../../evil.pdf', '%PDF-ok%'),
            ],
            ['Accept' => 'application/json'],
        )->assertStatus(201);

        $stored = PortalShippingDocument::withoutGlobalScope('b2b_tenancy')
            ->where('purchase_order_id', $a['po']->id)
            ->where('document_type', 'bill_of_lading')
            ->firstOrFail();

        $this->assertStringStartsWith("portal/shipping-docs/{$a['po']->id}/", $stored->file_path);
        $this->assertStringNotContainsString('..', $stored->file_path);
        $this->assertStringNotContainsString('..', (string) $stored->original_filename);
        $this->assertNotSame('evil.pdf', basename($stored->file_path), 'stored name is not randomised');

        // Server-side MIME enforcement: an executable payload named .pdf.
        //
        // This deliberately does NOT use UploadedFile::fake(). Laravel's
        // Illuminate\Http\Testing\File overrides getMimeType() to return
        // MimeType::from($name) — derived from the FILENAME — so a fake named
        // "payload.pdf" reports application/pdf whatever its bytes are, and a
        // `mimes:` assertion written against the fake proves nothing about the
        // application. A real UploadedFile inherits Symfony's getMimeType(),
        // which sniffs the file with finfo, which is the production path.
        $tmp = tempnam(sys_get_temp_dir(), 'm047');
        file_put_contents($tmp, "<?php echo 'pwn'; ?>\n");
        $realUpload = new UploadedFile(
            $tmp,
            'payload.pdf',
            'application/pdf',   // client-declared, i.e. attacker-controlled
            null,
            true,                // $test
        );

        $this->post(
            "/api/v1/b2b/supplier/purchase-orders/{$a['po']->hash_id}/shipping-documents",
            ['document_type' => 'commercial_invoice', 'file' => $realUpload],
            ['Accept' => 'application/json'],
        )->assertStatus(422)->assertJsonValidationErrors(['file']);

        @unlink($tmp);
    }

    /* ─── 4. Auth surface ────────────────────────────────────────── */

    public function test_a_portal_write_records_an_audit_row_under_the_custom_guard(): void
    {
        // CLAUDE.md warns that under a non-web guard Auth::id() returns a
        // non-User PK and any HasAuditLog write FK-violates on audit_logs.
        // Prove empirically that an acknowledgement (which touches PurchaseOrder,
        // a HasAuditLog model) both succeeds and writes its audit rows.
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'approved');
        $this->makePoItem($po);

        $this->actAs($user);
        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/acknowledge", [
            'notes' => 'received and acknowledged',
        ])->assertOk();

        // The portal-principal row: user_id null, actor_type supplier_portal.
        $portalRow = AuditLog::query()
            ->where('action', 'supplier_po.ack')
            ->where('model_id', $po->id)
            ->firstOrFail();
        $this->assertNull($portalRow->user_id);
        $this->assertSame('supplier_portal', $portalRow->actor_type);
        $this->assertSame($user->id, $portalRow->new_values['portal_user_id']);
        $this->assertSame($vendor->id, $portalRow->new_values['vendor_id']);

        // The HasAuditLog row from the underlying PurchaseOrder write must exist
        // and must point at a real users row, not the portal-user PK.
        $modelRow = AuditLog::query()
            ->where('model_type', PurchaseOrder::class)
            ->where('model_id', $po->id)
            ->whereNotNull('user_id')
            ->latest('id')
            ->first();
        $this->assertNotNull($modelRow, 'no HasAuditLog row was written for the portal PO write');
        $this->assertTrue(
            User::query()->whereKey($modelRow->user_id)->exists(),
            'audit_logs.user_id does not reference a real users row under the portal guard',
        );
    }

    public function test_login_and_forgot_password_do_not_disclose_whether_an_account_exists(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        $known = $this->postJson('/api/v1/b2b/supplier/login', [
            'email' => $user->email,
            'password' => 'WrongPassword1!',
        ]);
        $unknown = $this->postJson('/api/v1/b2b/supplier/login', [
            'email' => 'nobody-'.uniqid().'@t.test',
            'password' => 'WrongPassword1!',
        ]);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('errors.email'), $unknown->json('errors.email'));

        $forgotKnown = $this->postJson('/api/v1/b2b/supplier/forgot-password', ['email' => $user->email]);
        $forgotUnknown = $this->postJson('/api/v1/b2b/supplier/forgot-password', ['email' => 'nobody-'.uniqid().'@t.test']);

        $this->assertSame($forgotKnown->status(), $forgotUnknown->status());
        $this->assertSame($forgotKnown->json('message'), $forgotUnknown->json('message'));
    }

    public function test_supplier_login_is_rate_limited_by_the_auth_limiter(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        $statuses = [];
        for ($i = 0; $i < 8; $i++) {
            $statuses[] = $this->postJson('/api/v1/b2b/supplier/login', [
                'email' => $user->email,
                'password' => 'WrongPassword1!',
            ])->status();
        }

        $this->assertContains(429, $statuses, 'supplier login was never throttled in 8 attempts');
    }

    public function test_a_supplier_token_cannot_reach_the_internal_portal_access_administration(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $this->actAs($user);

        $this->getJson('/api/v1/b2b/portal-access/suppliers')->assertStatus(401);
        $this->postJson("/api/v1/b2b/portal-access/suppliers/{$vendor->hash_id}/invite", [
            'name' => 'Self Promoted',
            'email' => 'self-'.uniqid().'@t.test',
        ])->assertStatus(401);
    }
}
