<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use App\Common\Models\Alert;
use App\Common\Services\AlertEngineService;
use App\Common\Services\ChainBottleneckService;
use App\Common\Services\SettingsService;
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

    public function handle(ChainBottleneckService $detector, AlertEngineService $alerts, SettingsService $settings): int
    {
        $start = microtime(true);
        $all = $detector->detectAll();
        $raised = 0;

        foreach ($all as $rows) {
            foreach ($rows as $row) {
                // ChainBottleneckService emits `entity_id` as a hash_id because
                // its other consumer is the SPA widget (ID-obfuscation rule).
                // `alerts.entity_id` is a bigint, so decode before touching it —
                // passing the hash straight through made this hourly cron fatal
                // with SQLSTATE[22P02] invalid input syntax for type bigint.
                $entityId = $this->decodeEntityId($row['entity_id'] ?? null);
                if ($entityId === null) {
                    $this->warn("Skipping bottleneck row with unusable entity_id: {$row['doc_number']}");
                    continue;
                }

                // Use a fake-entity Alert pinned by metadata: we don't load
                // the actual model here (avoids cross-module dependencies).
                // AlertEngineService::raise() de-dups by (type, entity_type,
                // entity_id) within the configured deduplication window.
                $dedupWindowHours = $settings->requiredInt('alerts.dedup_window_hours', 1);
                $alert = Alert::query()
                    ->where('type', AlertType::ChainBottleneck->value)
                    ->where('is_dismissed', false)
                    ->where('created_at', '>=', now()->subHours($dedupWindowHours))
                    ->where('entity_type', $row['entity_type'])
                    ->where('entity_id', $entityId)
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
                    'entity_id'   => $entityId,
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

    /**
     * Turn the service's hash_id into the raw bigint `alerts.entity_id` wants.
     * Already-numeric values pass through so the command stays correct if the
     * service ever emits raw keys.
     */
    private function decodeEntityId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw)) {
            return $raw;
        }
        $str = (string) $raw;
        if (ctype_digit($str)) {
            return (int) $str;
        }
        $decoded = app('hashids')->decode($str);

        return empty($decoded) ? null : (int) $decoded[0];
    }
}
