<?php

declare(strict_types=1);

namespace App\Common\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowDefinition extends Model
{
    protected $fillable = ['workflow_type', 'name', 'steps'];

    protected $casts = [
        'steps' => 'array',
    ];
}
