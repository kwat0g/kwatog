<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\PortalContractTemplate;
use App\Modules\B2B\Services\PortalContractPdfProcessor;
use App\Modules\CRM\Models\CustomerAgreementDocument;
use App\Modules\CRM\Models\CustomerProductWarrantyOption;
use App\Modules\CRM\Models\Product;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class CustomerAgreementTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        app(SettingsService::class)->set('modules.crm', true, 'modules');
        app(SettingsService::class)->set('modules.b2b_portals', true, 'modules');
        Storage::fake('local');
    }

    public function test_customer_agreement_upload_stores_a_sha256_and_is_visible_to_its_customer_portal(): void
    {
        $customer = Customer::factory()->create();
        $staff = $this->staffWithCustomerAgreementPermissions();
        $portalUser = CustomerPortalUser::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Authorized Buyer',
            'email' => 'authorized-buyer-'.uniqid().'@example.test',
            'password' => 'Password1!',
            'is_active' => true,
            'is_authorized_signatory' => true,
            'must_change_password' => false,
        ]);
        $pdfBytes = "%PDF-1.4\ncustomer agreement\n%%EOF";

        $upload = $this->actingAs($staff)->post(
            "/api/v1/crm/customers/{$customer->hash_id}/agreement-documents",
            ['file' => UploadedFile::fake()->createWithContent('agreement.pdf', $pdfBytes)],
        );
        $upload->assertCreated();

        $document = CustomerAgreementDocument::query()->firstOrFail();
        $this->assertSame(hash('sha256', $pdfBytes), $document->document_sha256);
        $this->actingAs($portalUser, 'customer_portal')
            ->getJson('/api/v1/b2b/customer/agreement-documents')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('can_sign', true);
        $this->actingAs($staff)
            ->postJson("/api/v1/crm/customers/{$customer->hash_id}/agreement-documents/{$document->hash_id}/issue")
            ->assertOk()
            ->assertJsonPath('data.signature_status', 'pending');
        $this->actingAs($portalUser, 'customer_portal')
            ->getJson('/api/v1/b2b/customer/agreement-documents')
            ->assertOk()
            ->assertJsonPath('data.0.file_name', 'agreement.pdf')
            ->assertJsonPath('data.0.signature_status', 'pending');
    }

    public function test_only_the_customer_authorized_signatory_can_sign_once(): void
    {
        $customer = Customer::factory()->create();
        $staff = $this->staffWithCustomerAgreementPermissions();
        $document = $this->uploadCustomerDocument($customer, $staff);
        $this->actingAs($staff)
            ->postJson("/api/v1/crm/customers/{$customer->hash_id}/agreement-documents/{$document->hash_id}/issue")
            ->assertOk();
        $unauthorized = $this->customerPortalUser($customer, false);

        $this->actingAs($unauthorized, 'customer_portal')
            ->postJson("/api/v1/b2b/customer/agreement-documents/{$document->hash_id}/sign", ['typed_name' => $unauthorized->name])
            ->assertForbidden();

        $signer = $this->customerPortalUser($customer, true);
        $signed = $this->actingAs($signer, 'customer_portal')
            ->postJson("/api/v1/b2b/customer/agreement-documents/{$document->hash_id}/sign", ['typed_name' => $signer->name]);
        $signed->assertOk()
            ->assertJsonPath('data.signature_status', 'signed')
            ->assertJsonPath('data.signed_name', $signer->name);
        $this->assertNotNull($document->fresh()->signed_at);
        $this->assertSame(hash_file('sha256', Storage::disk('local')->path($document->file_path)), $document->fresh()->signed_document_sha256);

        $this->actingAs($signer, 'customer_portal')
            ->postJson("/api/v1/b2b/customer/agreement-documents/{$document->hash_id}/sign", ['typed_name' => $signer->name])
            ->assertStatus(409);
    }

    public function test_legacy_pending_customer_agreement_without_issue_timestamp_is_hidden_from_portal(): void
    {
        $customer = Customer::factory()->create();
        $staff = $this->staffWithCustomerAgreementPermissions();
        $document = $this->uploadCustomerDocument($customer, $staff);
        $document->forceFill(['signature_status' => 'pending', 'issued_at' => null])->save();
        $signer = $this->customerPortalUser($customer, true);

        $this->actingAs($signer, 'customer_portal')
            ->getJson('/api/v1/b2b/customer/agreement-documents')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_migration_returns_unissued_legacy_customer_agreements_to_draft(): void
    {
        $customer = Customer::factory()->create();
        $staff = $this->staffWithCustomerAgreementPermissions();
        $document = $this->uploadCustomerDocument($customer, $staff);
        $document->forceFill(['signature_status' => 'pending', 'issued_at' => null])->save();

        $migration = require base_path('database/migrations/2026_11_10_140000_protect_legacy_customer_agreements_from_signing.php');
        $migration->up();

        $this->assertSame('draft', $document->fresh()->signature_status);
    }

    public function test_legacy_pending_customer_agreement_without_issue_timestamp_cannot_be_signed(): void
    {
        $customer = Customer::factory()->create();
        $staff = $this->staffWithCustomerAgreementPermissions();
        $document = $this->uploadCustomerDocument($customer, $staff);
        $document->forceFill(['signature_status' => 'pending', 'issued_at' => null])->save();
        $signer = $this->customerPortalUser($customer, true);

        $this->actingAs($signer, 'customer_portal')
            ->postJson("/api/v1/b2b/customer/agreement-documents/{$document->hash_id}/sign", ['typed_name' => $signer->name])
            ->assertNotFound();
    }

    public function test_staff_cannot_issue_an_agreement_after_the_file_content_changes(): void
    {
        $customer = Customer::factory()->create();
        $staff = $this->staffWithCustomerAgreementPermissions();
        $document = $this->uploadCustomerDocument($customer, $staff);
        Storage::disk('local')->put($document->file_path, "%PDF-1.4\nchanged after upload\n%%EOF");

        $this->actingAs($staff)
            ->postJson("/api/v1/crm/customers/{$customer->hash_id}/agreement-documents/{$document->hash_id}/issue")
            ->assertStatus(409);
        $this->assertSame('draft', $document->fresh()->signature_status);
    }

    public function test_contract_template_discovers_fields_and_rejects_unapproved_mapping_values(): void
    {
        $staff = $this->staffWithCustomerAgreementPermissions();
        $this->app->instance(PortalContractPdfProcessor::class, new class implements PortalContractPdfProcessor
        {
            public function discoverFields(string $pdfPath): array
            {
                return [['name' => 'Buyer Name', 'type' => 'Text']];
            }

            public function fill(string $pdfPath, array $values): string
            {
                return '/tmp/not-used.pdf';
            }
        });
        $uploadedPdf = UploadedFile::fake()->createWithContent('honda-form.pdf', "%PDF-1.4\nform fields\n%%EOF");

        $response = $this->actingAs($staff)->post('/api/v1/b2b/portal-access/contract-templates', [
            'name' => 'Honda agreement form',
            'realm' => 'customer',
            'file' => $uploadedPdf,
        ]);
        $response->assertCreated()
            ->assertJsonPath('data.auto_fill_available', true)
            ->assertJsonPath('data.pdf_fields.0.name', 'Buyer Name');

        $template = PortalContractTemplate::query()->firstOrFail();
        Storage::disk('local')->assertExists($template->file_path);
        $this->actingAs($staff)
            ->putJson('/api/v1/b2b/portal-access/contract-templates/'.$template->hash_id, [
                'field_mappings' => ['Buyer Name' => 'customer_name'],
                'required_fields' => ['Buyer Name'],
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->actingAs($staff)
            ->putJson('/api/v1/b2b/portal-access/contract-templates/'.$template->hash_id, [
                'field_mappings' => ['Buyer Name' => 'customer_tin'],
                'required_fields' => ['Buyer Name'],
            ])
            ->assertUnprocessable();
    }

    public function test_ordinary_pdf_template_can_be_saved_without_auto_fill_fields(): void
    {
        $staff = $this->staffWithCustomerAgreementPermissions();
        $this->app->instance(PortalContractPdfProcessor::class, new class implements PortalContractPdfProcessor
        {
            public function discoverFields(string $pdfPath): array
            {
                return [];
            }

            public function fill(string $pdfPath, array $values): string
            {
                return '/tmp/not-used.pdf';
            }
        });

        $this->actingAs($staff)->post('/api/v1/b2b/portal-access/contract-templates', [
            'name' => 'Supplier terms attachment',
            'realm' => 'supplier',
            'file' => UploadedFile::fake()->createWithContent('supplier-terms.pdf', "%PDF-1.4\nplain agreement\n%%EOF"),
        ])->assertCreated()
            ->assertJsonPath('data.auto_fill_available', false)
            ->assertJsonPath('data.pdf_fields', []);
    }

    public function test_customer_template_auto_fills_allowed_products_and_product_warranty_terms(): void
    {
        $customer = Customer::factory()->create([
            'portal_catalog_mode' => 'restricted',
            'delivery_latitude' => 14.25,
            'delivery_longitude' => 121.1,
            'allowed_payment_methods' => ['bank_transfer', 'check'],
            'shipping_payer' => 'company',
        ]);
        $product = Product::factory()->create([
            'part_number' => 'PN-900',
            'name' => 'Rear lamp assembly',
            'portal_visibility' => 'restricted',
        ]);
        $customer->portalProducts()->attach($product->id);
        CustomerProductWarrantyOption::query()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'label' => '12-month standard',
            'warranty_duration_days' => 365,
            'warranty_report_window_days' => 30,
            'warranty_start_basis' => 'delivery',
            'warranty_covered_issues' => ['manufacturing_defect'],
            'warranty_remedies' => ['repair', 'replace'],
            'additional_terms' => null,
        ]);
        $staff = $this->staffWithCustomerAgreementPermissions();
        $templatePath = 'portal-contract-templates/customer/demo-customer-agreement.pdf';
        $pdfBytes = "%PDF-1.4\ndemo fillable agreement\n%%EOF";
        Storage::disk('local')->put($templatePath, $pdfBytes);
        $template = PortalContractTemplate::query()->create([
            'name' => 'Demo customer agreement',
            'realm' => 'customer',
            'file_path' => $templatePath,
            'filename' => 'demo-customer-agreement.pdf',
            'size' => strlen($pdfBytes),
            'pdf_fields' => [
                ['name' => 'AllowedProducts', 'type' => 'Text'],
                ['name' => 'WarrantySummary', 'type' => 'Text'],
                ['name' => 'DeliveryPin', 'type' => 'Text'],
                ['name' => 'PaymentMethods', 'type' => 'Text'],
                ['name' => 'ShippingPayer', 'type' => 'Text'],
            ],
            'field_mappings' => [
                'AllowedProducts' => 'allowed_products_summary',
                'WarrantySummary' => 'warranty_summary',
                'DeliveryPin' => 'delivery_map_pin',
                'PaymentMethods' => 'allowed_payment_methods',
                'ShippingPayer' => 'shipping_payer',
            ],
            'required_fields' => ['AllowedProducts', 'WarrantySummary', 'DeliveryPin', 'PaymentMethods', 'ShippingPayer'],
            'version' => 1,
            'source_sha256' => hash('sha256', $pdfBytes),
            'created_by' => $staff->id,
        ]);
        $filledPdfPath = tempnam(sys_get_temp_dir(), 'ogami-filled-agreement-');
        file_put_contents($filledPdfPath, "%PDF-1.4\nfilled demo agreement\n%%EOF");
        $processor = Mockery::mock(PortalContractPdfProcessor::class);
        $processor->shouldReceive('fill')
            ->once()
            ->withArgs(function (string $path, array $values): bool {
                $this->assertStringContainsString('PN-900', $values['AllowedProducts']);
                $this->assertStringContainsString('Rear lamp assembly', $values['AllowedProducts']);
                $this->assertStringContainsString('12-month standard', $values['WarrantySummary']);
                $this->assertStringContainsString('365 days', $values['WarrantySummary']);
                $this->assertStringContainsString('manufacturing defect', strtolower($values['WarrantySummary']));
                $this->assertStringContainsString('Repair, Replace', $values['WarrantySummary']);
                $this->assertSame('14.2500000, 121.1000000', $values['DeliveryPin']);
                $this->assertSame('Bank transfer, Check', $values['PaymentMethods']);
                $this->assertSame('Company', $values['ShippingPayer']);

                return true;
            })
            ->andReturn($filledPdfPath);
        $this->app->instance(PortalContractPdfProcessor::class, $processor);

        $this->actingAs($staff)
            ->postJson("/api/v1/crm/customers/{$customer->hash_id}/agreement-documents", ['template_id' => $template->hash_id])
            ->assertCreated()
            ->assertJsonPath('data.signature_status', 'draft');
    }

    private function uploadCustomerDocument(Customer $customer, User $staff): CustomerAgreementDocument
    {
        $response = $this->actingAs($staff)->post(
            "/api/v1/crm/customers/{$customer->hash_id}/agreement-documents",
            ['file' => UploadedFile::fake()->createWithContent('agreement.pdf', "%PDF-1.4\ncontent\n%%EOF")],
        );
        $response->assertCreated();

        return CustomerAgreementDocument::query()->firstOrFail();
    }

    private function customerPortalUser(Customer $customer, bool $authorized): CustomerPortalUser
    {
        return CustomerPortalUser::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Customer Signer',
            'email' => 'customer-signer-'.uniqid().'@example.test',
            'password' => 'Password1!',
            'is_active' => true,
            'is_authorized_signatory' => $authorized,
            'must_change_password' => false,
        ]);
    }

    private function staffWithCustomerAgreementPermissions(): User
    {
        $role = Role::create(['name' => 'Agreement Manager '.uniqid(), 'slug' => 'agreement_manager_'.uniqid()]);
        foreach (['accounting.customers.manage', 'accounting.customers.view', 'b2b.portal_access.manage', 'b2b.portal_access.view'] as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => 'crm']);
            $role->permissions()->attach($permission->id);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
