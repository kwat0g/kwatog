<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Models\DssRunLog;
use App\Modules\DSS\Services\DetectorRegistry;
use App\Modules\DSS\Services\DssParameterService;
use App\Modules\DSS\Services\RecommendationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecommendationQueryDetector implements DetectorInterface
{
    public function id(): string
    {
        return 'query.advisory';
    }

    public function domain(): string
    {
        return 'cross_module';
    }

    public function ownerPermissions(): array
    {
        return ['dss.view'];
    }

    public function version(): string
    {
        return 'v1';
    }

    public function defaultParams(): array
    {
        return [];
    }

    public function detect(array $context = []): DetectorScanResult
    {
        return new DetectorScanResult([]);
    }
}

class RecommendationShadowQueryDetector extends RecommendationQueryDetector
{
    public function id(): string
    {
        return 'query.shadow';
    }
}

class RecommendationDisabledQueryDetector extends RecommendationQueryDetector
{
    public function id(): string
    {
        return 'query.disabled';
    }
}

class RecommendationQueryTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    /** @var array<string, DssRunLog> */
    private array $evaluationRuns = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('modules.dss', true, 'modules');
        app(DetectorRegistry::class)->register('query.advisory', RecommendationQueryDetector::class);
        app(DssParameterService::class)->set('query.advisory', 'mode', 'advisory');
        app(DetectorRegistry::class)->register('query.shadow', RecommendationShadowQueryDetector::class);
        app(DetectorRegistry::class)->register('query.disabled', RecommendationDisabledQueryDetector::class);
        app(DssParameterService::class)->set('query.shadow', 'mode', 'shadow');
        app(DssParameterService::class)->set('query.disabled', 'mode', 'advisory');
        app(DssParameterService::class)->set('query.disabled', 'enabled', false);
        $this->viewer = User::factory()->withRole('system_admin')->create(['is_active' => true]);
    }

    private function row(array $values = []): DssRecommendation
    {
        static $n = 0;
        $n++;

        $detector = $values['detector_id'] ?? 'query.advisory';
        $run = $this->evaluationRuns[$detector] ??= DssRunLog::create([
            'detector_id' => $detector,
            'detector_version' => 'v1',
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $row = DssRecommendation::create(array_merge([
            'detector_id' => 'query.advisory', 'domain' => 'cross_module', 'type' => 'query_test',
            'severity' => 'warning', 'priority_score' => '0.5', 'rationale' => ['rule' => 'ordinary'],
            'dedupe_key' => 'query-test-'.$n.'-'.uniqid(),
            'last_evaluated_at' => $run->finished_at,
            'last_evaluated_run_id' => $run->id,
        ], $values));
        $row->forceFill(['created_at' => now()->subSeconds($n)])->save();

        return $row;
    }

    public function test_list_paginates_visible_rows_and_summary_ignores_pagination(): void
    {
        $highestPriority = null;
        $newest = null;
        for ($i = 0; $i < 41; $i++) {
            $row = $this->row(['severity' => ['critical', 'warning', 'info'][$i % 3], 'priority_score' => $i === 0 ? '0.99' : '0.5']);
            if ($i === 0) {
                $row->forceFill(['created_at' => now()->subYear()])->save();
                $highestPriority = $row;
            } elseif ($i === 1) {
                $newest = $row;
            }
        }
        $hidden = $this->row(['detector_id' => 'not.registered', 'severity' => 'critical']);

        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations?per_page=20&page=3')
            ->assertOk()->assertJsonPath('meta.total', 41)->assertJsonPath('meta.current_page', 3)->assertJsonCount(1, 'data');
        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations/summary?page=9&per_page=1')
            ->assertOk()->assertJsonPath('data.total', 41)->assertJsonPath('data.by_severity.critical', 14)
            ->assertJsonPath('data.by_severity.warning', 14)->assertJsonPath('data.by_severity.info', 13)
            ->assertJsonMissing(['id' => $hidden->hash_id]);
        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations?per_page=1')
            ->assertOk()->assertJsonPath('data.0.id', $highestPriority->hash_id);
        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations?sort=created_at&direction=desc&per_page=1')
            ->assertOk()->assertJsonPath('data.0.id', $newest->hash_id);
        $paginator = app(RecommendationService::class)->list($this->viewer, ['page' => 3, 'per_page' => 20]);
        $this->assertSame(3, $paginator->currentPage());
    }

    public function test_active_base_preserves_expiry_freshness_and_due_snooze_rules(): void
    {
        $visible = $this->row();
        $expired = $this->row(['expires_at' => now()->subSecond()]);
        $futureSnooze = $this->row(['snoozed_until' => now()->addHour()]);
        $futureSnooze->forceFill(['status' => 'snoozed'])->save();
        $dueSnooze = $this->row(['snoozed_until' => now()->subSecond()]);
        $dueSnooze->forceFill(['status' => 'snoozed'])->save();
        $stale = $this->row();
        DB::table('dss_recommendations')->where('id', $stale->id)->update(['updated_at' => now()->subMinute()]);

        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations')
            ->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonFragment(['id' => $visible->hash_id])->assertJsonFragment(['id' => $dueSnooze->hash_id])
            ->assertJsonFragment(['id' => $stale->hash_id])
            ->assertJsonMissing(['id' => $expired->hash_id])->assertJsonMissing(['id' => $futureSnooze->hash_id]);

        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations?status=snoozed')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['id' => $futureSnooze->hash_id]);
    }

    public function test_follow_up_and_history_status_filters_match_their_labels(): void
    {
        $active = $this->row();
        $followUp = $this->row();
        $followUp->forceFill(['status' => 'in_progress'])->save();
        $accepted = $this->row();
        $accepted->forceFill(['status' => 'accepted'])->save();

        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations')
            ->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['id' => $active->hash_id])
            ->assertJsonFragment(['id' => $followUp->hash_id])
            ->assertJsonMissing(['id' => $accepted->hash_id]);
        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations?status=in_progress')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['id' => $followUp->hash_id]);
        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations?status=accepted')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['id' => $accepted->hash_id]);
        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations/options')
            ->assertOk()->assertJsonFragment(['value' => 'in_progress', 'label' => 'In progress'])
            ->assertJsonFragment(['value' => 'resolved', 'label' => 'Resolved']);
    }

    public function test_service_list_uses_explicit_validated_page_without_http_request_state(): void
    {
        for ($i = 0; $i < 41; $i++) {
            $this->row();
        }

        $paginator = app(RecommendationService::class)->list($this->viewer, ['page' => 3, 'per_page' => 20]);

        $this->assertSame(3, $paginator->currentPage());
        $this->assertCount(1, $paginator->items());
    }

    public function test_summary_filters_results_before_counting_and_options_ignore_list_filters(): void
    {
        $critical = $this->row(['severity' => 'critical', 'domain' => 'cross_module']);
        $this->row(['severity' => 'warning', 'domain' => 'cross_module']);
        $filtered = $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations/summary?severity=critical&per_page=1');
        $filtered->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.by_severity.critical', 1)
            ->assertJsonPath('data.by_severity.warning', 0);
        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations?severity=critical')->assertOk();
        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations/options')->assertOk()
            ->assertJsonFragment(['value' => 'cross_module', 'label' => 'Connected workflows']);
        $this->assertSame(1, $filtered->json('data.total'));
    }

    public function test_hidden_shadow_disabled_and_unowned_high_impact_rows_never_affect_queries(): void
    {
        $viewer = User::factory()->withRole('hr_officer')->create(['is_active' => true]);
        $visible = $this->row(['owner_role_slugs' => ['hr_officer']]);
        $this->row(['detector_id' => 'query.shadow', 'domain' => 'shadow_domain', 'impact_estimate' => '999999999.00']);
        $this->row(['detector_id' => 'query.disabled', 'domain' => 'disabled_domain', 'impact_estimate' => '999999999.00']);
        $this->row(['domain' => 'hidden_permission', 'owner_permissions' => ['accounting.view'], 'owner_role_slugs' => ['finance_officer'], 'impact_estimate' => '999999999.00']);

        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/dss/recommendations/summary')->assertOk()->assertJsonPath('data.total', 1);
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/dss/recommendations/options')->assertOk()
            ->assertJsonMissing(['value' => 'shadow_domain'])->assertJsonMissing(['value' => 'disabled_domain'])
            ->assertJsonMissing(['value' => 'hidden_permission']);
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/dss/recommendations')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonFragment(['id' => $visible->hash_id]);
    }

    public function test_sorting_happens_before_pagination_and_ties_use_id(): void
    {
        $low = $this->row(['priority_score' => '0.1']);
        $high = $this->row(['priority_score' => '0.9']);
        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations?sort=priority_score&direction=asc&per_page=1')
            ->assertOk()->assertJsonPath('data.0.id', $low->hash_id);
        $tieA = $this->row(['priority_score' => '0.5']);
        $tieB = $this->row(['priority_score' => '0.5']);
        $unscored = $this->row(['priority_score' => null]);
        $response = $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations?sort=priority_score&direction=asc&per_page=100');
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertLessThan(array_search($tieA->hash_id, $ids, true), array_search($tieB->hash_id, $ids, true));
        $this->assertContains($high->hash_id, $ids);
        $descending = $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations?sort=priority_score&direction=desc&per_page=100')->json('data');
        $descendingIds = collect($descending)->pluck('id')->all();
        $this->assertLessThan(array_search($unscored->hash_id, $descendingIds, true), array_search($high->hash_id, $descendingIds, true));
        $this->assertSame($unscored->hash_id, end($descendingIds));
    }

    public function test_options_are_scoped_and_filters_reject_unavailable_choices(): void
    {
        $viewer = User::factory()->withRole('hr_officer')->create(['is_active' => true]);
        $this->row(['domain' => 'cross_module', 'owner_role_slugs' => ['hr_officer']]);
        $this->row(['domain' => 'hidden_domain', 'owner_role_slugs' => ['hidden_role']]);
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/dss/recommendations/options')
            ->assertOk()->assertJsonPath('data.domains.0.value', 'cross_module')
            ->assertJsonFragment(['value' => 'hr_officer', 'label' => 'HR Officer'])
            ->assertJsonMissing(['value' => 'hidden_domain'])->assertJsonMissing(['value' => 'hidden_role']);
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/dss/recommendations?domain=hidden_domain')->assertUnprocessable();
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/dss/recommendations?owner_role=hidden_role')->assertUnprocessable();
    }

    public function test_search_matches_literal_patterns_and_human_scalar_and_source_fields(): void
    {
        $percent = $this->row(['rationale' => ['customer' => 'A%_B']]);
        $customer = $this->row(['rationale' => ['customer' => 'Customer-Needle']]);
        $workOrder = $this->row(['rationale' => ['work_order' => 'WO-Needle']]);
        $ncr = $this->row(['rationale' => ['ncr_number' => 'NCR-Needle']]);
        $training = $this->row(['rationale' => ['training' => 'Training-Needle']]);
        $rule = $this->row(['rationale' => ['rule' => 'Rule-Needle']]);
        $document = $this->row(['rationale' => ['sample_documents' => [['document' => 'Doc-Needle']]]]);
        $object = $this->row(['rationale' => ['customer' => ['nested' => 'Customer-Needle']]]);
        foreach ([['%_', $percent], ['Customer-Needle', $customer], ['WO-Needle', $workOrder], ['NCR-Needle', $ncr], ['Training-Needle', $training], ['Rule-Needle', $rule], ['Doc-Needle', $document]] as [$term, $expected]) {
            $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations?search='.urlencode($term))
                ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonFragment(['id' => $expected->hash_id]);
        }
        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations?search=Customer-Needle')
            ->assertOk()->assertJsonMissing(['id' => $object->hash_id]);
        $this->row(['rationale' => ['sample_documents' => ['bad', ['document' => ['nested' => 'Doc-Needle']], null]]]);
        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations?search=unmatched')->assertOk();
    }

    public function test_invalid_query_values_return_validation_errors(): void
    {
        foreach (['sort=created_at%3Bid', 'direction=sideways', 'page=0', 'per_page=101', 'status=closed', 'search='.str_repeat('x', 121)] as $query) {
            $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations?'.$query)->assertUnprocessable();
        }
    }

    public function test_owner_role_slugs_are_returned_and_static_paths_do_not_bind_as_models(): void
    {
        $row = $this->row(['owner_role_slugs' => ['hr_officer']]);
        $malformed = $this->row();
        $unrecorded = $this->row(['owner_role_slugs' => ['ghost_role']]);
        DB::table('dss_recommendations')->where('id', $malformed->id)->update(['owner_role_slugs' => json_encode(['unexpected' => 'malformed_role'])]);
        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations?sort=created_at&direction=desc&per_page=1')
            ->assertOk()->assertJsonPath('data.0.owner_role_slugs.0', 'hr_officer');
        $resource = $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations?per_page=100')->assertOk()
            ->json('data');
        $malformedResource = collect($resource)->firstWhere('id', $malformed->hash_id);
        $this->assertNull($malformedResource['owner_role_slugs']);
        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations/summary')->assertOk();
        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations/options')->assertOk();
        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/dss/recommendations/options')->assertJsonFragment(['value' => 'ghost_role', 'label' => 'Owner not recorded']);
    }
}
