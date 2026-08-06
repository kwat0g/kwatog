<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

enum DocumentCategory: string
{
    case Sop = 'sop';
    case WorkInstruction = 'work_instruction';
    case Form = 'form';
    case Spec = 'spec';
    case Policy = 'policy';
}
