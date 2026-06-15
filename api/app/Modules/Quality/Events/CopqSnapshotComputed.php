<?php

declare(strict_types=1);

namespace App\Modules\Quality\Events;

use App\Modules\Quality\Models\CopqSnapshot;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class CopqSnapshotComputed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly CopqSnapshot $snapshot) {}
}
