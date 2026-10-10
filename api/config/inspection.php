<?php

declare(strict_types=1);

return [
    'checklist_capture_enabled' => (bool) env('QC_CHECKLIST_CAPTURE_ENABLED', false),
    'counted_capture_enabled' => (bool) env('QC_COUNTED_CAPTURE_ENABLED', false),
    'outgoing_confirmed_checks_enabled' => (bool) env('WO_OUTGOING_CONFIRMED_CHECKS_ENABLED', false),
    'finish_first_wo_enabled' => (bool) env('WO_FINISH_FIRST_QC_ENABLED', false),
];
