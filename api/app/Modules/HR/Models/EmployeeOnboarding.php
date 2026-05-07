<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeOnboarding extends Model
{
    use HasHashId;

    protected $fillable = [
        'employee_id',
        'profile_completed_at',
        'shift_assigned_at',
        'leave_balances_initialized_at',
        'account_provisioned_at',
        'dept_team_notified_at',
        'gov_ids_recorded_at',
        'banking_recorded_at',
        'completed_at',
        'reminder_sent_at',
    ];

    protected $casts = [
        'profile_completed_at'          => 'datetime',
        'shift_assigned_at'             => 'datetime',
        'leave_balances_initialized_at' => 'datetime',
        'account_provisioned_at'        => 'datetime',
        'dept_team_notified_at'         => 'datetime',
        'gov_ids_recorded_at'           => 'datetime',
        'banking_recorded_at'           => 'datetime',
        'completed_at'                  => 'datetime',
        'reminder_sent_at'              => 'datetime',
    ];

    /**
     * Ordered list of onboarding step keys.
     *
     * @return array<int, string>
     */
    public static function stepKeys(): array
    {
        return [
            'profile_completed',
            'shift_assigned',
            'leave_balances_initialized',
            'account_provisioned',
            'dept_team_notified',
            'gov_ids_recorded',
            'banking_recorded',
        ];
    }

    public static function stepLabel(string $key): string
    {
        return match ($key) {
            'profile_completed'           => 'Profile',
            'shift_assigned'              => 'Shift',
            'leave_balances_initialized'  => 'Leave Balances',
            'account_provisioned'         => 'System Account',
            'dept_team_notified'          => 'Dept Team Notified',
            'gov_ids_recorded'            => 'Government IDs',
            'banking_recorded'            => 'Banking Info',
            default                        => ucwords(str_replace('_', ' ', $key)),
        };
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isComplete(): bool
    {
        foreach (self::stepKeys() as $key) {
            if ($this->{$key.'_at'} === null) {
                return false;
            }
        }
        return true;
    }
}
