<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum TrainingMatrixStatus: string
{
    case Trained = 'trained';
    case Expired = 'expired';
    case Gap = 'gap';

    public function label(): string { return ucfirst($this->value); }
}
