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
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
