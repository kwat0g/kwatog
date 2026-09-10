<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

enum DtrImportRowOutcome: string
{
    case Imported = 'imported';
    case Noop = 'noop';
    case SkippedManual = 'skipped_manual';
}
