<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Events;

use App\Modules\ReturnManagement\Models\ReturnRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReturnRequestUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ReturnRequest $returnRequest,
        public readonly string $action = 'updated',
    ) {}
}
