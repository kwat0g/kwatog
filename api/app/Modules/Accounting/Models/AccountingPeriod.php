<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Enums\AccountingPeriodStatus;
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OGAMI-001 — GL period-close lock.
 *
 * One row per (year, month). Absence of a row means the month has never been
 * touched and is treated as OPEN (posting allowed). Status transitions:
 *   open → closed → reopened (→ closed again on a future re-close).
 *
 * Service-managed columns (never mass-assigned from controllers):
 *   closed_at / closed_by / reopened_at / reopened_by / reopen_reason.
 *
 * @property int                     $id
 * @property int                     $year
 * @property int                     $month
 * @property AccountingPeriodStatus  $status
 * @property ?Carbon                 $closed_at
 * @property ?int                    $closed_by
 * @property ?Carbon                 $reopened_at
 * @property ?int                    $reopened_by
 * @property ?string                 $reopen_reason
 */
class AccountingPeriod extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'year',
        'month',
        'status',
    ];

    protected $casts = [
        'year'        => 'integer',
        'month'       => 'integer',
        'status'      => AccountingPeriodStatus::class,
        'closed_at'   => 'datetime',
        'reopened_at' => 'datetime',
    ];

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    /**
     * Resolve the period row that owns a given calendar date, or null when no
     * row exists for that month (caller treats null as OPEN).
     */
    public static function forDate(Carbon|string $date): ?self
    {
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);

        return self::query()
            ->where('year', (int) $d->year)
            ->where('month', (int) $d->month)
            ->first();
    }

    public function isClosed(): bool
    {
        return $this->status === AccountingPeriodStatus::Closed;
    }
}
