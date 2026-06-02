<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Common\Enums\DocumentType;
use App\Common\Models\Document;
use App\Common\Services\DocumentVaultService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
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

    private function seedDoc(DocumentType $type = DocumentType::Invoice, bool $confidential = false): Document
    {
        $entity = $this->fakeEntity();
        return app(DocumentVaultService::class)->store(
            'BYTES',
            $type,
            $entity,
            null,
            $confidential || $type->isConfidential(),
        );
    }

    private function seedUser(array $permissions): User
    {
        $role = Role::firstOrCreate(
            ['slug' => 'test-role-'.uniqid()],
            ['name' => 'Test', 'description' => 'Test role'],
        );
        foreach ($permissions as $slug) {
            $perm = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => explode('.', $slug)[0]]);
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }
        return User::factory()->create([
            'role_id' => $role->id,
            'email'   => 'u+'.uniqid().'@example.com',
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
