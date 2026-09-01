<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Quality\Enums\PpapStatus;
use App\Modules\Quality\Models\PpapSubmission;
use App\Modules\Quality\Services\PpapService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M058 AUDIT PROBE 3 — leftovers: PUT contract, bulk-expiry audit trail,
 * evidence/attachment surface, restore route, options endpoints.
 * Scratch file: delete before release.
 */
class ZzM058Probe3Test extends TestCase
{
    use RefreshDatabase;

    private User $qc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->qc = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
    }

    private function make(): PpapSubmission
    {
        $this->actingAs($this->qc)->postJson('/api/v1/quality/ppap', [
            'vendor_id'  => Vendor::factory()->create()->hash_id,
            'item_id'    => Item::factory()->create()->hash_id,
            'ppap_level' => '3',
        ])->assertStatus(201);

        return PpapSubmission::query()->latest('id')->firstOrFail();
    }

    /** PUT contract: product_id is `integer` on update but a hash on create. */
    public function test_probe_put_contract_divergence(): void
    {
        $p = $this->make();
        $product = \App\Modules\CRM\Models\Product::factory()->create();

        $cases = [
            'raw pk integer'   => ['product_id' => $product->id],
            'the real hash id' => ['product_id' => $product->hash_id],
            'nonexistent pk'   => ['product_id' => 987654],
            'level banana'     => ['ppap_level' => 'banana'],
            'level 7'          => ['ppap_level' => '7'],
            'level 1e0'        => ['ppap_level' => '1e0'],
        ];
        foreach ($cases as $label => $body) {
            $status = 'threw';
            try {
                DB::transaction(function () use ($p, $body, &$status) {
                    $status = (string) $this->actingAs($this->qc)
                        ->putJson("/api/v1/quality/ppap/{$p->hash_id}", $body)->status();
                });
            } catch (\Throwable $e) {
                $status = 'threw '.(preg_match('/SQLSTATE\[(\w+)\]/', $e->getMessage(), $m) ? $m[1] : '');
            }
            try {
                $row = DB::table('ppap_submissions')->where('id', $p->id)->first(['ppap_level', 'product_id']);
            } catch (\Throwable $e) {
                fwrite(STDERR, sprintf("\n[Q1] PUT %-17s %-24s => %-10s (read-back blocked by aborted txn)",
                    $label, json_encode($body), $status));
                continue;
            }
            fwrite(STDERR, sprintf(
                "\n[Q1] PUT %-17s %-24s => %-10s stored level=%s product_id=%s",
                $label, json_encode($body), $status, $row->ppap_level, var_export($row->product_id, true),
            ));
        }
        fwrite(STDERR, "\n");
        $this->assertTrue(true);
    }

    /** Bulk expiry: does the mass update leave any audit trail at all? */
    public function test_probe_bulk_expiry_audit_trail(): void
    {
        $p = $this->make();
        DB::table('ppap_submissions')->where('id', $p->id)->update([
            'status' => PpapStatus::Approved->value,
            'approved_by' => $this->qc->id,
            'approved_at' => '2023-01-01 00:00:00',
            'expires_at' => '2024-01-01 00:00:00',
        ]);

        // audit_logs cannot be truncated: a PostgreSQL trigger raises P0001
        // ("Audit logs are immutable"). Take a high-water mark instead.
        $before = (int) DB::table('audit_logs')->max('id');
        $moved = app(PpapService::class)->expireOverdue();
        $new = DB::table('audit_logs')->where('id', '>', $before)
            ->get(['action', 'model_type', 'model_id'])->toArray();

        fwrite(STDERR, "\n[Q2] expireOverdue() moved {$moved} row(s); NEW audit_logs rows = "
            .count($new)."\n");
        fwrite(STDERR, '[Q2] rows: '.json_encode($new)."\n");
        fwrite(STDERR, "[Q2] audit_logs itself IS trigger-protected: DELETE raises "
            ."P0001 'Audit logs are immutable' (prevent_audit_log_modification) — the\n"
            ."     journal-ledger precedent exists in this database and works.\n");
        fwrite(STDERR, '[Q2] status column now = '
            .DB::table('ppap_submissions')->where('id', $p->id)->value('status')."\n");
        fwrite(STDERR, "[Q2] scheduled callers of expireOverdue(): 0 (grep of app/ routes/ database/)\n");

        $this->assertSame(1, $moved);
    }

    /** Evidence / attachment surface: is there one at all? */
    public function test_probe_evidence_surface(): void
    {
        $uploadish = collect(app('router')->getRoutes())
            ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())
            ->filter(fn ($u) => str_contains($u, 'ppap') || str_contains($u, 'shipment-lot'))
            ->values()->all();
        fwrite(STDERR, "\n[Q3] every route in the PPAP + shipment-lot surface:\n  "
            .implode("\n  ", $uploadish)."\n");
        fwrite(STDERR, "[Q3] upload routes: 0 | download/serve routes: 0 | delete-element routes: 0\n");
        fwrite(STDERR, "[Q3] create-element routes: 0 — only PATCH on the one auto-created PSW row\n");
        fwrite(STDERR, '[Q3] restore routes in the whole Quality module: '
            .collect(app('router')->getRoutes())->filter(
                fn ($r) => str_contains($r->uri(), 'quality') && str_contains($r->uri(), 'restore'),
            )->count()."\n");

        // document_path is a bare string with no storage contract: prove it round-trips verbatim
        $p = $this->make();
        $el = $p->elements()->firstOrFail();
        $this->actingAs($this->qc)->patchJson(
            "/api/v1/quality/ppap/{$p->hash_id}/elements/{$el->hash_id}",
            ['document_path' => '../../../../etc/passwd'],
        )->assertOk();
        $shown = $this->actingAs($this->qc)->getJson("/api/v1/quality/ppap/{$p->hash_id}")
            ->json('data.elements.0.document_path');
        fwrite(STDERR, "[Q3] traversal path stored AND echoed back to an internal caller = "
            .var_export($shown, true)."\n");
        fwrite(STDERR, "[Q3] no file is ever written, so nothing to serve — the field is an "
            ."unvalidated free-text pointer\n");

        $this->assertTrue(true);
    }

    /** Cross-tenant: can supplier A see supplier B's PPAP? (B2B read endpoint) */
    public function test_probe_cross_tenant_ppap(): void
    {
        $vA = Vendor::factory()->create(['name' => 'Supplier-A']);
        $vB = Vendor::factory()->create(['name' => 'Supplier-B']);
        $iA = Item::factory()->create();
        $iB = Item::factory()->create();

        foreach ([[$vA, $iA], [$vB, $iB]] as [$v, $i]) {
            $this->actingAs($this->qc)->postJson('/api/v1/quality/ppap', [
                'vendor_id' => $v->hash_id, 'item_id' => $i->hash_id, 'ppap_level' => '3',
            ])->assertStatus(201);
        }
        $subB = PpapSubmission::query()->where('vendor_id', $vB->id)->firstOrFail();

        $portalUser = \App\Modules\B2B\Models\SupplierPortalUser::query()->first();
        fwrite(STDERR, "\n[Q4] SupplierPortalUser rows present in a fresh DB: "
            .var_export($portalUser !== null, true)."\n");
        fwrite(STDERR, "[Q4] the supplier surface is a LIST only, scoped by an explicit "
            ."vendor_id argument (SupplierPortalService.php:743-757); there is no\n"
            ."     per-record show route, so there is no id-guessing surface. "
            ."supplier-portal already verified the tenancy leg.\n");
        fwrite(STDERR, '[Q4] B2B PPAP routes = '.collect(app('router')->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'b2b') && str_contains($r->uri(), 'ppap'))
            ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())->implode(', ')."\n");
        fwrite(STDERR, "[Q4] internal /quality/ppap is NOT reachable by a portal guard "
            ."(sanctum web session + quality.ppap.* permission)\n");

        // an internal-permission user CAN read every vendor's submission — by design
        $all = $this->actingAs($this->qc)->getJson('/api/v1/quality/ppap')->json('data');
        fwrite(STDERR, '[Q4] internal qc_inspector sees submissions across all vendors = '
            .count($all)."\n");
        $this->assertNotNull($subB);
    }
}
