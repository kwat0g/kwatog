<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Observers;

use App\Modules\ReturnManagement\Models\ReturnCaseAttachment;
use App\Modules\ReturnManagement\Services\ReturnCaseRealtimeService;

final class ReturnCaseAttachmentRealtimeObserver
{
    public function created(ReturnCaseAttachment $attachment): void
    {
        $this->stage($attachment);
    }

    public function deleted(ReturnCaseAttachment $attachment): void
    {
        $this->stage($attachment);
    }

    private function stage(ReturnCaseAttachment $attachment): void
    {
        $case = $attachment->returnCase()->first();
        if ($case) {
            app(ReturnCaseRealtimeService::class)->stage($case, 'updated');
        }
    }
}
