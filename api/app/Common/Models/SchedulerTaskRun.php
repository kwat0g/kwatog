<?php

declare(strict_types=1);

namespace App\Common\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchedulerTaskRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected $table = 'scheduler_task_runs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'task_key',
        'task_name',
        'command',
        'expression',
        'status',
        'scheduler_tick_id',
        'started_at',
        'finished_at',
        'runtime_seconds',
        'last_error',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'runtime_seconds' => 'decimal:2',
    ];

    public function schedulerTick(): BelongsTo
    {
        return $this->belongsTo(SchedulerTickRun::class, 'scheduler_tick_id');
    }
}
