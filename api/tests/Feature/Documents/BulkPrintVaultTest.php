<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Common\Enums\DocumentType;
use App\Common\Models\Document;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * M011-F05 — bulk print is an official company PDF and must therefore leave
 * the same audit trail as every other generated document: a `documents` row
 * with a checksum, a blob on the private disk, and a re-download that goes
 * back through the central permission check.
 */
class BulkPrintVaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_print_stores_a_vault_row_and_streams_from_it(): void
    {
        Storage::fake('local');
        $invoice = Invoice::factory()->create();
        $user = $this->userWith(['admin.print.bulk']);

        $response = $this->actingAs($user)->post('/api/v1/print/bulk', [
            'type' => 'invoice',
            'ids' => [$invoice->hash_id],
        ]);

        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        $document = Document::query()->latest('id')->first();
        $this->assertNotNull($document);
        $this->assertSame(DocumentType::BulkPdf, $document->document_type);
        $this->assertSame($user->id, $document->generated_by);
        $this->assertNotNull($document->checksum_sha256);
        $this->assertGreaterThan(0, (int) $document->file_size);
        Storage::disk('local')->assertExists((string) $document->file_path);
    }

    public function test_bulk_print_requires_the_bulk_print_permission(): void
    {
        Storage::fake('local');
        $invoice = Invoice::factory()->create();

        $this->actingAs($this->userWith(['accounting.invoices.view']))
            ->postJson('/api/v1/print/bulk', ['type' => 'invoice', 'ids' => [$invoice->hash_id]])
            ->assertForbidden();

        $this->assertSame(0, Document::query()->count());
    }

    public function test_a_stored_bulk_pdf_is_only_downloadable_with_the_bulk_print_permission(): void
    {
        Storage::fake('local');
        $invoice = Invoice::factory()->create();
        $printer = $this->userWith(['admin.print.bulk']);

        $this->actingAs($printer)->post('/api/v1/print/bulk', [
            'type' => 'invoice',
            'ids' => [$invoice->hash_id],
        ])->assertOk();

        $document = Document::query()->latest('id')->firstOrFail();

        // Being able to read the underlying invoices is not the same grant as
        // being able to pull the operator's bulk artifact back out of the vault.
        $this->actingAs($this->userWith(['accounting.invoices.view']))
            ->get("/api/v1/documents/{$document->hash_id}/download")
            ->assertForbidden();

        $this->actingAs($printer)
            ->get("/api/v1/documents/{$document->hash_id}/download")
            ->assertOk();
    }

    /** @param array<int, string> $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'name' => 'Bulk print test role '.uniqid(),
            'slug' => 'bulk-print-'.uniqid(),
            'description' => 'Bulk print vault test role',
        ]);
        foreach ($permissions as $slug) {
            $permission = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'module' => explode('.', $slug)[0]],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
