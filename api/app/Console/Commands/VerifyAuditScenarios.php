<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Exceptions\JevClientException;
use App\Common\Services\JevClient;
use Illuminate\Console\Command;
use JsonException;

final class VerifyAuditScenarios extends Command
{
    protected $signature = 'audit:verify-scenarios
        {manifest? : JSON manifest path; omit when using --stdin}
        {--stdin : Read the JSON manifest from standard input}
        {--live : Allow requests to the live Jev API}
        {--json : Emit machine-readable output}';

    protected $description = 'Read-only, confidence-gated Jev verification of supplied audit scenarios';

    public function __construct(private readonly JevClient $jev)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('live')) {
            return $this->configurationError('Live Jev calls are opt-in. Re-run with --live.');
        }

        try {
            $manifest = $this->loadManifest();
            $scenarios = $this->validateManifest($manifest);
        } catch (JevClientException|JsonException $exception) {
            return $this->configurationError($exception->getMessage());
        }

        $results = [];
        foreach ($scenarios as $scenario) {
            try {
                $results[] = $this->verifyScenario($scenario);
            } catch (JevClientException $exception) {
                $results[] = [
                    'id' => $scenario['id'],
                    'module' => $scenario['module'],
                    'status' => 'service_error',
                    'error' => $exception->getMessage(),
                ];
            }
        }

        $report = $this->summarize($results);
        $this->render($report);

        return in_array($report['status'], ['pass'], true) ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function loadManifest(): array
    {
        $fromStdin = (bool) $this->option('stdin');
        $path = $this->argument('manifest');

        if ($fromStdin === ($path !== null)) {
            throw new JevClientException('Provide exactly one manifest source: a path or --stdin.');
        }

        $raw = $fromStdin
            ? stream_get_contents(STDIN)
            : file_get_contents($this->resolvePath((string) $path));

        if (! is_string($raw) || trim($raw) === '') {
            throw new JevClientException('The audit scenario manifest is empty or unreadable.');
        }

        $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($manifest)) {
            throw new JevClientException('The audit scenario manifest must be a JSON object.');
        }

        return $manifest;
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    /** @return list<array{id: string, module: string, source: ?string, expected: string, evidence: array<string, mixed>}> */
    private function validateManifest(array $manifest): array
    {
        if (($manifest['schema_version'] ?? null) !== 1 || ! is_array($manifest['scenarios'] ?? null) || $manifest['scenarios'] === []) {
            throw new JevClientException('The manifest must contain schema_version 1 and at least one scenario.');
        }

        $scenarios = [];
        foreach ($manifest['scenarios'] as $index => $scenario) {
            if (! is_array($scenario)
                || ! is_string($scenario['id'] ?? null)
                || ! is_string($scenario['module'] ?? null)
                || ! is_string($scenario['expected'] ?? null)
                || ! is_array($scenario['evidence'] ?? null)) {
                throw new JevClientException("Scenario {$index} is missing id, module, expected, or evidence.");
            }

            $source = $scenario['source'] ?? null;
            if ($source !== null && ! is_string($source)) {
                throw new JevClientException("Scenario {$index} has an invalid source.");
            }

            $scenarios[] = [
                'id' => $scenario['id'],
                'module' => $scenario['module'],
                'source' => $source,
                'expected' => $scenario['expected'],
                'evidence' => $scenario['evidence'],
            ];
        }

        return $scenarios;
    }

    /** @param array{id: string, module: string, source: ?string, expected: string, evidence: array<string, mixed>} $scenario */
    private function verifyScenario(array $scenario): array
    {
        $state = [
            'module' => $scenario['module'],
            'scenario_id' => $scenario['id'],
            'source' => $scenario['source'],
            'expected_invariant' => $scenario['expected'],
            'evidence' => $scenario['evidence'],
        ];

        $requestBytes = strlen(json_encode([
            'model' => config('typesafe.model'),
            'state' => $state,
            'questions' => $this->questions(),
        ], JSON_THROW_ON_ERROR));

        if ($requestBytes > (int) config('typesafe.max_request_bytes', 200000)) {
            throw new JevClientException("Scenario {$scenario['id']} exceeds the configured evidence limit; split the scenario.");
        }

        $result = $this->jev->evaluate($state, $this->questions());
        $answers = $result['answers'];
        $reachable = $this->noul($answers, 'scenario_is_reachable');
        $supported = $this->noul($answers, 'expected_invariant_supported');
        $current = $this->noul($answers, 'evidence_is_current');
        $coverage = $this->choiceAnswer($answers, 'coverage_status');
        $risk = $this->score($answers, 'risk');
        $minimum = min(1.0, max(0.5, (float) config('typesafe.min_confidence', 0.75)));
        $lower = 1 - $minimum;

        $status = match (true) {
            $reachable <= $lower => 'not_applicable',
            $reachable < $minimum
                || $current < $minimum
                || ($supported > $lower && $supported < $minimum)
                || $coverage['confidence'] < $minimum
                || $risk['confidence'] < $minimum
                || ($coverage['choice'] === 'not_applicable' && $reachable >= $minimum) => 'review',
            in_array($coverage['choice'], ['partial', 'missing'], true) && $supported <= $lower => 'gap',
            $coverage['choice'] === 'covered' && $supported >= $minimum => 'covered',
            default => 'review',
        };

        return [
            'id' => $scenario['id'],
            'module' => $scenario['module'],
            'source' => $scenario['source'],
            'status' => $status,
            'model' => $result['model'],
            'judgments' => [
                'reachable' => $reachable,
                'supported' => $supported,
                'current' => $current,
                'coverage' => $coverage,
                'risk' => $risk,
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function questions(): array
    {
        return [
            'scenario_is_reachable' => [
                'type' => 'noul',
                'instructions' => 'Can `scenario_id` occur in the implementation described by `evidence.implementation` and `evidence.runtime`?',
                'criteria' => [
                    'true' => 'The implementation or runtime evidence describes a reachable path.',
                    'false' => 'The evidence shows that the path cannot occur under the stated system rules.',
                ],
            ],
            'expected_invariant_supported' => [
                'type' => 'noul',
                'instructions' => 'Do `evidence.implementation`, `evidence.tests`, and `evidence.runtime` support `expected_invariant` for `scenario_id`?',
                'criteria' => [
                    'true' => 'The supplied evidence directly supports the expected invariant.',
                    'false' => 'The evidence contradicts the invariant or shows it is absent.',
                ],
            ],
            'evidence_is_current' => [
                'type' => 'noul',
                'instructions' => 'Does the supplied evidence describe the current implementation rather than a historical or remediated state?',
                'criteria' => [
                    'true' => 'The evidence is explicitly current or tied to current code/tests.',
                    'false' => 'The evidence is historical, ambiguous, or clearly superseded.',
                ],
            ],
            'coverage_status' => [
                'type' => 'choice',
                'instructions' => 'What coverage status best describes `scenario_id` from the supplied evidence?',
                'criteria' => [
                    'covered' => 'The implementation and tests adequately handle the scenario.',
                    'partial' => 'Some handling exists, but a meaningful implementation or test gap remains.',
                    'missing' => 'No effective implementation or test coverage is shown.',
                    'not_applicable' => 'The scenario cannot occur under the stated process.',
                ],
            ],
            'risk' => [
                'type' => 'score',
                'instructions' => 'How serious would a gap in `scenario_id` be?',
                'criteria' => [
                    'Minor inconvenience with easy recovery.',
                    'Operational or data-integrity impact.',
                    'Financial, security, compliance, quality, or safety impact.',
                ],
            ],
        ];
    }

    /** @param array<string, array<string, mixed>> $answers */
    private function noul(array $answers, string $id): float
    {
        $value = $answers[$id]['noul'] ?? null;
        if (($answers[$id]['type'] ?? null) !== 'noul'
            || (! is_int($value) && ! is_float($value))) {
            throw new JevClientException("Jev answer {$id} is missing a numeric noul value.");
        }

        $value = (float) $value;
        if ($value < 0 || $value > 1) {
            throw new JevClientException("Jev answer {$id} has an invalid probability.");
        }

        return $value;
    }

    /** @param array<string, array<string, mixed>> $answers @return array{choice: string, confidence: float} */
    private function choiceAnswer(array $answers, string $id): array
    {
        $answer = $answers[$id] ?? [];
        $choice = $answer['choice'] ?? null;
        $confidence = $answer['confidence'] ?? null;
        if (($answer['type'] ?? null) !== 'choice'
            || ! is_string($choice)
            || (! is_int($confidence) && ! is_float($confidence))) {
            throw new JevClientException("Jev answer {$id} is missing choice confidence.");
        }

        $confidence = (float) $confidence;
        if ($confidence < 0 || $confidence > 1) {
            throw new JevClientException("Jev answer {$id} has invalid confidence.");
        }

        return ['choice' => $choice, 'confidence' => $confidence];
    }

    /** @param array<string, array<string, mixed>> $answers @return array{score: float, confidence: float} */
    private function score(array $answers, string $id): array
    {
        $answer = $answers[$id] ?? [];
        $score = $answer['score'] ?? null;
        $confidence = $answer['confidence'] ?? null;
        if (($answer['type'] ?? null) !== 'score'
            || (! is_int($score) && ! is_float($score))
            || (! is_int($confidence) && ! is_float($confidence))) {
            throw new JevClientException("Jev answer {$id} is missing score confidence.");
        }

        $confidence = (float) $confidence;
        if ($confidence < 0 || $confidence > 1) {
            throw new JevClientException("Jev answer {$id} has invalid confidence.");
        }

        return ['score' => (float) $score, 'confidence' => $confidence];
    }

    /** @param list<array<string, mixed>> $results @return array<string, mixed> */
    private function summarize(array $results): array
    {
        $counts = [
            'covered' => 0,
            'gaps' => 0,
            'review' => 0,
            'not_applicable' => 0,
            'service_errors' => 0,
        ];

        foreach ($results as $result) {
            $status = $result['status'];
            if ($status === 'gap') {
                $counts['gaps']++;
            } elseif ($status === 'service_error') {
                $counts['service_errors']++;
            } elseif (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }

        return [
            'status' => ($counts['gaps'] + $counts['review'] + $counts['service_errors']) === 0 ? 'pass' : 'fail',
            'model' => config('typesafe.model'),
            'counts' => $counts,
            'scenarios' => $results,
        ];
    }

    /** @param array<string, mixed> $report */
    private function render(array $report): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return;
        }

        $this->table(
            ['Scenario', 'Module', 'Status'],
            array_map(static fn (array $scenario): array => [
                $scenario['id'],
                $scenario['module'],
                $scenario['status'],
            ], $report['scenarios']),
        );

        $this->line('Jev model: '.$report['model']);
        $this->line('Summary: '.json_encode($report['counts'], JSON_THROW_ON_ERROR));
    }

    private function configurationError(string $message): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => 'error',
                'error' => $message,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->error($message);
        }

        return self::INVALID;
    }
}
