<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Events;

use App\Modules\Attendance\Models\OvertimeRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OvertimeRequestSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public OvertimeRequest $overtimeRequest) {}
}
