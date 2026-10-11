<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Dashboard\Support\Analytics\MetricRegistry;
use Illuminate\Console\Command;

/**
 * Render the metric dictionary from `MetricRegistry`.
 *
 * The doc is GENERATED, not maintained: `docs/dashboards/METRICS.md` is written
 * by this command, so a metric cannot be described in the doc and not exist in
 * the code (or vice versa). The equivalent of the seeder drift guard, for the
 * dictionary.
 *
 * `--check` fails when the checked-in file differs from the registry, which is
 * the CI-able form — but it needs a filesystem where `docs/` is reachable
 * (checked out next to `api/`, not the api-only container mount), so it is a
 * manual/CI step rather than part of the PHPUnit suite.
 */
class RenderMetricsDoc extends Command
{
    protected $signature = 'metrics:doc
        {--output= : Write the Markdown to this path instead of stdout}
        {--check : Do not write; exit non-zero when the file differs from the registry}';

    protected $description = 'Render the dashboard metric dictionary (docs/dashboards/METRICS.md) from MetricRegistry';

    public function handle(MetricRegistry $registry): int
    {
        $markdown = $registry->renderMarkdown();
        $path = $this->resolvePath();

        if ($this->option('check')) {
            if ($path === null || ! is_file($path)) {
                $this->error('Cannot check: no metric dictionary file found. Pass --output=<path>.');

                return self::FAILURE;
            }

            $current = (string) file_get_contents($path);

            if ($current !== $markdown) {
                $this->error("Metric dictionary is stale: {$path}");
                $this->line('Regenerate with: php artisan metrics:doc --output='.$path);

                return self::FAILURE;
            }

            $this->info("Metric dictionary is current ({$path}).");

            return self::SUCCESS;
        }

        if ($path === null) {
            $this->line($markdown);

            return self::SUCCESS;
        }

        if (file_put_contents($path, $markdown) === false) {
            $this->error("Could not write {$path}");

            return self::FAILURE;
        }

        $this->info('Wrote '.$path.' ('.count($registry->all()).' metrics, fingerprint '.$registry->fingerprint().').');

        return self::SUCCESS;
    }

    /**
     * Where the doc lives. Candidates, in order: the explicit option, the
     * sibling `docs/` of a full checkout, and the api container's own
     * `resources/docs` fallback so the command still works in a trimmed mount.
     */
    private function resolvePath(): ?string
    {
        $explicit = $this->option('output');

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $candidates = [
            base_path('../docs/dashboards/METRICS.md'),
            base_path('docs/dashboards/METRICS.md'),
        ];

        foreach ($candidates as $candidate) {
            if (is_dir(dirname($candidate))) {
                return $candidate;
            }
        }

        return null;
    }
}
