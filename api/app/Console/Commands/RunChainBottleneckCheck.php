<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use App\Common\Models\Alert;
use App\Common\Services\AlertEngineService;
use App\Common\Services\ChainBottleneckService;
use Illuminate\Console\Command;

/**
 * Series C — Task C5. Hourly scan for chain bottlenecks. Each stuck record
 * is mirrored into the alerts table (de-duplicated by AlertEngineService::raise)
 * so the existing notification + email infrastructure picks it up too.
 */
class RunChainBottleneckCheck extends Command
{
    protected $signature   = 'chain:check-bottlenecks';
    protected $description = 'Scan for chain bottlenecks and raise alerts (Series C — Task C5)';

    public function handle(ChainBottleneckService $detector, AlertEngineService $alerts): int
    {
        $start = microtime(true);
        $all = $detector->detectAll();
        $raised = 0;

        foreach ($all as $rows) {
            foreach ($rows as $row) {
                // Use a fake-entity Alert pinned by metadata: we don't load
                // the actual model here (avoids cross-module dependencies).
                // AlertEngineService::raise() de-dups by (type, entity_type,
                // entity_id) within the last 24h.
                $alert = Alert::query()
                    ->where('type', AlertType::ChainBottleneck->value)
                    ->where('is_dismissed', false)
                    ->where('created_at', '>=', now()->subHours(24))
                    ->where('entity_type', $row['entity_type'])
                    ->where('entity_id', $row['entity_id'])
                    ->first();
                if ($alert) continue;

                Alert::create([
                    'type'        => AlertType::ChainBottleneck->value,
                    'severity'    => AlertSeverity::Warning->value,
                    'title'       => $row['label'],
                    'message'     => sprintf(
                        '%s %s stuck at %s for %d hours.',
                        ucfirst(str_replace('_', ' ', $row['entity_type'])),
                        $row['doc_number'],
                        $row['status'],
                        (int) ($row['hours_stuck'] ?? 0),
                    ),
                    'entity_type' => $row['entity_type'],
                    'entity_id'   => $row['entity_id'],
                    'metadata'    => [
                        'bottleneck_key' => $row['key'],
                        'audience'       => $row['audience'],
                        'doc_number'     => $row['doc_number'],
                        'hours_stuck'    => $row['hours_stuck'] ?? null,
                    ],
                ]);
                $raised++;
            }
        }

        $ms = (int) round((microtime(true) - $start) * 1000);
        $this->info("Chain bottleneck scan completed in {$ms}ms — raised {$raised} new alerts.");
        return self::SUCCESS;
    }
}
