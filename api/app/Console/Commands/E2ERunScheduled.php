<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Support\E2EClock;
use App\Common\Support\E2EEnvironment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

final class E2ERunScheduled extends Command
{
    protected $signature = 'e2e:run-scheduled';

    protected $description = 'Run due scheduled tasks once using the E2E clock (isolated E2E only).';

    public function handle(E2EClock $clock): int
    {
        E2EEnvironment::assertEnabled();
        $clock->apply();

        return Artisan::call('schedule:run', ['--no-interaction' => true], $this->output);
    }
}
