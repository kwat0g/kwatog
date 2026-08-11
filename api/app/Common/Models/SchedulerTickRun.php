<?php

declare(strict_types=1);

namespace App\Common\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulerTickRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected $table = 'scheduler_tick_runs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'status',
        'failed_tasks',
        'exit_code',
        'started_at',
        'finished_at',
        'last_error',
    ];

    protected $casts = [
        'failed_tasks' => 'integer',
        'exit_code' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
