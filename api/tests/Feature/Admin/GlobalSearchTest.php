<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M009 — global search (`GET /api/v1/search`).
 *
 * The endpoint had no backend test of any kind, which is how it shipped with an
 * unscoped `DB::table()` query per group behind a single broad `can()` check.
 * The cases below are deliberately weighted toward what the module gets WRONG
 * when nobody is watching: which rows come back, not which columns.
 *
 * Outer boundary (route gates, validation) is cheap to test and cheap to break,
 * so it is covered too — but the load-bearing tests are the row-scope and
 * soft-delete ones, because both defects are invisible to any test that only
 * asserts "200 with some results".
 */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('modules.search', true, 'modules');
    }

    // ------------------------------------------------------------------
    // Outer boundary — route gates and input contract
    // ------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/search?q=ab')->assertUnauthorized();
    }

    public function test_user_without_the_global_search_permission_is_denied(): void
    {
        $user = $this->userWithPermissions(['hr.employees.view']);

        $this->actingAs($user)->getJson('/api/v1/search?q=ab')->assertForbidden();
    }

    public function test_request_is_denied_when_the_search_feature_is_switched_off(): void
    {
        app(SettingsService::class)->set('modules.search', false, 'modules');
        $user = $this->userWithPermissions(['search.global', 'hr.employees.view']);

        $this->actingAs($user)->getJson('/api/v1/search?q=ab')
            ->assertForbidden()
            ->assertJsonPath('code', 'feature_disabled');
    }

    public function test_query_shorter_than_two_characters_is_rejected(): void
    {
        $user = $this->userWithPermissions(['search.global']);

        $this->actingAs($user)->getJson('/api/v1/search?q=a')
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_query_longer_than_the_bound_is_rejected(): void
    {
        $user = $this->userWithPermissions(['search.global']);

        $this->actingAs($user)->getJson('/api/v1/search?q='.str_repeat('a', 121))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_a_module_permission_the_caller_lacks_contributes_no_group(): void
    {
        Employee::factory()->create(['last_name' => 'Zarzuela']);
        // search.global opens the endpoint; it does not open any module.
        $user = $this->userWithPermissions(['search.global']);

        $this->actingAs($user)->getJson('/api/v1/search?q=Zarzuela')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    // ------------------------------------------------------------------
    // M009-F01 — row-level scope must match the module list, not the module
    // ------------------------------------------------------------------

    public function test_department_head_cannot_search_employees_outside_their_department(): void
    {
        $own   = Department::factory()->create(['name' => 'Tooling']);
        $other = Department::factory()->create(['name' => 'Finance']);

        $mine   = Employee::factory()->create(['department_id' => $own->id,   'last_name' => 'Kirisawa']);
        $theirs = Employee::factory()->create(['department_id' => $other->id, 'last_name' => 'Kirisawa']);

        $head = $this->departmentHeadFor($own);

        $labels = $this->labelsFor(
            $this->actingAs($head)->getJson('/api/v1/search?q=Kirisawa')->assertOk()->json('data'),
            'employee',
        );

        // Same surname, two departments. The head holds hr.employees.view but
        // NOT hr.employees.view_sensitive, so exactly one of these is theirs.
        $this->assertContains($mine->first_name.' '.$mine->last_name, $labels);
        $this->assertCount(1, $labels, 'Search returned an employee the department head cannot list.');
        $this->assertNotSame($theirs->department_id, $own->id);
    }

    public function test_employee_search_is_unscoped_for_a_holder_of_the_sensitive_grant(): void
    {
        $a = Department::factory()->create();
        $b = Department::factory()->create();
        Employee::factory()->create(['department_id' => $a->id, 'last_name' => 'Kirisawa']);
        Employee::factory()->create(['department_id' => $b->id, 'last_name' => 'Kirisawa']);

        $hr = $this->userWithPermissions([
            'search.global', 'hr.employees.view', 'hr.employees.view_sensitive',
        ]);

        $labels = $this->labelsFor(
            $this->actingAs($hr)->getJson('/api/v1/search?q=Kirisawa')->assertOk()->json('data'),
            'employee',
        );

        $this->assertCount(2, $labels);
    }

    public function test_employee_search_matches_and_ranks_a_middle_name_only_fixture(): void
    {
        $admin = $this->admin();

        // These decoys match only through a middle-name substring. Their
        // employee numbers sort before the exact fixture, so relevance must
        // promote the exact middle-name match into the five-row window.
        foreach (range(1, 5) as $i) {
            Employee::factory()->create([
                'employee_no' => sprintf('OGM-90000%d', $i),
                'first_name'  => "Decoy{$i}",
                'middle_name' => "Nakamura {$i}",
                'last_name'   => "Person{$i}",
            ]);
        }

        $target = Employee::factory()->create([
            'employee_no' => 'OGM-999999',
            'first_name'  => 'Aiko',
            'middle_name' => 'Nakamura',
            'last_name'   => 'Target',
        ]);

        $items = $this->itemsFor(
            $this->actingAs($admin)->getJson('/api/v1/search?q=nakamura')->assertOk()->json('data'),
            'employee',
        );

        $this->assertCount(5, $items);
        $this->assertSame($target->hash_id, $items[0]['id']);
        $this->assertSame('Aiko Target', $items[0]['label']);
        $this->assertSame('/hr/employees/'.$target->hash_id, $items[0]['url']);
        $this->assertArrayNotHasKey('middle_name', $items[0]);
    }

    public function test_department_head_cannot_search_purchase_orders_outside_their_scope(): void
    {
        $own   = Department::factory()->create();
        $other = Department::factory()->create();
        $head  = $this->departmentHeadFor($own);

        $mine = PurchaseOrder::factory()->create([
            'po_number'           => 'PO-999901-0001',
            'purchase_request_id' => PurchaseRequest::factory()->create(['department_id' => $own->id])->id,
        ]);
        $theirs = PurchaseOrder::factory()->create([
            'po_number'           => 'PO-999901-0002',
            'purchase_request_id' => PurchaseRequest::factory()->create(['department_id' => $other->id])->id,
        ]);

        $labels = $this->labelsFor(
            $this->actingAs($head)->getJson('/api/v1/search?q=PO-999901')->assertOk()->json('data'),
            'purchase_order',
        );

        $this->assertSame([$mine->po_number], $labels);
        $this->assertNotContains($theirs->po_number, $labels);
    }

    public function test_purchase_order_approver_searches_every_purchase_order(): void
    {
        $a = PurchaseOrder::factory()->create(['po_number' => 'PO-999902-0001']);
        $b = PurchaseOrder::factory()->create(['po_number' => 'PO-999902-0002']);

        $approver = $this->userWithPermissions([
            'search.global', 'purchasing.view', 'purchasing.po.approve',
        ]);

        $labels = $this->labelsFor(
            $this->actingAs($approver)->getJson('/api/v1/search?q=PO-999902')->assertOk()->json('data'),
            'purchase_order',
        );

        $this->assertEqualsCanonicalizing([$a->po_number, $b->po_number], $labels);
    }

    public function test_purchase_order_search_falls_back_to_authorship_without_department_reach(): void
    {
        $buyer = $this->userWithPermissions(['search.global', 'purchasing.view']);

        $own   = PurchaseOrder::factory()->create(['po_number' => 'PO-999903-0001', 'created_by' => $buyer->id]);
        $other = PurchaseOrder::factory()->create(['po_number' => 'PO-999903-0002']);

        $labels = $this->labelsFor(
            $this->actingAs($buyer)->getJson('/api/v1/search?q=PO-999903')->assertOk()->json('data'),
            'purchase_order',
        );

        $this->assertSame([$own->po_number], $labels);
        $this->assertNotContains($other->po_number, $labels);
    }

    // ------------------------------------------------------------------
    // M009-F03 — archived rows are not searchable
    // ------------------------------------------------------------------

    public function test_soft_deleted_records_are_excluded_from_every_group(): void
    {
        $admin = $this->admin();

        $employee = Employee::factory()->create(['last_name' => 'Tombstone']);
        $customer = Customer::factory()->create(['name' => 'Tombstone Trading']);
        $vendor   = Vendor::factory()->create(['name' => 'Tombstone Supply']);
        $product  = Product::factory()->create(['name' => 'Tombstone bushing']);
        $item     = Item::factory()->create(['name' => 'Tombstone resin']);
        $so       = SalesOrder::factory()->create(['so_number' => 'SO-999904-0001']);
        $po       = PurchaseOrder::factory()->create(['po_number' => 'PO-999904-0001']);
        $wo       = WorkOrder::factory()->create(['wo_number' => 'WO-999904-0001']);

        // Live first: prove each term actually matches before deleting, or the
        // assertions below would pass on a typo.
        foreach (['Tombstone', 'SO-999904', 'PO-999904', 'WO-999904'] as $term) {
            $this->assertNotEmpty(
                $this->actingAs($admin)->getJson('/api/v1/search?q='.$term)->assertOk()->json('data'),
                "Term {$term} matched nothing even before soft-deleting.",
            );
        }

        foreach ([$employee, $customer, $vendor, $product, $item, $so, $po, $wo] as $model) {
            $model->delete();
            $this->assertNotNull($model->fresh()->deleted_at, class_basename($model).' was hard-deleted.');
        }

        foreach (['Tombstone', 'SO-999904', 'PO-999904', 'WO-999904'] as $term) {
            $this->assertSame(
                [],
                $this->actingAs($admin)->getJson('/api/v1/search?q='.$term)->assertOk()->json('data'),
                "Archived rows are still searchable for term {$term}.",
            );
        }
    }

    // ------------------------------------------------------------------
    // M009-F02 — no sensitive identifiers in the result contract
    // ------------------------------------------------------------------

    public function test_customer_and_vendor_results_never_carry_a_tin(): void
    {
        Customer::factory()->create([
            'name' => 'Tinless Motors', 'contact_person' => null, 'tin' => '123-456-789-000',
        ]);
        Vendor::factory()->create([
            'name' => 'Tinless Polymers', 'contact_person' => null, 'tin' => '987-654-321-000',
        ]);

        // A *view*-only caller: CustomerResource/VendorResource mask the TIN for
        // exactly this permission set, so search must not be the way around them.
        $viewer = $this->userWithPermissions([
            'search.global', 'accounting.customers.view', 'accounting.vendors.view',
        ]);

        $response = $this->actingAs($viewer)->getJson('/api/v1/search?q=Tinless')->assertOk();
        $body = $response->getContent();

        $this->assertStringNotContainsString('123-456-789-000', $body);
        $this->assertStringNotContainsString('987-654-321-000', $body);
        // `tin` is an `encrypted` cast, so a raw select leaks ciphertext rather
        // than a readable number. Neither belongs in a search sublabel.
        $this->assertStringNotContainsString('eyJpdiI6', $body);

        foreach (['customer', 'vendor'] as $type) {
            foreach ($this->itemsFor($response->json('data'), $type) as $item) {
                $this->assertArrayNotHasKey('tin', $item);
                $this->assertNull($item['sublabel'] ?? null, "A {$type} with no contact person still emitted a sublabel.");
            }
        }
    }

    public function test_customer_sublabel_falls_back_to_the_business_code(): void
    {
        Customer::factory()->create([
            'name' => 'Codeful Motors', 'contact_person' => null, 'code' => 'CUS-0042',
        ]);
        $viewer = $this->userWithPermissions(['search.global', 'accounting.customers.view']);

        $items = $this->itemsFor(
            $this->actingAs($viewer)->getJson('/api/v1/search?q=Codeful')->assertOk()->json('data'),
            'customer',
        );

        $this->assertSame('CUS-0042', $items[0]['sublabel']);
    }

    // ------------------------------------------------------------------
    // M009-F04 / F05 — matching semantics and ordering
    // ------------------------------------------------------------------

    public function test_wildcard_characters_are_matched_literally(): void
    {
        $admin = $this->admin();
        Customer::factory()->count(3)->create();

        // Unescaped, `%%` is "every row in every table" and `a_` is "a followed
        // by anything" — the user would be handed the whole database.
        foreach (['%%', 'a_'] as $term) {
            $this->assertSame(
                [],
                $this->actingAs($admin)->getJson('/api/v1/search?q='.urlencode($term))->assertOk()->json('data'),
                "Term {$term} was treated as a wildcard pattern.",
            );
        }
    }

    public function test_a_literal_underscore_still_matches_when_it_is_really_there(): void
    {
        $admin = $this->admin();
        Customer::factory()->create(['name' => 'A_C Plastics']);
        Customer::factory()->create(['name' => 'ABC Plastics']);

        $labels = $this->labelsFor(
            $this->actingAs($admin)->getJson('/api/v1/search?q='.urlencode('A_C'))->assertOk()->json('data'),
            'customer',
        );

        $this->assertSame(['A_C Plastics'], $labels);
    }

    public function test_an_exact_identifier_outranks_incidental_substring_matches(): void
    {
        $admin = $this->admin();

        // Five decoys that all contain the term, plus the exact record. With
        // `limit(5)` and no ORDER BY the exact row could be dropped entirely.
        foreach (range(1, 5) as $i) {
            PurchaseOrder::factory()->create(['po_number' => "PO-999905-000{$i}X"]);
        }
        $exact = PurchaseOrder::factory()->create(['po_number' => 'PO-999905-0001']);

        $labels = $this->labelsFor(
            $this->actingAs($admin)->getJson('/api/v1/search?q=PO-999905-0001')->assertOk()->json('data'),
            'purchase_order',
        );

        $this->assertSame($exact->po_number, $labels[0], 'The exact identifier did not rank first.');
    }

    public function test_ordering_is_stable_across_identical_requests(): void
    {
        $admin = $this->admin();
        foreach (range(1, 8) as $i) {
            PurchaseOrder::factory()->create(['po_number' => "PO-999906-000{$i}"]);
        }

        $first = $this->labelsFor(
            $this->actingAs($admin)->getJson('/api/v1/search?q=PO-999906')->assertOk()->json('data'),
            'purchase_order',
        );
        $second = $this->labelsFor(
            $this->actingAs($admin)->getJson('/api/v1/search?q=PO-999906')->assertOk()->json('data'),
            'purchase_order',
        );

        $this->assertSame($first, $second);
        $this->assertCount(5, $first, 'The per-group window is no longer five rows.');
    }

    // ------------------------------------------------------------------
    // Result contract
    // ------------------------------------------------------------------

    public function test_results_expose_hash_ids_and_hash_based_urls(): void
    {
        $admin = $this->admin();
        $po = PurchaseOrder::factory()->create(['po_number' => 'PO-999907-0001']);

        $items = $this->itemsFor(
            $this->actingAs($admin)->getJson('/api/v1/search?q=PO-999907')->assertOk()->json('data'),
            'purchase_order',
        );

        $this->assertSame($po->hash_id, $items[0]['id']);
        $this->assertNotSame((string) $po->id, (string) $items[0]['id']);
        $this->assertSame('/purchasing/purchase-orders/'.$po->hash_id, $items[0]['url']);
    }

    public function test_status_and_amount_are_emitted_as_plain_strings(): void
    {
        $admin = $this->admin();
        PurchaseOrder::factory()->create(['po_number' => 'PO-999908-0001', 'total_amount' => '1234.50']);

        $items = $this->itemsFor(
            $this->actingAs($admin)->getJson('/api/v1/search?q=PO-999908')->assertOk()->json('data'),
            'purchase_order',
        );

        // Moving off DB::table() means these arrive as enums / decimal casts.
        // The palette feeds `status` to chipVariantForStatus() and `amount` to
        // Number(), so an object here would break both silently.
        $this->assertIsString($items[0]['status']);
        $this->assertIsString($items[0]['amount']);
        $this->assertSame('1234.50', $items[0]['amount']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    /** A user whose role grants exactly $slugs — nothing wider. */
    private function userWithPermissions(array $slugs): User
    {
        $role = Role::create([
            'name'        => 'M009 '.substr(uniqid(), -5),
            'slug'        => 'm009_'.substr(uniqid(), -5),
            'description' => 'M009 test role',
            'is_system'   => false,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', $slugs)->pluck('id')->all(),
        );

        return User::factory()->create(['role_id' => $role->id]);
    }

    /**
     * A seeded department_head bound to an employee in $department.
     *
     * Uses the real seeded role rather than a hand-built one: the whole point of
     * M009-F01 is that this role's *actual* grant set (hr.employees.view and
     * purchasing.view, without the view-all tiers) is what leaked.
     */
    private function departmentHeadFor(Department $department): User
    {
        $employee = Employee::factory()->create(['department_id' => $department->id]);

        return User::factory()->create([
            'role_id'     => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => $employee->id,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function itemsFor(array $groups, string $type): array
    {
        foreach ($groups as $group) {
            if (($group['type'] ?? null) === $type) {
                return $group['items'];
            }
        }

        return [];
    }

    /** @return array<int, string> */
    private function labelsFor(array $groups, string $type): array
    {
        return array_map(fn ($i) => $i['label'], $this->itemsFor($groups, $type));
    }
}
