<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Models\OutboxMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetActualsSyncRun extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $table = 'budget_actuals_sync_runs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'outbox_id', 'request_id', 'fiscal_year_id', 'status',
        'processed_lines', 'total_lines', 'last_error',
        'queued_at', 'started_at', 'completed_at', 'failed_at',
    ];

    protected $casts = [
        'processed_lines' => 'integer',
        'total_lines' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function outbox(): BelongsTo
    {
        return $this->belongsTo(OutboxMessage::class, 'outbox_id');
    }
}
