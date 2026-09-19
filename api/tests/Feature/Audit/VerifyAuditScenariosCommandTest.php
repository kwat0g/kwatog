<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class VerifyAuditScenariosCommandTest extends TestCase
{
    /** @var list<string> */
    private array $manifestFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'typesafe.api_key' => 'test-key',
            'typesafe.endpoint' => 'https://api.typesafe.test/v1/systemone',
            'typesafe.model' => 'jev-1.13.0',
            'typesafe.max_attempts' => 1,
            'typesafe.min_confidence' => 0.75,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->manifestFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_live_calls_are_opt_in(): void
    {
        Http::fake();

        $exit = Artisan::call('audit:verify-scenarios', [
            'manifest' => $this->manifestFile(),
        ]);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('--live', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_it_reports_a_covered_scenario_as_a_pass(): void
    {
        Http::fake([
            'https://api.typesafe.test/*' => Http::response($this->jevResponse(
                reachable: 0.98,
                supported: 0.96,
                current: 0.97,
                coverage: 'covered',
                coverageConfidence: 0.92,
                riskScore: 0.2,
            )),
        ]);

        $exit = Artisan::call('audit:verify-scenarios', [
            'manifest' => $this->manifestFile(),
            '--live' => true,
            '--json' => true,
        ]);

        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('pass', $report['status']);
        $this->assertSame(1, $report['counts']['covered']);
        $this->assertSame('covered', $report['scenarios'][0]['status']);
    }

    public function test_it_reports_a_supported_reachable_gap_as_a_failure(): void
    {
        Http::fake([
            'https://api.typesafe.test/*' => Http::response($this->jevResponse(
                reachable: 0.98,
                supported: 0.12,
                current: 0.96,
                coverage: 'missing',
                coverageConfidence: 0.9,
                riskScore: 2.0,
            )),
        ]);

        $exit = Artisan::call('audit:verify-scenarios', [
            'manifest' => $this->manifestFile(),
            '--live' => true,
            '--json' => true,
        ]);

        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('fail', $report['status']);
        $this->assertSame(1, $report['counts']['gaps']);
        $this->assertSame('gap', $report['scenarios'][0]['status']);
        $this->assertSame('PUR-001', $report['scenarios'][0]['id']);
    }

    /** @return array<string, mixed> */
    private function jevResponse(
        float $reachable,
        float $supported,
        float $current,
        string $coverage,
        float $coverageConfidence,
        float $riskScore,
    ): array {
        return [
            'model' => 'jev-1.13.0',
            'answers' => [
                'scenario_is_reachable' => ['type' => 'noul', 'noul' => $reachable],
                'expected_invariant_supported' => ['type' => 'noul', 'noul' => $supported],
                'evidence_is_current' => ['type' => 'noul', 'noul' => $current],
                'coverage_status' => [
                    'type' => 'choice',
                    'choice' => $coverage,
                    'confidence' => $coverageConfidence,
                    'probabilities' => [$coverage => $coverageConfidence],
                ],
                'risk' => [
                    'type' => 'score',
                    'score' => $riskScore,
                    'confidence' => 0.9,
                    'probabilities' => ['0' => 0.1, '1' => 0.2, '2' => 0.7],
                    'legend' => [
                        '0' => 'Minor inconvenience with easy recovery',
                        '1' => 'Operational or data-integrity impact',
                        '2' => 'Financial, security, compliance, or safety impact',
                    ],
                ],
            ],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
        ];
    }

    /** @param array<string, mixed>|null $manifest */
    private function manifestFile(?array $manifest = null): string
    {
        $path = storage_path('framework/testing/jev-audit-'.uniqid('', true).'.json');
        file_put_contents($path, json_encode($manifest ?? [
            'schema_version' => 1,
            'scenarios' => [[
                'id' => 'PUR-001',
                'module' => 'Purchasing',
                'source' => 'docs/new-audit/PURCHASE-REQUEST-CHAIN-TRACE-2026-09-18-FINISHED.md',
                'expected' => 'A requester cannot approve their own purchase request.',
                'evidence' => [
                    'implementation' => ['The approval service checks the acting user before approving a step.'],
                    'tests' => ['PurchaseRequestChainTraceFixesTest covers self-approval refusal.'],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));
        $this->manifestFiles[] = $path;

        return $path;
    }
}
