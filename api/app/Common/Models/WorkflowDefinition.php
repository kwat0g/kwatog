<?php

declare(strict_types=1);

namespace App\Common\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowDefinition extends Model
{
    protected $fillable = ['workflow_type', 'name', 'steps', 'is_active'];

    protected $casts = [
        'steps' => 'array',
        'is_active' => 'boolean',
    ];
}
