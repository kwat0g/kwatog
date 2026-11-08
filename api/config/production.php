<?php

declare(strict_types=1);

return [
    // Compatibility default for existing deployments. The simplified workflow
    // sets this false and keeps prior routing records as historical evidence.
    'routing_execution_enabled' => (bool) env('PRODUCTION_ROUTING_EXECUTION_ENABLED', true),
];
