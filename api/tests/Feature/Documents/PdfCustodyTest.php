<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Common\Enums\DocumentType;
use App\Common\Models\AuditLog;
use App\Common\Models\Document;
use App\Common\Services\DocumentVaultService;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 3/4 (custody & audit) — every generated PDF leaves a vault row.
 *
 * Statements, BIR-2307 and the audit-trail export previously streamed bytes
 * with no `documents` audit row. They now route through the vault with the
 * same human filename the direct response used, so URLs and contracts stay
 * stable while custody is complete.
 */
class PdfCustodyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_vault_store_honors_human_filename(): void
    {
        Storage::fake('local');

        $doc = app(DocumentVaultService::class)->store(
            'BYTES',
            DocumentType::TrialBalance,
            $this->userWithPermissions(['accounting.statements.view']),
            null,
            null,
            'TrialBalance-2026-04-01-2026-04-30.pdf',
        );

        self::assertSame('TrialBalance-2026-04-01-2026-04-30.pdf', $doc->file_name);
        Storage::disk('local')->assertExists($doc->file_path);
    }

    public function test_vault_store_sanitizes_traversal_filename(): void
    {
        Storage::fake('local');

        $doc = app(DocumentVaultService::class)->store(
            'BYTES',
            DocumentType::TrialBalance,
            $this->userWithPermissions(['accounting.statements.view']),
            null,
            null,
            '../../evil".pdf',
        );

        self::assertSame('evil.pdf', $doc->file_name);
        self::assertSame('documents/trial_balance/evil.pdf', $doc->file_path);
        Storage::disk('local')->assertExists($doc->file_path);
    }

    public function test_vault_store_dedupes_repeated_human_filename(): void
    {
        Storage::fake('local');
        $vault = app(DocumentVaultService::class);
        $owner = $this->userWithPermissions(['accounting.statements.view']);

        $first = $vault->store('A', DocumentType::TrialBalance, $owner, null, null, 'TrialBalance-x.pdf');
        $second = $vault->store('B', DocumentType::TrialBalance, $owner, null, null, 'TrialBalance-x.pdf');

        self::assertStringStartsWith('TrialBalance-x', $first->file_name);
        self::assertNotSame($first->file_name, $second->file_name);
        self::assertSame('A', $vault->readBytes($first));
        self::assertSame('B', $vault->readBytes($second));
    }

    public function test_trial_balance_pdf_leaves_vault_row(): void
    {
        Storage::fake('local');
        $this->seed(ChartOfAccountsSeeder::class);
        $this->postBalancedLedger();
        $user = $this->userWithPermissions(['accounting.statements.view', 'accounting.statements.export']);

        $resp = $this->actingAs($user)
            ->get('/api/v1/accounting/statements/trial-balance/pdf?from=2026-04-01&to=2026-04-30');

        $resp->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString(
            'TrialBalance-2026-04-01-2026-04-30.pdf',
            (string) $resp->headers->get('Content-Disposition'),
        );

        $doc = Document::query()->where('document_type', DocumentType::TrialBalance)->latest('id')->first();
        self::assertNotNull($doc);
        self::assertSame($user->id, $doc->generated_by);
        // Streamed responses carry no test-visible body; the vault copy is the bytes.
        self::assertStringStartsWith('%PDF', app(DocumentVaultService::class)->readBytes($doc));
    }

    public function test_audit_log_pdf_leaves_vault_row(): void
    {
        Storage::fake('local');
        $admin = $this->userWithPermissions(['admin.audit_logs.view']);
        AuditLog::create([
            'user_id' => $admin->id,
            'action' => 'created',
            'model_type' => 'App\\Modules\\HR\\Models\\Employee',
            'model_id' => 1,
            'old_values' => null,
            'new_values' => ['first_name' => 'Test'],
            'ip_address' => '10.0.0.1',
            'user_agent' => 'TestAgent',
            'created_at' => now(),
        ]);

        $resp = $this->actingAs($admin)->get('/api/v1/admin/audit-logs/export/pdf');

        $resp->assertOk()->assertHeader('Content-Type', 'application/pdf');
        self::assertNotNull(
            Document::query()->where('document_type', DocumentType::AuditTrail)->first(),
            'Audit-trail export must leave a vault row.',
        );
    }

    public function test_bir2307_pdf_leaves_vault_row_filed_against_vendor(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions(['accounting.bills.view']);
        $vendor = Vendor::factory()->create();

        $resp = $this->actingAs($user)
            ->get("/api/v1/vendors/{$vendor->hash_id}/bir-2307/pdf?year=2026&quarter=1");

        $resp->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $doc = Document::query()->where('document_type', DocumentType::Bir2307)->first();
        self::assertNotNull($doc);
        self::assertSame($vendor->id, $doc->entity_id);
    }

    public function test_quality_policy_renders_branded_chrome_without_vault_row(): void
    {
        $before = Document::query()->count();

        $resp = $this->get('/api/v1/landing/quality-policy', ['Accept' => 'application/pdf']);

        $resp->assertOk();
        $this->assertStringContainsString('application/pdf', $resp->headers->get('Content-Type', ''));
        $this->assertStringContainsString(
            'ogami-quality-policy.pdf',
            (string) $resp->headers->get('Content-Disposition'),
        );
        self::assertSame($before, Document::query()->count(), 'Public policy stays vault-free (no owning entity).');
    }

    public function test_journal_entry_vault_row_requires_journal_permission(): void
    {
        Storage::fake('local');
        $doc = app(DocumentVaultService::class)->store('BYTES', DocumentType::JournalEntry, $this->fakeEntity());

        // accounting.bills.view must NOT open a journal-entry row (was the mapping).
        $this->actingAs($this->userWithPermissions(['accounting.bills.view']))
            ->get("/api/v1/documents/{$doc->hash_id}/view")
            ->assertForbidden();

        $this->actingAs($this->userWithPermissions(['accounting.journal.view']))
            ->get("/api/v1/documents/{$doc->hash_id}/view")
            ->assertOk();
    }

    public function test_bulk_print_is_rate_limited(): void
    {
        $user = $this->userWithPermissions(['admin.print.bulk']);

        $statuses = [];
        for ($i = 0; $i < 11; $i++) {
            $statuses[] = $this->actingAs($user)
                ->postJson('/api/v1/print/bulk', ['type' => 'invoice', 'ids' => ['nope']])
                ->getStatusCode();
        }

        self::assertContains(429, $statuses, 'Bulk render must be throttled.');
    }

    /* ─── helpers ─────────────────────────────────────────────── */

    private function userWithPermissions(array $slugs): User
    {
        $role = Role::create([
            'name' => 'Custody '.uniqid(),
            'slug' => 'custody-'.uniqid(),
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id')->all());

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function fakeEntity(): Model
    {
        return new class extends Model
        {
            protected $table = 'fake_entities';

            public $exists = true;

            public function __construct()
            {
                parent::__construct();
                $this->id = 1;
            }

            public function getKey()
            {
                return 1;
            }

            public function getMorphClass(): string
            {
                return 'fake_entity';
            }
        };
    }

    private function postBalancedLedger(): void
    {
        $maker = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $checker = User::factory()->create(['role_id' => $maker->role_id]);
        $journalEntries = app(JournalEntryService::class);
        $code = static fn (string $code): string => Account::query()->where('code', $code)->firstOrFail()->hash_id;

        $capital = $journalEntries->create([
            'date' => '2026-04-01',
            'description' => 'Capital infusion',
            'lines' => [
                ['account_id' => $code('1020'), 'debit' => '12345678.90', 'credit' => '0'],
                ['account_id' => $code('3010'), 'debit' => '0', 'credit' => '12345678.90'],
            ],
        ], $maker);
        $journalEntries->post($capital, $checker);
    }
}
