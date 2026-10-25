<?php

return [
    'event_retention_approval_reference' => env('DSS_EVENT_RETENTION_APPROVAL_REFERENCE'),
    'notification_daily_cap' => max(0, (int) env('DSS_NOTIFICATION_DAILY_CAP', 3)),
];
