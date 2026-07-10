<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Common\Models\ImportBatch;
use App\Modules\Accounting\Models\Account;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * REC-03 — master-data CSV import pipeline (dry-run, atomic commit, rollback).
 */
class MasterDataImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function csv(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, $body);
        return new UploadedFile($path, 'import.csv', 'text/csv', null, true);
    }

    public function test_dry_run_validates_without_writing(): void
    {
        $csv = "code,name,type,normal_balance\n1000,Cash,asset,debit\nBAD,,asset,debit\n";

        $res = $this->actingAs($this->admin())
            ->post('/api/v1/imports/coa/dry-run', ['file' => $this->csv($csv)])
            ->assertStatus(200);

        $res->assertJsonPath('data.total', 2);
        $res->assertJsonPath('data.valid', 1);
        $this->assertCount(1, $res->json('data.errors'));
        // Nothing persisted.
        $this->assertSame(0, Account::query()->count());
    }

    public function test_commit_is_atomic_all_or_nothing(): void
    {
        // One good + one bad row → commit imports NOTHING.
        $bad = "code,name,type,normal_balance\n1000,Cash,asset,debit\n2000,Bad,notatype,debit\n";
        $this->actingAs($this->admin())
            ->post('/api/v1/imports/coa/commit', ['file' => $this->csv($bad)])
            ->assertStatus(422);
        $this->assertSame(0, Account::query()->count());
        $this->assertSame(0, ImportBatch::query()->count());

        // All-good → commits, creates a batch.
        $good = "code,name,type,normal_balance\n1000,Cash,asset,debit\n3000,Equity,equity,credit\n";
        $res = $this->actingAs($this->admin())
            ->post('/api/v1/imports/coa/commit', ['file' => $this->csv($good)])
            ->assertStatus(201);
        $res->assertJsonPath('data.imported', 2);
        $this->assertSame(2, Account::query()->count());
        $this->assertSame(1, ImportBatch::query()->where('status', 'committed')->count());
    }

    public function test_items_import_resolves_category_and_commits(): void
    {
        $csv = "code,name,item_type,unit_of_measure,category,standard_cost\n"
             ."RM-001,ABS Resin,raw_material,kg,Resins,120.50\n"
             ."FG-001,Wiper Bushing,finished_good,pcs,Molded Parts,5.00\n";

        $this->actingAs($this->admin())
            ->post('/api/v1/imports/items/commit', ['file' => $this->csv($csv)])
            ->assertStatus(201)
            ->assertJsonPath('data.imported', 2);

        $this->assertSame(2, Item::query()->count());
        $item = Item::query()->where('code', 'RM-001')->firstOrFail();
        $this->assertSame('ABS Resin', $item->name);
        $this->assertNotNull($item->category_id);
    }

    public function test_customers_and_vendors_import(): void
    {
        $custCsv = "name,code,email,payment_terms_days\n"
                 ."Toyota Motor Phils,TMP,ap@toyota.test,45\n"
                 ."Honda Cars Phils,HCP,,30\n";
        $this->actingAs($this->admin())
            ->post('/api/v1/imports/customers/commit', ['file' => $this->csv($custCsv)])
            ->assertStatus(201)
            ->assertJsonPath('data.imported', 2);
        $this->assertSame(2, \App\Modules\Accounting\Models\Customer::query()->count());
        $toyota = \App\Modules\Accounting\Models\Customer::query()->where('code', 'TMP')->firstOrFail();
        $this->assertSame(45, (int) $toyota->payment_terms_days);

        $venCsv = "name,contact_person,payment_terms_days\n"
                ."Mitsui Resins,J. Sato,60\n"
                ."Cavite Molds Inc,,30\n";
        $this->actingAs($this->admin())
            ->post('/api/v1/imports/vendors/commit', ['file' => $this->csv($venCsv)])
            ->assertStatus(201)
            ->assertJsonPath('data.imported', 2);
        $this->assertSame(2, \App\Modules\Accounting\Models\Vendor::query()->count());
    }

    public function test_duplicate_customer_name_rejects_whole_batch(): void
    {
        \App\Modules\Accounting\Models\Customer::create(['name' => 'Toyota Motor Phils', 'is_active' => true]);
        $csv = "name\nNissan Phils\nToyota Motor Phils\n"; // 2nd row dup

        $this->actingAs($this->admin())
            ->post('/api/v1/imports/customers/commit', ['file' => $this->csv($csv)])
            ->assertStatus(422);
        // All-or-nothing: Nissan must NOT have been created either.
        $this->assertSame(1, \App\Modules\Accounting\Models\Customer::query()->count());
    }

    public function test_batch_rollback_deletes_imported_records(): void
    {
        $good = "code,name,type,normal_balance\n1000,Cash,asset,debit\n3000,Equity,equity,credit\n";
        $commit = $this->actingAs($this->admin())
            ->post('/api/v1/imports/coa/commit', ['file' => $this->csv($good)])
            ->assertStatus(201);
        $batchHash = $commit->json('data.batch_id');
        $this->assertSame(2, Account::query()->count());

        $this->actingAs($this->admin())
            ->postJson("/api/v1/imports/batches/{$batchHash}/rollback")
            ->assertStatus(200);

        $this->assertSame(0, Account::query()->count());
        $this->assertSame(1, ImportBatch::query()->where('status', 'rolled_back')->count());
    }

    public function test_import_routes_are_permission_gated(): void
    {
        $employee = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);
        $csv = "code,name,type,normal_balance\n1000,Cash,asset,debit\n";

        $this->actingAs($employee)
            ->post('/api/v1/imports/coa/commit', ['file' => $this->csv($csv)])
            ->assertStatus(403);
        $this->assertSame(0, Account::query()->count());
    }
}
