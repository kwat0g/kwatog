<?php

declare(strict_types=1);

namespace App\Modules\DSS\Detectors;

use App\Modules\Accounting\Services\InvoiceService;
use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;

/**
 * D09 — collections priority. Reuses InvoiceService::aging() (the AR aging
 * report's own engine: same settled-vs-balance math the finance pages show)
 * and adds what the report lacks: a prioritized, lifecycle-managed
 * recommendation per late aging bucket.
 *
 * One aggregated emission per late bucket (never one row per invoice).
 * `current` and `d1_30` stay out — they are the per-invoice AR alerts' window
 * (ArOverdue30/ArOverdue60). Rows are labeled audience finance_officer;
 * per-row enforcement lives in RecommendationService. Deterministic counts →
 * confidence 1.0. Read-only.
 */
class CollectionPriorityDetector implements DetectorInterface
{
    public const ID = 'dss.collection_priority';

    /** late bucket → severity */
    private const LATE_BUCKETS = [
        'd31_60' => 'warning',
        'd61_90' => 'critical',
        'd91_plus' => 'critical',
    ];

    public function __construct(
        private readonly InvoiceService $invoices,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function domain(): string
    {
        return 'finance';
    }

    public function ownerPermissions(): array
    {
        // Reachability only. Row-level access is enforced per audience_roles
        // in RecommendationService.
        return ['dss.view'];
    }

    public function version(): string
    {
        return 'v1';
    }

    public function defaultParams(): array
    {
        return ['max_evidence_per_bucket' => 5];
    }

    /** @return list<DetectorEmission> */
    public function detect(array $context = []): array
    {
        $maxEvidence = max(1, (int) ($context['max_evidence'] ?? $this->defaultParams()['max_evidence_per_bucket']));
        $aging = $this->invoices->aging();

        $emissions = [];

        foreach (self::LATE_BUCKETS as $bucket => $severity) {
            $customers = [];
            foreach ($aging['by_customer'] as $row) {
                $amount = (string) ($row[$bucket] ?? '0.00');
                if (bccomp($amount, '0.00', 2) === 1) {
                    $customers[] = ['name' => (string) $row['customer_name'], 'amount' => $amount];
                }
            }
            if ($customers === []) {
                continue;
            }

            usort($customers, static fn (array $a, array $b): int => bccomp($b['amount'], $a['amount'], 2));

            $emissions[] = new DetectorEmission(
                type: 'collection_priority',
                severity: $severity,
                dedupeKey: "ar-aging:{$bucket}",
                rationale: [
                    'rule' => 'AR aging scan',
                    'bucket' => $bucket,
                    'total_amount' => (string) ($aging['buckets'][$bucket] ?? '0.00'),
                    'affected_customer_count' => count($customers),
                    'capped_at_max_evidence' => count($customers) > $maxEvidence,
                    'audience_roles' => ['finance_officer'],
                    'evidence' => array_map(
                        static fn (array $c): string => "{$c['name']} — {$c['amount']}",
                        array_slice($customers, 0, $maxEvidence),
                    ),
                    'why_it_matters' => 'Receivables reaching the late buckets stop recovering the longer they wait — top them by amount for collections.',
                ],
                confidence: 1.0,
                action: ['route' => '/accounting/invoices', 'label' => 'Open invoices →'],
            );
        }

        return $emissions;
    }
}
