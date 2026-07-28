<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Common\Models\ImportBatch;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
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
        $this->assertSame(2, Customer::query()->count());
        $toyota = Customer::query()->where('code', 'TMP')->firstOrFail();
        $this->assertSame(45, (int) $toyota->payment_terms_days);

        $venCsv = "name,contact_person,payment_terms_days\n"
                ."Mitsui Resins,J. Sato,60\n"
                ."Cavite Molds Inc,,30\n";
        $this->actingAs($this->admin())
            ->post('/api/v1/imports/vendors/commit', ['file' => $this->csv($venCsv)])
            ->assertStatus(201)
            ->assertJsonPath('data.imported', 2);
        $this->assertSame(2, Vendor::query()->count());
    }

    public function test_duplicate_customer_name_rejects_whole_batch(): void
    {
        Customer::create(['name' => 'Toyota Motor Phils', 'is_active' => true]);
        $csv = "name\nNissan Phils\nToyota Motor Phils\n"; // 2nd row dup

        $this->actingAs($this->admin())
            ->post('/api/v1/imports/customers/commit', ['file' => $this->csv($csv)])
            ->assertStatus(422);
        // All-or-nothing: Nissan must NOT have been created either.
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_employees_import_resolves_department_and_position(): void
    {
        $dept = Department::create(['name' => 'Production', 'code' => 'PRD']);

        $csv = "first_name,last_name,birth_date,gender,civil_status,department,position,employment_type,pay_type,date_hired,basic_monthly_salary,sss_no\n"
             ."Juan,Dela Cruz,1990-01-15,male,single,PRD,Operator,regular,monthly,2025-01-06,20000.00,34-1234567-8\n"
             ."Maria,Santos,1992-03-20,female,married,PRD,Line Lead,regular,monthly,2024-06-01,25000.00,34-7654321-0\n";

        $this->actingAs($this->admin())
            ->post('/api/v1/imports/employees/commit', ['file' => $this->csv($csv)])
            ->assertStatus(201)
            ->assertJsonPath('data.imported', 2);

        $this->assertSame(2, Employee::query()->count());
        $juan = Employee::query()->where('last_name', 'Dela Cruz')->firstOrFail();
        $this->assertSame($dept->id, $juan->department_id);
        $this->assertNotNull($juan->employee_no); // generated
        $this->assertSame('34-1234567-8', $juan->sss_no); // encrypted round-trips
        // Position auto-created within the department.
        $this->assertSame(2, Position::query()->where('department_id', $dept->id)->count());
    }

    public function test_employee_import_rejects_bad_enum_and_imports_nothing(): void
    {
        Department::create(['name' => 'Production', 'code' => 'PRD']);
        // Second row has an invalid pay_type → whole batch rejected.
        $csv = "first_name,last_name,birth_date,gender,civil_status,department,position,employment_type,pay_type,date_hired,basic_monthly_salary\n"
             ."Juan,Dela Cruz,1990-01-15,male,single,PRD,Operator,regular,monthly,2025-01-06,20000.00\n"
             ."Bad,Row,1990-01-15,male,single,PRD,Operator,regular,weekly,2025-01-06,20000.00\n";

        $this->actingAs($this->admin())
            ->post('/api/v1/imports/employees/commit', ['file' => $this->csv($csv)])
            ->assertStatus(422);
        $this->assertSame(0, Employee::query()->count());
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

    public function test_batch_rollback_hides_database_details_when_an_imported_record_is_in_use(): void
    {
        $commit = $this->actingAs($this->admin())
            ->post('/api/v1/imports/coa/commit', [
                'file' => $this->csv("code,name,type,normal_balance\n1000,Cash,asset,debit\n"),
            ])
            ->assertCreated();

        $account = Account::query()->where('code', '1000')->firstOrFail();
        $entry = JournalEntry::create([
            'entry_number' => 'JE-IMPORT-ROLLBACK',
            'date' => '2026-01-01',
            'description' => 'References imported master data',
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'line_no' => 1,
            'debit' => 1,
            'credit' => 0,
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/imports/batches/{$commit->json('data.batch_id')}/rollback")
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Cannot roll back — some imported records are already referenced by other data.',
            ]);

        $this->assertDatabaseHas('accounts', ['id' => $account->id]);
        $this->assertDatabaseHas('import_batches', ['id' => ImportBatch::query()->value('id'), 'status' => 'committed']);
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
