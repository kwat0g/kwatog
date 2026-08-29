<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
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
use App\Modules\Quality\Models\NonConformanceReport;
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

    public function test_padded_one_character_query_is_rejected_after_trimming(): void
    {
        $user = $this->userWithPermissions(['search.global']);

        $this->actingAs($user)->getJson('/api/v1/search?q='.urlencode(' a '))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_whitespace_only_query_is_rejected_after_trimming(): void
    {
        $user = $this->userWithPermissions(['search.global']);

        $this->actingAs($user)->getJson('/api/v1/search?q='.urlencode('   '))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_valid_padded_query_is_normalized_for_search_and_response(): void
    {
        $employee = Employee::factory()->create(['last_name' => 'PaddedQuery']);
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/search?q='.urlencode('  PaddedQuery  '))
            ->assertOk()
            ->assertJsonPath('query', 'PaddedQuery');

        $this->assertContains(
            $employee->first_name.' '.$employee->last_name,
            $this->labelsFor($response->json('data'), 'employee'),
        );
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
    // M009-F09 — a disabled module's records are not searchable
    // ------------------------------------------------------------------

    /**
     * Group type => the module toggle(s) that own it, mirroring
     * GlobalSearchService::GROUP_FEATURES. Duplicated on purpose: if the service
     * map is edited without a reason, this table disagrees and the tests below
     * fail, which is the point. ANY-of — `customer` is reachable under either
     * Accounting or the delegated CRM route.
     */
    private const GROUP_FEATURES = [
        'employee'       => ['hr'],
        'sales_order'    => ['crm'],
        'purchase_order' => ['purchasing'],
        'work_order'     => ['production'],
        'invoice'        => ['accounting'],
        'bill'           => ['accounting'],
        'product'        => ['crm'],
        'item'           => ['inventory'],
        'customer'       => ['accounting', 'crm'],
        'vendor'         => ['accounting'],
        'ncr'            => ['quality'],
    ];

    private const OWNING_FEATURES = ['hr', 'crm', 'purchasing', 'production', 'accounting', 'inventory', 'quality'];

    public function test_switching_a_module_off_hides_exactly_its_own_search_groups(): void
    {
        $admin = $this->admin();
        $this->seedOnePerGroup(Department::factory()->create(), $admin);
        $settings = app(SettingsService::class);

        foreach (self::OWNING_FEATURES as $off) {
            foreach (self::OWNING_FEATURES as $f) {
                $settings->set("modules.{$f}", $f !== $off, 'modules');
            }
            $settings->flushCache();

            // With only $off disabled, a group survives iff ANY owner is still on.
            $expected = array_keys(array_filter(
                self::GROUP_FEATURES,
                fn (array $owners) => $owners !== [$off],
            ));
            $actual = array_map(
                fn ($g) => $g['type'],
                $this->actingAs($admin)->getJson('/api/v1/search?q='.self::MARKER)->assertOk()->json('data'),
            );

            $this->assertEqualsCanonicalizing(
                $expected,
                $actual,
                "With modules.{$off} disabled, the searchable groups are wrong.",
            );
        }
    }

    public function test_every_module_off_leaves_a_system_admin_with_no_searchable_records(): void
    {
        // The permission gate cannot catch this: hasPermission() short-circuits
        // to true for system_admin, so the feature gate is the only thing
        // standing between a fully switched-off system and eleven result groups.
        $admin = $this->admin();
        $this->seedOnePerGroup(Department::factory()->create(), $admin);

        $settings = app(SettingsService::class);
        foreach (self::OWNING_FEATURES as $f) {
            $settings->set("modules.{$f}", false, 'modules');
        }
        $settings->flushCache();

        $this->actingAs($admin)->getJson('/api/v1/search?q='.self::MARKER)
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_customers_remain_searchable_while_either_owning_module_is_enabled(): void
    {
        $admin = $this->admin();
        $this->seedOnePerGroup(Department::factory()->create(), $admin);
        $settings = app(SettingsService::class);

        foreach ([['accounting' => false, 'crm' => true], ['accounting' => true, 'crm' => false]] as $combo) {
            foreach ($combo as $f => $on) {
                $settings->set("modules.{$f}", $on, 'modules');
            }
            $settings->flushCache();

            $this->assertNotEmpty(
                $this->itemsFor($this->actingAs($admin)->getJson('/api/v1/search?q='.self::MARKER)->assertOk()->json('data'), 'customer'),
                'Customers are reachable under both Accounting and the delegated CRM route, so one toggle must not hide them: '.json_encode($combo),
            );
        }

        // Only when BOTH owners are off does the group disappear.
        $settings->set('modules.accounting', false, 'modules');
        $settings->set('modules.crm', false, 'modules');
        $settings->flushCache();

        $this->assertSame(
            [],
            $this->itemsFor($this->actingAs($admin)->getJson('/api/v1/search?q='.self::MARKER)->assertOk()->json('data'), 'customer'),
        );
    }

    // ------------------------------------------------------------------
    // Permission matrix — search.global opens the endpoint, nothing else
    // ------------------------------------------------------------------

    /**
     * Every seeded role × every searchable group, measured through HTTP.
     *
     * `search.global` is ONE permission held by seven roles, and this endpoint
     * queries eleven tables. The failure mode it exists to prevent is a group
     * added later without a permission check, which would be invisible to every
     * other test here: they all assert one group at a time. This asserts the
     * whole row — a caller sees a group if and only if it holds that group's
     * gate — for each seeded role, against fixtures deliberately placed IN the
     * caller's row scope so the permission dimension is the only variable.
     */
    public function test_no_seeded_role_sees_a_group_it_lacks_the_permission_for(): void
    {
        $gates = [
            'employee'       => 'hr.employees.view',
            'sales_order'    => 'crm.sales_orders.view',
            'purchase_order' => 'purchasing.view',
            'work_order'     => 'production.work_orders.view',
            'invoice'        => 'accounting.invoices.view',
            'bill'           => 'accounting.bills.view',
            'product'        => 'crm.products.view',
            'item'           => 'inventory.view',
            'customer'       => 'accounting.customers.view',
            'vendor'         => 'accounting.vendors.view',
            'ncr'            => 'quality.ncr.view',
        ];
        $this->assertSame(
            array_keys(self::GROUP_FEATURES),
            array_keys($gates),
            'A searchable group was added or removed without updating this matrix.',
        );

        $department = Department::factory()->create();
        $checked = 0;

        foreach (Role::query()->orderBy('slug')->pluck('slug') as $slug) {
            $user = User::factory()->create([
                'role_id'     => Role::query()->where('slug', $slug)->value('id'),
                'employee_id' => Employee::factory()->create(['department_id' => $department->id])->id,
            ]);
            $this->seedOnePerGroup($department, $user);

            $response = $this->actingAs($user)->getJson('/api/v1/search?q='.self::MARKER);
            $checked++;

            if (! $user->hasPermission('search.global')) {
                $response->assertForbidden();
            } else {
                $expected = array_keys(array_filter($gates, fn ($gate) => $user->hasPermission($gate)));
                $this->assertEqualsCanonicalizing(
                    $expected,
                    array_map(fn ($g) => $g['type'], $response->assertOk()->json('data')),
                    "Role {$slug} sees a different group set than its grants allow.",
                );
            }
        }

        // Guard against the seeder shrinking and the loop silently asserting nothing.
        $this->assertGreaterThanOrEqual(13, $checked);
    }

    public function test_global_search_permission_alone_opens_no_module(): void
    {
        // maintenance_tech is the live example: it holds search.global and not
        // one of the eleven gates, so its record search is legitimately empty.
        $bare = $this->userWithPermissions(['search.global']);
        $this->seedOnePerGroup(Department::factory()->create(), $bare);

        $this->actingAs($bare)->getJson('/api/v1/search?q='.self::MARKER)
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private const MARKER = 'Zenkoku';

    private int $fixtureRun = 0;

    /**
     * One record per searchable group, all matching self::MARKER, all inside
     * $author's row scope (same department, authored by them) so a row-scope
     * miss cannot be mistaken for a permission miss.
     */
    private function seedOnePerGroup(Department $department, User $author): void
    {
        $k = ++$this->fixtureRun;
        $marker = self::MARKER;

        Employee::factory()->create(['department_id' => $department->id, 'last_name' => $marker]);

        // Counterparties named WITHOUT the marker: a transaction must be found
        // through its own identifier, not through a joined name, or the groups
        // stop being independent.
        $customer = Customer::factory()->create(['name' => "Counterparty C{$k}"]);
        $vendor   = Vendor::factory()->create(['name' => "Counterparty V{$k}"]);

        SalesOrder::factory()->create(['so_number' => "SO-{$marker}{$k}", 'customer_id' => $customer->id]);
        PurchaseOrder::factory()->create([
            'po_number'           => "PO-{$marker}{$k}",
            'vendor_id'           => $vendor->id,
            'purchase_request_id' => PurchaseRequest::factory()->create(['department_id' => $department->id])->id,
            'created_by'          => $author->id,
        ]);
        WorkOrder::factory()->create(['wo_number' => "WO-{$marker}{$k}"]);
        Invoice::factory()->create(['invoice_number' => "INV-{$marker}{$k}", 'customer_id' => $customer->id]);
        Bill::factory()->create(['bill_number' => "BL-{$marker}{$k}", 'vendor_id' => $vendor->id]);
        Product::factory()->create(['name' => "{$marker} bushing {$k}"]);
        Item::factory()->create(['name' => "{$marker} resin {$k}"]);
        Customer::factory()->create(['name' => "{$marker} Motors {$k}"]);
        Vendor::factory()->create(['name' => "{$marker} Supply {$k}"]);
        NonConformanceReport::factory()->create(['ncr_number' => "NCR-{$marker}{$k}"]);
    }

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
