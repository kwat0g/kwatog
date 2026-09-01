<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Quality\Enums\PpapElementStatus;
use App\Modules\Quality\Enums\PpapElementType;
use App\Modules\Quality\Enums\PpapStatus;
use App\Modules\Quality\Models\PpapElement;
use App\Modules\Quality\Models\PpapSubmission;
use App\Modules\Quality\Services\PpapService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M058 AUDIT PROBE — PPAP submission integrity over HTTP.
 * Scratch file: delete before release.
 */
class ZzM058PpapProbeTest extends TestCase
{
    use RefreshDatabase;

    private User $qc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->qc = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
    }

    private function newSubmission(?Vendor $v = null, ?Item $i = null): PpapSubmission
    {
        $v ??= Vendor::factory()->create();
        $i ??= Item::factory()->create();

        $r = $this->actingAs($this->qc)->postJson('/api/v1/quality/ppap', [
            'vendor_id'  => $v->hash_id,
            'item_id'    => $i->hash_id,
            'ppap_level' => '3',
        ]);
        $r->assertStatus(201);

        return PpapSubmission::query()->latest('id')->firstOrFail();
    }

    /** PROBE 1 — every PPAP endpoint over HTTP (none is covered by any test). */
    public function test_probe_all_ppap_endpoints_over_http(): void
    {
        $v = Vendor::factory()->create();
        $i = Item::factory()->create();

        $create = $this->actingAs($this->qc)->postJson('/api/v1/quality/ppap', [
            'vendor_id'  => $v->hash_id,
            'item_id'    => $i->hash_id,
            'ppap_level' => '3',
            'notes'      => 'probe',
        ]);
        fwrite(STDERR, "\n[P1] POST /ppap => ".$create->status()."\n");
        $create->assertStatus(201);
        $hash = $create->json('data.id');
        fwrite(STDERR, "[P1] created id payload = {$hash}\n");
        $this->assertIsString($hash, 'hash id must be a string');

        $index = $this->actingAs($this->qc)->getJson('/api/v1/quality/ppap');
        fwrite(STDERR, '[P1] GET /ppap => '.$index->status()."\n");
        $index->assertOk();

        $show = $this->actingAs($this->qc)->getJson("/api/v1/quality/ppap/{$hash}");
        fwrite(STDERR, '[P1] GET /ppap/{id} => '.$show->status()."\n");
        $show->assertOk();

        $put = $this->actingAs($this->qc)->putJson("/api/v1/quality/ppap/{$hash}", ['notes' => 'edited']);
        fwrite(STDERR, '[P1] PUT /ppap/{id} => '.$put->status()."\n");
        $put->assertOk();

        $submit = $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$hash}/submit");
        fwrite(STDERR, '[P1] PATCH submit => '.$submit->status()."\n");
        $submit->assertOk();

        $review = $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$hash}/review");
        fwrite(STDERR, '[P1] PATCH review => '.$review->status()."\n");
        $review->assertOk();

        $ppap = PpapSubmission::query()->latest('id')->firstOrFail();
        $el = $ppap->elements()->firstOrFail();
        $patchEl = $this->actingAs($this->qc)->patchJson(
            "/api/v1/quality/ppap/{$hash}/elements/{$el->hash_id}",
            ['status' => 'accepted', 'document_path' => 'ppap/psw.pdf'],
        );
        fwrite(STDERR, '[P1] PATCH element => '.$patchEl->status()."\n");
        $patchEl->assertOk();

        $approve = $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$hash}/approve");
        fwrite(STDERR, '[P1] PATCH approve => '.$approve->status()."\n");
        $approve->assertOk();

        // reject a fresh one so the reject leg is exercised on a legal state
        $other = $this->newSubmission();
        $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$other->hash_id}/submit")->assertOk();
        $rej = $this->actingAs($this->qc)->patchJson(
            "/api/v1/quality/ppap/{$other->hash_id}/reject", ['reason' => 'probe reject'],
        );
        fwrite(STDERR, '[P1] PATCH reject => '.$rej->status()."\n");
        $rej->assertOk();

        $this->assertTrue(true);
    }

    /** PROBE 2 — can a submission be APPROVED with only its auto-PSW row? */
    public function test_probe_approval_without_required_elements(): void
    {
        $p = $this->newSubmission();
        $count = $p->elements()->count();
        fwrite(STDERR, "\n[P2] elements auto-created at level 3 = {$count}\n");
        fwrite(STDERR, '[P2] element types = '.$p->elements()->pluck('element_type')->implode(',')."\n");

        $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$p->hash_id}/submit")->assertOk();
        $el = $p->elements()->firstOrFail();
        $this->actingAs($this->qc)->patchJson(
            "/api/v1/quality/ppap/{$p->hash_id}/elements/{$el->hash_id}", ['status' => 'accepted'],
        )->assertOk();

        // no document_path at all on the only element
        $ap = $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$p->hash_id}/approve");
        fwrite(STDERR, '[P2] approve with 1 element, no evidence => '.$ap->status()."\n");
        fwrite(STDERR, '[P2] final status = '.$p->fresh()->status->value."\n");
        fwrite(STDERR, '[P2] element document_path = '.var_export($el->fresh()->document_path, true)."\n");

        $this->assertTrue(true);
    }

    /** PROBE 3 — producer approves own PPAP (IATF self-approval). */
    public function test_probe_self_approval(): void
    {
        $p = $this->newSubmission();
        $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$p->hash_id}/submit")->assertOk();
        $el = $p->elements()->firstOrFail();
        $this->actingAs($this->qc)->patchJson(
            "/api/v1/quality/ppap/{$p->hash_id}/elements/{$el->hash_id}", ['status' => 'accepted'],
        )->assertOk();
        $r = $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$p->hash_id}/approve");

        $f = $p->fresh();
        fwrite(STDERR, "\n[P3] approve by the same user who created it => ".$r->status()."\n");
        fwrite(STDERR, "[P3] submitted_by={$f->submitted_by} reviewed_by=".var_export($f->reviewed_by, true)
            ." approved_by={$f->approved_by}\n");
        fwrite(STDERR, '[P3] SAME ACTOR ALL THREE: '
            .(($f->submitted_by === $f->approved_by) ? 'YES' : 'no')."\n");
        // was a review step even required?
        fwrite(STDERR, '[P3] approved straight from Submitted (no review): '
            .($f->reviewed_by === null ? 'YES' : 'no')."\n");

        $this->assertTrue(true);
    }

    /** PROBE 4 — approved submission immutability: Eloquent / raw SQL / delete / triggers. */
    public function test_probe_approved_immutability(): void
    {
        $p = $this->newSubmission();
        $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$p->hash_id}/submit")->assertOk();
        $el = $p->elements()->firstOrFail();
        $this->actingAs($this->qc)->patchJson(
            "/api/v1/quality/ppap/{$p->hash_id}/elements/{$el->hash_id}",
            ['status' => 'accepted', 'document_path' => 'ppap/original-psw.pdf'],
        )->assertOk();
        $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$p->hash_id}/approve")->assertOk();
        $this->assertSame(PpapStatus::Approved, $p->fresh()->status);

        // (a) HTTP PUT on an approved parent
        $put = $this->actingAs($this->qc)->putJson("/api/v1/quality/ppap/{$p->hash_id}", ['notes' => 'HACKED']);
        fwrite(STDERR, "\n[P4a] PUT approved parent => ".$put->status()
            .' | notes now = '.var_export($p->fresh()->notes, true)."\n");

        // (b) HTTP element swap AFTER approval — the evidence itself
        $swap = $this->actingAs($this->qc)->patchJson(
            "/api/v1/quality/ppap/{$p->hash_id}/elements/{$el->hash_id}",
            ['document_path' => 'ppap/SWAPPED-EVIDENCE.pdf', 'status' => 'rejected'],
        );
        fwrite(STDERR, '[P4b] PATCH element on APPROVED submission => '.$swap->status()."\n");
        fwrite(STDERR, '[P4b] element document_path now = '.var_export($el->fresh()->document_path, true)."\n");
        fwrite(STDERR, '[P4b] element status now = '.$el->fresh()->status->value."\n");
        fwrite(STDERR, '[P4b] parent still reads approved = '.$p->fresh()->status->value."\n");

        // (c) HTTP reject AFTER approval
        $rej = $this->actingAs($this->qc)->patchJson(
            "/api/v1/quality/ppap/{$p->hash_id}/reject", ['reason' => 'post-approval reversal'],
        );
        fwrite(STDERR, '[P4c] PATCH reject on APPROVED submission => '.$rej->status()
            .' | status now = '.$p->fresh()->status->value."\n");

        // (d) raw SQL rewrite of the ppap_number
        $p2 = $this->newSubmission();
        $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$p2->hash_id}/submit")->assertOk();
        $e2 = $p2->elements()->firstOrFail();
        $this->actingAs($this->qc)->patchJson(
            "/api/v1/quality/ppap/{$p2->hash_id}/elements/{$e2->hash_id}", ['status' => 'accepted'],
        )->assertOk();
        $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$p2->hash_id}/approve")->assertOk();

        $rows = DB::update('update ppap_submissions set ppap_number = ? where id = ?', ['HACKED', $p2->id]);
        fwrite(STDERR, "[P4d] raw SQL rewrote ppap_number rows={$rows} -> "
            .DB::table('ppap_submissions')->where('id', $p2->id)->value('ppap_number')."\n");

        // (e) raw SQL delete of an approved submission
        $del = DB::delete('delete from ppap_submissions where id = ?', [$p2->id]);
        $left = DB::table('ppap_submissions')->where('id', $p2->id)->exists();
        $elLeft = DB::table('ppap_elements')->where('ppap_submission_id', $p2->id)->count();
        fwrite(STDERR, "[P4e] raw DELETE approved submission rows={$del} still_exists="
            .var_export($left, true)." orphan_elements={$elLeft}\n");

        // (f) Eloquent ->delete() (no SoftDeletes on the model)
        $p3 = $this->newSubmission();
        $ok = $p3->delete();
        fwrite(STDERR, '[P4f] Eloquent delete() on PpapSubmission => '.var_export($ok, true)
            .' SoftDeletes='.var_export(
                in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($p3), true), true,
            )."\n");

        // (g) pg_trigger census
        $trg = DB::select("select tgrelid::regclass::text tbl, tgname from pg_trigger
            where not tgisinternal and tgrelid::regclass::text in ('ppap_submissions','ppap_elements')");
        fwrite(STDERR, '[P4g] pg_trigger on ppap tables = '.count($trg)."\n");

        // (h) is the element write audited at all?
        $audits = DB::table('audit_logs')->where('model_type', 'like', '%PpapElement%')->count();
        $parentAudits = DB::table('audit_logs')->where('model_type', 'like', '%PpapSubmission%')->count();
        fwrite(STDERR, "[P4h] audit_logs PpapElement={$audits} PpapSubmission={$parentAudits}\n");

        $this->assertTrue(true);
    }

    /** PROBE 5 — two conflicting APPROVED submissions for the same vendor+item. */
    public function test_probe_two_conflicting_approved_submissions(): void
    {
        $v = Vendor::factory()->create();
        $i = Item::factory()->create();

        $ids = [];
        for ($n = 0; $n < 2; $n++) {
            $p = $this->newSubmission($v, $i);
            $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$p->hash_id}/submit")->assertOk();
            $el = $p->elements()->firstOrFail();
            $this->actingAs($this->qc)->patchJson(
                "/api/v1/quality/ppap/{$p->hash_id}/elements/{$el->hash_id}", ['status' => 'accepted'],
            )->assertOk();
            $r = $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$p->hash_id}/approve");
            $ids[] = [$p->id, $r->status(), $p->fresh()->ppap_level->value];
        }
        fwrite(STDERR, "\n[P5] approvals: ".json_encode($ids)."\n");
        $approved = PpapSubmission::query()->where('vendor_id', $v->id)->where('item_id', $i->id)
            ->where('status', 'approved')->count();
        fwrite(STDERR, "[P5] concurrently-approved submissions for one vendor+item = {$approved}\n");

        // now approve two with DIFFERENT levels — conflicting claims about the same part
        $p3 = $this->newSubmission($v, $i);
        $this->actingAs($this->qc)->putJson("/api/v1/quality/ppap/{$p3->hash_id}", ['ppap_level' => '1'])->assertOk();
        $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$p3->hash_id}/submit")->assertOk();
        $e3 = $p3->elements()->firstOrFail();
        $this->actingAs($this->qc)->patchJson(
            "/api/v1/quality/ppap/{$p3->hash_id}/elements/{$e3->hash_id}", ['status' => 'accepted'],
        )->assertOk();
        $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$p3->hash_id}/approve")->assertOk();
        $levels = PpapSubmission::query()->where('vendor_id', $v->id)->where('item_id', $i->id)
            ->where('status', 'approved')->pluck('ppap_level')->map(fn ($l) => is_object($l) ? $l->value : (string) $l)->implode(',');
        fwrite(STDERR, "[P5] approved levels for the SAME part = [{$levels}]\n");

        $this->assertTrue(true);
    }

    /** PROBE 6 — expiry: does anything age it, and does an expired one read as current? */
    public function test_probe_expiry_ageing(): void
    {
        $v = Vendor::factory()->create();
        $i = Item::factory()->create();
        $p = $this->newSubmission($v, $i);
        $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$p->hash_id}/submit")->assertOk();
        $el = $p->elements()->firstOrFail();
        $this->actingAs($this->qc)->patchJson(
            "/api/v1/quality/ppap/{$p->hash_id}/elements/{$el->hash_id}", ['status' => 'accepted'],
        )->assertOk();
        $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$p->hash_id}/approve")->assertOk();

        $f = $p->fresh();
        fwrite(STDERR, "\n[P6] approved_at={$f->approved_at} expires_at={$f->expires_at}\n");

        // Force it past expiry (pinned, not relative to wall clock drift).
        DB::table('ppap_submissions')->where('id', $p->id)
            ->update(['expires_at' => '2020-01-01 00:00:00']);

        $svc = app(PpapService::class);
        fwrite(STDERR, '[P6] vendorHasActivePpap after expiry = '
            .var_export($svc->vendorHasActivePpap($v->id, $i->id), true)."\n");
        fwrite(STDERR, '[P6] status column still says = '
            .DB::table('ppap_submissions')->where('id', $p->id)->value('status')."\n");

        // does the list endpoint present it as approved?
        $list = $this->actingAs($this->qc)->getJson('/api/v1/quality/ppap?status=approved');
        fwrite(STDERR, '[P6] GET /ppap?status=approved returns expired row: '
            .(collect($list->json('data'))->contains(fn ($r) => $r['id'] === $p->hash_id) ? 'YES' : 'no')."\n");
        fwrite(STDERR, '[P6] its status_label in the payload = '
            .(collect($list->json('data'))->firstWhere('id', $p->hash_id)['status_label'] ?? 'n/a')."\n");

        // is expireOverdue reachable from any command?
        $moved = $svc->expireOverdue();
        fwrite(STDERR, "[P6] expireOverdue() moved {$moved} row(s) when called DIRECTLY\n");
        fwrite(STDERR, '[P6] audit rows for that bulk expiry = '
            .DB::table('audit_logs')->where('model_type', 'like', '%PpapSubmission%')
                ->where('action', 'like', '%pdate%')->count()."\n");

        $this->assertTrue(true);
    }

    /** PROBE 7 — full transition matrix, 6 states x 5 actions = 30 cells. */
    public function test_probe_full_transition_matrix(): void
    {
        $states = PpapStatus::cases();
        $actions = ['submit', 'review', 'approve', 'reject', 'update'];
        $grid = [];
        $cells = 0;

        foreach ($states as $st) {
            foreach ($actions as $act) {
                $p = $this->newSubmission();
                // put every element into an approvable state so the matrix
                // measures the STATE guard, not the element guard
                foreach ($p->elements as $e) {
                    DB::table('ppap_elements')->where('id', $e->id)->update(['status' => 'accepted']);
                }
                DB::table('ppap_submissions')->where('id', $p->id)->update(['status' => $st->value]);

                $url = "/api/v1/quality/ppap/{$p->hash_id}";
                $res = match ($act) {
                    'update' => $this->actingAs($this->qc)->putJson($url, ['notes' => 'x']),
                    'reject' => $this->actingAs($this->qc)->patchJson("{$url}/reject", ['reason' => 'x']),
                    default  => $this->actingAs($this->qc)->patchJson("{$url}/{$act}"),
                };
                $after = DB::table('ppap_submissions')->where('id', $p->id)->value('status');
                $grid[$st->value][$act] = $res->status().($after !== $st->value ? "->{$after}" : '');
                $cells++;
            }
        }

        fwrite(STDERR, "\n[P7] PPAP transition matrix ({$cells} cells) — HTTP status, -> = state changed\n");
        fwrite(STDERR, sprintf("%-13s %-12s %-12s %-12s %-12s %-12s\n", 'FROM', ...$actions));
        foreach ($grid as $from => $row) {
            fwrite(STDERR, sprintf(
                "%-13s %-12s %-12s %-12s %-12s %-12s\n",
                $from, $row['submit'], $row['review'], $row['approve'], $row['reject'], $row['update'],
            ));
        }
        $this->assertSame(30, $cells);
    }

    /** PROBE 8 — permissions per endpoint including list, and every registry role. */
    public function test_probe_permission_gate_per_endpoint(): void
    {
        $p = $this->newSubmission();
        $el = $p->elements()->firstOrFail();
        $h = $p->hash_id;

        $endpoints = [
            ['GET', "/api/v1/quality/ppap"],
            ['GET', "/api/v1/quality/ppap/{$h}"],
            ['POST', "/api/v1/quality/ppap"],
            ['PUT', "/api/v1/quality/ppap/{$h}"],
            ['PATCH', "/api/v1/quality/ppap/{$h}/submit"],
            ['PATCH', "/api/v1/quality/ppap/{$h}/review"],
            ['PATCH', "/api/v1/quality/ppap/{$h}/approve"],
            ['PATCH', "/api/v1/quality/ppap/{$h}/reject"],
            ['PATCH', "/api/v1/quality/ppap/{$h}/elements/{$el->hash_id}"],
            ['GET', '/api/v1/quality/traceability/search?term=X'],
            ['GET', '/api/v1/quality/traceability/recall-simulation?lot=X'],
        ];

        foreach (['employee', 'qc_inspector', 'system_admin'] as $slug) {
            $u = User::factory()->withRole($slug)->create(['is_active' => true]);
            $line = "[P8] {$slug}: ";
            foreach ($endpoints as [$m, $url]) {
                $r = $this->actingAs($u)->json($m, $url, $m === 'PATCH' ? ['reason' => 'x'] : []);
                $line .= substr($m, 0, 2).' '.basename(parse_url($url, PHP_URL_PATH)).'='.$r->status().'  ';
            }
            fwrite(STDERR, "\n".$line."\n");
        }

        // NOTE: a "guest" leg cannot live in this method — actingAs() persists for the
        // rest of a test, so $this->json() here is still the last authenticated user.
        // Unauthenticated access is measured in ZzM058TraceProbeTest::test_probe_guest_is_refused.

        // does a 403/404 body leak a raw integer id?
        $denied = User::factory()->withRole('employee')->create(['is_active' => true]);
        $body = $this->actingAs($denied)->getJson("/api/v1/quality/ppap/{$h}")->getContent();
        fwrite(STDERR, '[P8] denied body = '.substr($body, 0, 300)."\n");
        fwrite(STDERR, '[P8] body contains the raw pk '.$p->id.': '
            .(preg_match('/\b'.$p->id.'\b/', $body) ? 'MAYBE' : 'no')."\n");

        $this->assertTrue(true);
    }

    /** PROBE 9 — validation family on the PPAP create/update FormRequests. */
    public function test_probe_validation_family(): void
    {
        $v = Vendor::factory()->create();
        $i = Item::factory()->create();

        // hash-id handling on optional refs, and raw-integer acceptance
        $cases = [
            'valid'                   => ['product_id' => null],
            'garbage hash product_id' => ['product_id' => 'NOT-A-HASH'],
            'raw integer product_id'  => ['product_id' => '999999'],
            'garbage purchase_order'  => ['purchase_order_id' => 'zzz'],
            'array product_id'        => ['product_id' => ['a' => 'b']],
            'level 9'                 => ['ppap_level' => '9'],
            'level 1e0'               => ['ppap_level' => '1e0'],
            'level float'             => ['ppap_level' => 1.0],
        ];
        foreach ($cases as $label => $extra) {
            $payload = array_merge([
                'vendor_id' => $v->hash_id, 'item_id' => $i->hash_id, 'ppap_level' => '3',
            ], $extra);
            try {
                $r = null;
                DB::transaction(function () use ($payload, &$r) {
                    $r = $this->actingAs($this->qc)->postJson('/api/v1/quality/ppap', $payload);
                });
            } catch (\Throwable $e) {
                fwrite(STDERR, sprintf("\n[P9] %-24s => threw %s", $label, $this->brief($e)));
                continue;
            }
            $stored = $r->status() === 201
                ? DB::table('ppap_submissions')->where('id', PpapSubmission::max('id'))
                    ->first(['product_id', 'purchase_order_id', 'ppap_level'])
                : null;
            fwrite(STDERR, sprintf(
                "\n[P9] %-24s => %d %s", $label, $r->status(), $stored ? json_encode($stored) : '',
            ));
        }
        fwrite(STDERR, "\n");

        // PUT: product_id is validated as a raw INTEGER here but a hash on create
        $p = $this->newSubmission($v, $i);
        foreach ([['product_id' => 4242], ['product_id' => 'aHashLike'], ['ppap_level' => 'banana']] as $body) {
            $r = $this->actingAs($this->qc)->putJson("/api/v1/quality/ppap/{$p->hash_id}", $body);
            fwrite(STDERR, '[P9-PUT] '.json_encode($body).' => '.$r->status()
                .' stored ppap_level='.DB::table('ppap_submissions')->where('id', $p->id)->value('ppap_level')
                .' product_id='.var_export(
                    DB::table('ppap_submissions')->where('id', $p->id)->value('product_id'), true,
                )."\n");
        }

        // element document_path: traversal + over-length
        $el = $p->elements()->firstOrFail();
        foreach ([
            'traversal'   => '../../../../etc/passwd',
            'absolute'    => '/etc/shadow',
            'overlength'  => str_repeat('a', 600),
            'null byte'   => "ok.pdf\0.php",
            'script'      => '<script>x</script>.pdf',
        ] as $label => $path) {
            $r = $this->actingAs($this->qc)->patchJson(
                "/api/v1/quality/ppap/{$p->hash_id}/elements/{$el->hash_id}", ['document_path' => $path],
            );
            fwrite(STDERR, sprintf(
                "[P9-doc] %-11s => %d stored=%s\n", $label, $r->status(),
                var_export(substr((string) $el->fresh()->document_path, 0, 60), true),
            ));
        }

        $this->assertTrue(true);
    }

    private function brief(\Throwable $e): string
    {
        return preg_match('/SQLSTATE\[(\w+)\]/', $e->getMessage(), $m)
            ? $m[1].' '.substr(strstr($e->getMessage(), 'ERROR') ?: '', 0, 90)
            : substr($e->getMessage(), 0, 90);
    }

    /** PROBE 10 — soft-deleted vendor / item / product across list + gate + trace. */
    public function test_probe_soft_deleted_parents(): void
    {
        $v = Vendor::factory()->create();
        $i = Item::factory()->create();
        $p = $this->newSubmission($v, $i);
        $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$p->hash_id}/submit")->assertOk();
        $el = $p->elements()->firstOrFail();
        $this->actingAs($this->qc)->patchJson(
            "/api/v1/quality/ppap/{$p->hash_id}/elements/{$el->hash_id}", ['status' => 'accepted'],
        )->assertOk();
        $this->actingAs($this->qc)->patchJson("/api/v1/quality/ppap/{$p->hash_id}/approve")->assertOk();

        $svc = app(PpapService::class);
        fwrite(STDERR, "\n[P10] gate before archive = ".var_export($svc->vendorHasActivePpap($v->id, $i->id), true)."\n");

        $v->delete(); // soft delete
        fwrite(STDERR, '[P10] vendor soft-deleted; trashed='.var_export($v->fresh()->trashed(), true)."\n");
        fwrite(STDERR, '[P10] gate after vendor archive = '
            .var_export($svc->vendorHasActivePpap($v->id, $i->id), true)."\n");

        $list = $this->actingAs($this->qc)->getJson('/api/v1/quality/ppap');
        $row = collect($list->json('data'))->firstWhere('id', $p->hash_id);
        fwrite(STDERR, '[P10] list still returns the row: '.($row ? 'YES' : 'no')
            .' | vendor block = '.json_encode($row['vendor'] ?? 'ABSENT')."\n");

        $show = $this->actingAs($this->qc)->getJson("/api/v1/quality/ppap/{$p->hash_id}");
        fwrite(STDERR, '[P10] show => '.$show->status().' vendor = '
            .json_encode($show->json('data.vendor'))."\n");

        $i->delete();
        fwrite(STDERR, '[P10] item soft-deleted; gate = '
            .var_export($svc->vendorHasActivePpap($v->id, $i->id), true)."\n");
        $show2 = $this->actingAs($this->qc)->getJson("/api/v1/quality/ppap/{$p->hash_id}");
        fwrite(STDERR, '[P10] show after item archive => '.$show2->status().' item = '
            .json_encode($show2->json('data.item'))."\n");

        // FORCE delete the vendor — cascadeOnDelete on ppap_submissions
        $before = PpapSubmission::query()->count();
        try {
            $v->forceDelete();
            $after = PpapSubmission::query()->count();
            fwrite(STDERR, "[P10] vendor FORCE-deleted: submissions {$before} -> {$after} "
                .($after < $before ? '*** APPROVED PPAP SILENTLY ERASED ***' : 'refused/retained')."\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, '[P10] vendor forceDelete refused: '.substr($e->getMessage(), 0, 160)."\n");
        }

        $this->assertTrue(true);
    }

    /** PROBE 11 — element uniqueness / creation of the full 18-element set. */
    public function test_probe_element_set_shape(): void
    {
        $p = $this->newSubmission();
        fwrite(STDERR, "\n[P11] PpapElementType cases = ".count(PpapElementType::cases())."\n");
        fwrite(STDERR, '[P11] auto-created for level 3 = '.$p->elements()->count()."\n");

        // can I add arbitrary elements? there is no create-element route.
        $routes = collect(app('router')->getRoutes())->map(fn ($r) => $r->methods()[0].' '.$r->uri())
            ->filter(fn ($u) => str_contains($u, 'ppap'))->values()->all();
        fwrite(STDERR, "[P11] all ppap routes:\n  ".implode("\n  ", $routes)."\n");

        // duplicate element_type refused at DB level?
        try {
            DB::transaction(fn () => PpapElement::create([
                'ppap_submission_id' => $p->id,
                'element_type'       => PpapElementType::PartSubmissionWarrant->value,
                'status'             => PpapElementStatus::Pending->value,
            ]));
            fwrite(STDERR, "[P11] duplicate element_type ACCEPTED\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, '[P11] duplicate element_type refused: '
                .(str_contains($e->getMessage(), 'ppap_element_unique') ? 'unique constraint' : substr($e->getMessage(), 0, 80))."\n");
        }

        // an element for a level-1 submission that has no PSW evidence at all
        fwrite(STDERR, '[P11] elements with a document_path = '
            .PpapElement::query()->whereNotNull('document_path')->count()."\n");

        $this->assertTrue(true);
    }
}
