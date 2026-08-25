<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Common\Enums\DocumentType;
use App\Common\Models\Document;
use App\Common\Services\DocumentVaultService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_view_returns_401_or_redirect(): void
    {
        Storage::fake('local');
        $doc = $this->seedDoc();
        $this->getJson("/api/v1/documents/{$doc->hash_id}/view")
            ->assertStatus(401);
    }

    public function test_view_is_inline_for_authorized_user(): void
    {
        Storage::fake('local');
        $doc = $this->seedDoc(DocumentType::Invoice);
        $user = $this->seedUser(['accounting.invoices.view']);

        $resp = $this->actingAs($user)
            ->get("/api/v1/documents/{$doc->hash_id}/view");

        $resp->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline', $resp->headers->get('Content-Disposition'));
    }

    public function test_download_is_attachment(): void
    {
        Storage::fake('local');
        $doc = $this->seedDoc(DocumentType::Invoice);
        $user = $this->seedUser(['accounting.invoices.view']);

        $resp = $this->actingAs($user)
            ->get("/api/v1/documents/{$doc->hash_id}/download");

        $resp->assertOk();
        $this->assertStringContainsString('attachment', $resp->headers->get('Content-Disposition'));
    }

    public function test_view_is_403_without_permission(): void
    {
        Storage::fake('local');
        $doc = $this->seedDoc(DocumentType::Invoice);
        $user = $this->seedUser(['hr.employees.view']); // wrong permission

        $this->actingAs($user)
            ->get("/api/v1/documents/{$doc->hash_id}/view")
            ->assertForbidden();
    }

    public function test_confidential_payslip_sets_no_store_cache_header(): void
    {
        Storage::fake('local');
        $doc = $this->seedDoc(DocumentType::Payslip, confidential: true);
        $user = $this->seedUser(['payroll.payslip.view_all', 'payroll.view']);

        $resp = $this->actingAs($user)
            ->get("/api/v1/documents/{$doc->hash_id}/view");

        $resp->assertOk();
        $this->assertStringContainsString('no-store', (string) $resp->headers->get('Cache-Control'));
    }

    public function test_payslip_owner_can_view_but_another_employee_cannot(): void
    {
        Storage::fake('local');
        $payroll = $this->publishedPayroll();
        $doc = app(DocumentVaultService::class)->store('BYTES', DocumentType::Payslip, $payroll, null, true);

        $owner = $this->seedUser(['payroll.view'], $payroll->employee_id);
        $this->actingAs($owner)
            ->get("/api/v1/documents/{$doc->hash_id}/view")
            ->assertOk();

        $other = $this->seedUser(['payroll.view'], Employee::factory()->create()->id);
        $this->actingAs($other)
            ->get("/api/v1/documents/{$doc->hash_id}/view")
            ->assertForbidden();
    }

    public function test_department_head_can_view_same_department_but_not_other_department(): void
    {
        Storage::fake('local');
        $department = Department::factory()->create();
        $viewerEmployee = Employee::factory()->create(['department_id' => $department->id]);
        $ownerEmployee = Employee::factory()->create(['department_id' => $department->id]);
        $otherEmployee = Employee::factory()->create();
        $sameDepartmentPayroll = $this->publishedPayroll($ownerEmployee);
        $otherDepartmentPayroll = $this->publishedPayroll($otherEmployee);
        $sameDepartmentDoc = app(DocumentVaultService::class)->store('BYTES', DocumentType::Payslip, $sameDepartmentPayroll, null, true);
        $otherDepartmentDoc = app(DocumentVaultService::class)->store('BYTES', DocumentType::Payslip, $otherDepartmentPayroll, null, true);
        $head = $this->seedUser(['payroll.view'], $viewerEmployee->id, 'department_head');

        $this->actingAs($head)
            ->get("/api/v1/documents/{$sameDepartmentDoc->hash_id}/view")
            ->assertOk();
        $this->actingAs($head)
            ->get("/api/v1/documents/{$otherDepartmentDoc->hash_id}/view")
            ->assertForbidden();
    }

    public function test_payslip_with_a_non_payroll_entity_pair_fails_closed(): void
    {
        Storage::fake('local');
        $employee = Employee::factory()->create();
        $doc = app(DocumentVaultService::class)->store('BYTES', DocumentType::Payslip, $employee, null, true);
        $owner = $this->seedUser(['payroll.view'], $employee->id);

        $this->actingAs($owner)
            ->get("/api/v1/documents/{$doc->hash_id}/view")
            ->assertForbidden();
    }

    public function test_admin_document_index_requires_the_audit_permission(): void
    {
        Storage::fake('local');
        $this->seedDoc(DocumentType::Invoice);

        $this->actingAs($this->seedUser(['accounting.invoices.view']))
            ->getJson('/api/v1/documents')
            ->assertForbidden();

        $this->actingAs($this->seedUser(['admin.audit_logs.view']))
            ->getJson('/api/v1/documents')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_destroy_requires_the_audit_permission(): void
    {
        Storage::fake('local');
        $doc = $this->seedDoc(DocumentType::Invoice);

        // Holding the document type's own read permission must not imply the
        // right to purge the vault row.
        $this->actingAs($this->seedUser(['accounting.invoices.view']))
            ->deleteJson("/api/v1/documents/{$doc->hash_id}")
            ->assertForbidden();
        $this->assertDatabaseHas('documents', ['id' => $doc->id, 'deleted_at' => null]);

        $this->actingAs($this->seedUser(['admin.audit_logs.view']))
            ->deleteJson("/api/v1/documents/{$doc->hash_id}")
            ->assertNoContent();
        $this->assertSoftDeleted('documents', ['id' => $doc->id]);
    }

    public function test_view_returns_404_when_the_vault_blob_is_missing(): void
    {
        Storage::fake('local');
        $doc = $this->seedDoc(DocumentType::Invoice);
        Storage::disk('local')->delete((string) $doc->file_path);
        $user = $this->seedUser(['accounting.invoices.view']);

        // The row survives for audit history; the byte stream must fail
        // closed rather than emit a zero-length "PDF".
        $this->actingAs($user)
            ->get("/api/v1/documents/{$doc->hash_id}/view")
            ->assertNotFound();
        $this->actingAs($user)
            ->get("/api/v1/documents/{$doc->hash_id}/download")
            ->assertNotFound();
    }

    public function test_entity_document_list_requires_the_employee_documents_permission(): void
    {
        Storage::fake('local');
        $employee = Employee::factory()->create();
        app(DocumentVaultService::class)->store('BYTES', DocumentType::Bir2316, $employee, null, true);

        // An entity-scoped list must never become a second, less-guarded
        // enumeration surface for the admin document index.
        $this->actingAs($this->seedUser(['admin.audit_logs.view']))
            ->getJson("/api/v1/documents/entity/employees/{$employee->hash_id}")
            ->assertForbidden();
    }

    public function test_entity_document_list_is_scoped_to_employees_the_caller_can_see(): void
    {
        Storage::fake('local');
        $target = Employee::factory()->create();
        app(DocumentVaultService::class)->store('BYTES', DocumentType::Bir2316, $target, null, true);

        $stranger = $this->seedUser(
            ['hr.employees.documents.view'],
            Employee::factory()->create()->id,
        );
        $this->actingAs($stranger)
            ->getJson("/api/v1/documents/entity/employees/{$target->hash_id}")
            ->assertForbidden();

        $officer = $this->seedUser(['hr.employees.documents.view', 'hr.employees.view_sensitive']);
        $this->actingAs($officer)
            ->getJson("/api/v1/documents/entity/employees/{$target->hash_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_entity_document_list_rejects_an_unsupported_entity_type(): void
    {
        Storage::fake('local');
        $employee = Employee::factory()->create();
        $user = $this->seedUser(['hr.employees.documents.view', 'hr.employees.view_sensitive']);

        $this->actingAs($user)
            ->getJson("/api/v1/documents/entity/invoices/{$employee->hash_id}")
            ->assertNotFound();
    }

    private function seedDoc(DocumentType $type = DocumentType::Invoice, bool $confidential = false): Document
    {
        $entity = $type === DocumentType::Payslip ? $this->publishedPayroll() : $this->fakeEntity();
        return app(DocumentVaultService::class)->store(
            'BYTES',
            $type,
            $entity,
            null,
            $confidential || $type->isConfidential(),
        );
    }

    private function seedUser(array $permissions, ?int $employeeId = null, ?string $roleSlug = null): User
    {
        $roleSlug ??= 'test-role-'.uniqid();
        $role = Role::firstOrCreate(
            ['slug' => $roleSlug],
            ['name' => 'Test', 'description' => 'Test role'],
        );
        foreach ($permissions as $slug) {
            $perm = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => explode('.', $slug)[0]]);
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }
        return User::factory()->create([
            'role_id' => $role->id,
            'employee_id' => $employeeId,
            'email'   => 'u+'.uniqid().'@example.com',
        ]);
    }

    private function publishedPayroll(?Employee $employee = null): Payroll
    {
        $period = PayrollPeriod::factory()->create();
        $period->forceFill(['status' => 'finalized'])->saveQuietly();

        return Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => ($employee ?? Employee::factory()->create())->id,
        ]);
    }

    private function fakeEntity(): Model
    {
        return new class extends Model {
            protected $table = 'fake_entities';
            public $exists = true;
            public function __construct() { parent::__construct(); $this->id = 1; }
            public function getKey() { return 1; }
            public function getMorphClass(): string { return 'fake_entity'; }
        };
    }
}
