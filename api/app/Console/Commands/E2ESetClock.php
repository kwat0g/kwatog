<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Support\E2EClock;
use App\Common\Support\E2EEnvironment;
use Illuminate\Console\Command;
use RuntimeException;

final class E2ESetClock extends Command
{
    protected $signature = 'e2e:clock {instant? : ISO-8601 timestamp with a timezone} {--clear : Restore real time}';

    protected $description = 'Set or clear the shared E2E clock (isolated E2E only).';

    public function handle(E2EClock $clock): int
    {
        E2EEnvironment::assertEnabled();
        $instant = $this->argument('instant');
        if ($this->option('clear')) {
            if ($instant !== null) throw new RuntimeException('Use either an instant or --clear.');
            $clock->clear();
            $this->info('E2E clock cleared.');
            return self::SUCCESS;
        }
        if (! is_string($instant) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $instant)) {
            throw new RuntimeException('Provide an ISO-8601 timestamp with an explicit timezone, or use --clear.');
        }

        $frozen = $clock->freeze($instant);
        $this->info('E2E clock set to '.$frozen->toIso8601String().'.');

        return self::SUCCESS;
    }
}
