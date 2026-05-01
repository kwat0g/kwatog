<?php

declare(strict_types=1);

namespace App\Common\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowDefinition extends Model
{
    protected $fillable = ['workflow_type', 'name', 'steps', 'amount_threshold'];

    protected $casts = [
        'steps'            => 'array',
        'amount_threshold' => 'decimal:2',
    ];
}
