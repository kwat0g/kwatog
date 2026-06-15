<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Models\ControlledDocument;
use App\Modules\Quality\Models\DocumentAcknowledgment;
use App\Modules\Quality\Models\DocumentRevision;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id'   => Role::query()->where('slug', 'qc_inspector')->value('id'),
            'is_active' => true,
        ]);
    }

    private function newDoc(string $assignee = 'qc_inspector'): ControlledDocument
    {
        return ControlledDocument::create([
            'code'          => 'SOP-QC-'.random_int(1000, 9999),
            'title'         => 'Test Doc',
            'category'      => 'sop',
            'assignee_role' => $assignee,
            'is_active'     => true,
        ]);
    }

    public function test_first_revision_is_marked_current_file_stored_and_ack_rows_seeded(): void
    {
        $admin = $this->admin();
        $doc   = $this->newDoc();

        // Seed N qc_inspector users (in addition to the admin who is also qc_inspector)
        // and 1 employee user who must NOT receive an ack row.
        $qc1 = User::factory()->create([
            'role_id'   => Role::query()->where('slug', 'qc_inspector')->value('id'),
            'is_active' => true,
        ]);
        $qc2 = User::factory()->create([
            'role_id'   => Role::query()->where('slug', 'qc_inspector')->value('id'),
            'is_active' => true,
        ]);
        $emp = User::factory()->create([
            'role_id'   => Role::query()->where('slug', 'employee')->value('id'),
            'is_active' => true,
        ]);

        $resp = $this->actingAs($admin)->post(
            "/api/v1/quality/documents/{$doc->hash_id}/revisions",
            [
                'effective_date' => now()->toDateString(),
                'change_reason'  => 'Initial issue',
                'file'           => UploadedFile::fake()->create('sop.pdf', 200, 'application/pdf'),
            ],
            ['Accept' => 'application/json']
        );

        $resp->assertCreated()
             ->assertJsonPath('data.revision_number', 1)
             ->assertJsonPath('data.is_current', true);

        $this->assertSame(1, DocumentRevision::where('document_id', $doc->id)
            ->where('is_current', true)->count());

        $rev = DocumentRevision::where('document_id', $doc->id)->first();
        Storage::disk('local')->assertExists($rev->file_path);

        // admin + qc1 + qc2 = 3 active qc_inspector users → 3 ack rows.
        foreach ([$admin, $qc1, $qc2] as $u) {
            $this->assertDatabaseHas('document_acknowledgments', [
                'document_revision_id' => $rev->id,
                'user_id'              => $u->id,
                'acknowledged_at'      => null,
            ]);
        }
        // The lone employee gets no row.
        $this->assertDatabaseMissing('document_acknowledgments', [
            'document_revision_id' => $rev->id,
            'user_id'              => $emp->id,
        ]);

        // Publishing IS a review event — last_reviewed_at must be stamped.
        $this->assertNotNull($doc->fresh()->last_reviewed_at);
    }

    public function test_second_revision_flips_prior_is_current_to_false(): void
    {
        $admin = $this->admin();
        $doc   = $this->newDoc();

        $this->actingAs($admin)->post(
            "/api/v1/quality/documents/{$doc->hash_id}/revisions",
            [
                'effective_date' => now()->toDateString(),
                'change_reason'  => 'Initial',
                'file'           => UploadedFile::fake()->create('v1.pdf', 100, 'application/pdf'),
            ],
            ['Accept' => 'application/json']
        )->assertCreated();

        $this->actingAs($admin)->post(
            "/api/v1/quality/documents/{$doc->hash_id}/revisions",
            [
                'effective_date' => now()->toDateString(),
                'change_reason'  => 'Updated tolerances',
                'file'           => UploadedFile::fake()->create('v2.pdf', 100, 'application/pdf'),
            ],
            ['Accept' => 'application/json']
        )->assertCreated()
         ->assertJsonPath('data.revision_number', 2)
         ->assertJsonPath('data.is_current', true);

        // Exactly one current row per document_id.
        $this->assertSame(1, DocumentRevision::where('document_id', $doc->id)
            ->where('is_current', true)->count());

        // Rev #1 must be is_current=false; rev #2 is_current=true.
        $rev1 = DocumentRevision::where('document_id', $doc->id)
            ->where('revision_number', 1)->first();
        $rev2 = DocumentRevision::where('document_id', $doc->id)
            ->where('revision_number', 2)->first();
        $this->assertFalse((bool) $rev1->is_current);
        $this->assertTrue((bool) $rev2->is_current);
    }

    public function test_ack_row_count_matches_assignee_role_users(): void
    {
        $admin = $this->admin();
        $doc   = $this->newDoc('qc_inspector');

        // 4 more qc_inspectors active, 1 inactive (must be excluded).
        for ($i = 0; $i < 4; $i++) {
            User::factory()->create([
                'role_id'   => Role::query()->where('slug', 'qc_inspector')->value('id'),
                'is_active' => true,
            ]);
        }
        User::factory()->create([
            'role_id'   => Role::query()->where('slug', 'qc_inspector')->value('id'),
            'is_active' => false,
        ]);

        // Add a maintenance_tech and an employee — must NOT get rows.
        User::factory()->create([
            'role_id'   => Role::firstOrCreate(
                ['slug' => 'maintenance_tech'],
                ['name' => 'Maintenance Tech']
            )->id,
            'is_active' => true,
        ]);
        User::factory()->create([
            'role_id'   => Role::query()->where('slug', 'employee')->value('id'),
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post(
            "/api/v1/quality/documents/{$doc->hash_id}/revisions",
            [
                'effective_date' => now()->toDateString(),
                'change_reason'  => 'Issue',
                'file'           => UploadedFile::fake()->create('sop.pdf', 50, 'application/pdf'),
            ],
            ['Accept' => 'application/json']
        )->assertCreated();

        $rev = DocumentRevision::where('document_id', $doc->id)->first();

        $expectedActiveQc = User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', 'qc_inspector'))
            ->count();

        $this->assertSame(
            $expectedActiveQc,
            DocumentAcknowledgment::where('document_revision_id', $rev->id)->count(),
            'ack row count must match the number of active qc_inspector users',
        );
    }

    public function test_publish_does_not_spawn_ack_rows_for_other_roles(): void
    {
        $admin = $this->admin();
        $doc   = $this->newDoc('qc_inspector');

        $other = User::factory()->create([
            'role_id'   => Role::firstOrCreate(
                ['slug' => 'maintenance_tech'],
                ['name' => 'Maintenance Tech']
            )->id,
            'is_active' => true,
        ]);
        $emp = User::factory()->create([
            'role_id'   => Role::query()->where('slug', 'employee')->value('id'),
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post(
            "/api/v1/quality/documents/{$doc->hash_id}/revisions",
            [
                'effective_date' => now()->toDateString(),
                'change_reason'  => 'Issue',
                'file'           => UploadedFile::fake()->create('sop.pdf', 50, 'application/pdf'),
            ],
            ['Accept' => 'application/json']
        )->assertCreated();

        $rev = DocumentRevision::where('document_id', $doc->id)->first();

        foreach ([$other, $emp] as $u) {
            $this->assertDatabaseMissing('document_acknowledgments', [
                'document_revision_id' => $rev->id,
                'user_id'              => $u->id,
            ]);
        }
    }
}
