<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierAgreementDocument;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Models\PortalContractTemplate;
use App\Modules\B2B\Services\PortalContractPdfProcessor;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupplierAgreementSignatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        app(SettingsService::class)->set('modules.b2b_portals', true, 'modules');
        Storage::fake('local');
    }

    public function test_supplier_documents_are_vendor_scoped_and_require_an_authorized_signatory(): void
    {
        $vendor = Vendor::factory()->create();
        $otherVendor = Vendor::factory()->create();
        $staff = $this->staffWithPortalAccessPermission();
        $this->actingAs($staff)->post(
            "/api/v1/b2b/portal-access/suppliers/{$vendor->hash_id}/agreement-documents",
            ['file' => UploadedFile::fake()->createWithContent('supplier-agreement.pdf', "%PDF-1.4\nsupplier terms\n%%EOF")],
        )->assertCreated();

        $document = SupplierAgreementDocument::query()->firstOrFail();
        $this->actingAs($staff)
            ->postJson("/api/v1/b2b/portal-access/suppliers/{$vendor->hash_id}/agreement-documents/{$document->hash_id}/issue")
            ->assertOk()
            ->assertJsonPath('data.signature_status', 'pending');
        $signer = $this->supplierPortalUser($vendor, true);
        $otherSigner = $this->supplierPortalUser($otherVendor, true);

        $this->actingAs($signer, 'supplier_portal')
            ->getJson('/api/v1/b2b/supplier/agreement-documents')
            ->assertOk()
            ->assertJsonPath('data.0.file_name', 'supplier-agreement.pdf');
        $this->actingAs($otherSigner, 'supplier_portal')
            ->getJson("/api/v1/b2b/supplier/agreement-documents/{$document->hash_id}/download")
            ->assertNotFound();
        $this->assertSame(hash_file('sha256', Storage::disk('local')->path($document->file_path)), $document->document_sha256);

        $unauthorized = $this->supplierPortalUser($vendor, false);
        $this->actingAs($unauthorized, 'supplier_portal')
            ->postJson("/api/v1/b2b/supplier/agreement-documents/{$document->hash_id}/sign", ['typed_name' => $unauthorized->name])
            ->assertForbidden();

        $this->actingAs($signer, 'supplier_portal')
            ->postJson("/api/v1/b2b/supplier/agreement-documents/{$document->hash_id}/sign", ['typed_name' => $signer->name])
            ->assertOk()
            ->assertJsonPath('data.signature_status', 'signed');
        $this->actingAs($signer, 'supplier_portal')
            ->postJson("/api/v1/b2b/supplier/agreement-documents/{$document->hash_id}/sign", ['typed_name' => $signer->name])
            ->assertStatus(409);
    }

    public function test_supplier_template_fills_agreement_dates_supplied_when_creating_the_draft(): void
    {
        $vendor = Vendor::factory()->create();
        $staff = $this->staffWithPortalAccessPermission();
        $templatePath = 'portal-contract-templates/supplier/supplier-dates.pdf';
        $templateBytes = "%PDF-1.4\nsupplier agreement\n%%EOF";
        Storage::disk('local')->put($templatePath, $templateBytes);
        $template = PortalContractTemplate::query()->create([
            'name' => 'Supplier terms with dates',
            'realm' => 'supplier',
            'file_path' => $templatePath,
            'filename' => 'supplier-dates.pdf',
            'size' => strlen($templateBytes),
            'pdf_fields' => [
                ['name' => 'StartDate', 'type' => 'Text'],
                ['name' => 'EndDate', 'type' => 'Text'],
            ],
            'field_mappings' => [
                'StartDate' => 'effective_date',
                'EndDate' => 'expiry_date',
            ],
            'required_fields' => ['StartDate', 'EndDate'],
            'version' => 1,
            'source_sha256' => hash('sha256', $templateBytes),
            'created_by' => $staff->id,
        ]);

        $filledPath = tempnam(sys_get_temp_dir(), 'ogami-supplier-agreement-');
        file_put_contents($filledPath, "%PDF-1.4\nfilled supplier agreement\n%%EOF");
        $processor = \Mockery::mock(PortalContractPdfProcessor::class);
        $processor->shouldReceive('fill')
            ->once()
            ->withArgs(function (string $path, array $values): bool {
                $this->assertSame('2026-11-01', $values['StartDate']);
                $this->assertSame('2027-10-31', $values['EndDate']);

                return true;
            })
            ->andReturn($filledPath);
        $this->app->instance(PortalContractPdfProcessor::class, $processor);

        $this->actingAs($staff)->postJson(
            "/api/v1/b2b/portal-access/suppliers/{$vendor->hash_id}/agreement-documents",
            [
                'template_id' => $template->hash_id,
                'effective_date' => '2026-11-01',
                'expiry_date' => '2027-10-31',
            ],
        )->assertCreated()
            ->assertJsonPath('data.signature_status', 'draft');
    }

    private function staffWithPortalAccessPermission(): User
    {
        $role = Role::create(['name' => 'Portal Contract Manager '.uniqid(), 'slug' => 'portal_contract_manager_'.uniqid()]);
        $permission = Permission::firstOrCreate(['slug' => 'b2b.portal_access.manage'], ['name' => 'Manage portal access', 'module' => 'b2b']);
        $role->permissions()->attach($permission->id);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function supplierPortalUser(Vendor $vendor, bool $authorized): SupplierPortalUser
    {
        return SupplierPortalUser::query()->create([
            'vendor_id' => $vendor->id,
            'name' => 'Supplier Signer',
            'email' => 'supplier-signer-'.uniqid().'@example.test',
            'password' => 'Password1!',
            'is_active' => true,
            'is_authorized_signatory' => $authorized,
            'must_change_password' => false,
        ]);
    }
}
