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
use Tests\TestCase;

class DocumentAcknowledgmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function qcInspector(): User
    {
        return User::factory()->create([
            'role_id'   => Role::query()->where('slug', 'qc_inspector')->value('id'),
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: ControlledDocument, 1: DocumentRevision, 2: DocumentAcknowledgment}
     */
    private function seedDocWithRevisionAndAck(User $user, string $code = 'SOP-QC-AK1'): array
    {
        $doc = ControlledDocument::create([
            'code' => $code, 'title' => 'Ack Doc', 'category' => 'sop',
            'assignee_role' => 'qc_inspector', 'is_active' => true,
        ]);
        $rev = DocumentRevision::create([
            'document_id'     => $doc->id,
            'revision_number' => 1,
            'effective_date'  => now()->toDateString(),
            'change_reason'   => 'Initial',
            'file_path'       => 'controlled-documents/'.$doc->id.'/fake.pdf',
            'file_name'       => 'fake.pdf',
            'file_size'       => 100,
            'mime_type'       => 'application/pdf',
            'published_at'    => now(),
            'is_current'      => true,
        ]);
        $ack = DocumentAcknowledgment::forceCreate([
            'document_revision_id' => $rev->id,
            'user_id'              => $user->id,
            'acknowledged_at'      => null,
        ]);
        return [$doc, $rev, $ack];
    }

    public function test_pending_lists_only_unacknowledged_for_session_user(): void
    {
        $user = $this->qcInspector();
        [, $rev, $ack] = $this->seedDocWithRevisionAndAck($user);

        // A separate user's ack must NOT leak.
        $other = $this->qcInspector();
        DocumentAcknowledgment::forceCreate([
            'document_revision_id' => $rev->id,
            'user_id'              => $other->id,
            'acknowledged_at'      => null,
        ]);

        $resp = $this->actingAs($user)->getJson('/api/v1/self-service/documents/pending');
        $resp->assertOk()->assertJsonCount(1, 'data')
             ->assertJsonPath('data.0.id', $ack->hash_id);
    }

    public function test_user_can_acknowledge_their_own_pending_row(): void
    {
        $user = $this->qcInspector();
        [, $rev, $ack] = $this->seedDocWithRevisionAndAck($user);

        $resp = $this->actingAs($user)->postJson("/api/v1/self-service/documents/{$rev->hash_id}/acknowledge");
        $resp->assertOk()->assertJsonPath('data.acknowledged', true);

        $this->assertNotNull($ack->fresh()->acknowledged_at);
    }

    public function test_other_user_without_ack_row_gets_403(): void
    {
        $owner = $this->qcInspector();
        [, $rev, $ack] = $this->seedDocWithRevisionAndAck($owner);

        // The intruder has NO ack row for $rev — service throws 403.
        $intruder = $this->qcInspector();
        $resp = $this->actingAs($intruder)->postJson("/api/v1/self-service/documents/{$rev->hash_id}/acknowledge");
        $resp->assertStatus(403);

        $this->assertNull($ack->fresh()->acknowledged_at);
    }

    public function test_reacknowledge_is_idempotent(): void
    {
        $user = $this->qcInspector();
        [, $rev, $ack] = $this->seedDocWithRevisionAndAck($user);

        $this->actingAs($user)->postJson("/api/v1/self-service/documents/{$rev->hash_id}/acknowledge")->assertOk();
        $firstStamp = $ack->fresh()->acknowledged_at;

        // Second call must succeed and NOT mutate the timestamp.
        sleep(1);
        $this->actingAs($user)->postJson("/api/v1/self-service/documents/{$rev->hash_id}/acknowledge")->assertOk();
        $secondStamp = $ack->fresh()->acknowledged_at;

        $this->assertEquals($firstStamp->toISOString(), $secondStamp->toISOString());
    }
}
