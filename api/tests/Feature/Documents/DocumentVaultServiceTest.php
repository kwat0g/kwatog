<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Common\Enums\DocumentType;
use App\Common\Models\Document;
use App\Common\Services\DocumentVaultService;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentVaultServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_persists_blob_and_creates_row(): void
    {
        Storage::fake('local');

        $user = $this->makeUser();
        $entity = $this->fakeEntity();

        $vault = app(DocumentVaultService::class);
        $doc = $vault->store('FAKE PDF BYTES', DocumentType::Invoice, $entity, $user);

        $this->assertInstanceOf(Document::class, $doc);
        $this->assertNotEmpty($doc->hash_id);
        $this->assertSame(DocumentType::Invoice, $doc->document_type);
        $this->assertSame('application/pdf', $doc->mime_type);
        $this->assertSame(strlen('FAKE PDF BYTES'), $doc->file_size);
        $this->assertSame(hash('sha256', 'FAKE PDF BYTES'), $doc->checksum_sha256);
        Storage::disk('local')->assertExists($doc->file_path);
    }

    public function test_store_marks_payslips_confidential(): void
    {
        Storage::fake('local');

        $vault = app(DocumentVaultService::class);
        $doc = $vault->store('X', DocumentType::Payslip, $this->fakeEntity(), $this->makeUser());

        $this->assertTrue($doc->is_confidential);
    }

    public function test_store_refuses_empty_bytes(): void
    {
        $this->expectException(\RuntimeException::class);
        app(DocumentVaultService::class)
            ->store('', DocumentType::Invoice, $this->fakeEntity(), $this->makeUser());
    }

    public function test_regenerate_replaces_blob_and_archives_old_row(): void
    {
        Storage::fake('local');

        $vault = app(DocumentVaultService::class);
        $entity = $this->fakeEntity();
        $original = $vault->store('OLD', DocumentType::Invoice, $entity, $this->makeUser());
        $oldPath = $original->file_path;

        $regen = $vault->regenerate($original->fresh(), 'NEW', $this->makeUser());

        $this->assertNotSame($original->id, $regen->id);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($regen->file_path);

        // Original is soft-deleted (audit retained).
        $this->assertSoftDeleted('documents', ['id' => $original->id]);
    }

    public function test_list_for_entity_returns_only_matching_rows(): void
    {
        Storage::fake('local');
        $vault = app(DocumentVaultService::class);

        $a = $this->fakeEntity(11);
        $b = $this->fakeEntity(22);
        $vault->store('A1', DocumentType::Invoice, $a, $this->makeUser());
        $vault->store('A2', DocumentType::Coc,     $a, $this->makeUser());
        $vault->store('B1', DocumentType::Invoice, $b, $this->makeUser());

        $this->assertCount(2, $vault->listForEntity($a));
        $this->assertCount(1, $vault->listForEntity($b));
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'name'  => 'Test User',
            'email' => 'test+'.uniqid().'@example.com',
        ]);
    }

    private function fakeEntity(int $id = 99): Model
    {
        // Use a real model class so morphTo round-trips work; pick Alert
        // (smallest schema) and stub it as if it exists.
        return new class($id) extends Model {
            protected $table = 'fake_entities';
            public $exists = true;

            public function __construct(int $id)
            {
                parent::__construct();
                $this->id = $id;
                $this->employee_no = 'EMP-'.$id;
            }

            public function getKey() { return $this->id; }
            public function getMorphClass(): string { return 'fake_entity'; }
        };
    }
}
