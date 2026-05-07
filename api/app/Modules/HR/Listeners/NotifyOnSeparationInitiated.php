<?php

declare(strict_types=1);

namespace App\Modules\HR\Listeners;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\SeparationInitiated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Series C — Task C3. When HR initiates separation for an employee, the
 * SeparationService already creates the Clearance with all department
 * items pre-populated. This listener just notifies the relevant
 * department heads that they have items to sign off.
 *
 * Idempotent: notifications carry the clearance hash; duplicate firings
 * leave duplicate notifications but no double-processing of payroll.
 *
 * Best-effort.
 */
class NotifyOnSeparationInitiated implements ShouldQueue
{
    public function handle(SeparationInitiated $event): void
    {
        try {
            $clearance = $event->clearance->loadMissing('employee');

            User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', ['hr_officer', 'finance_officer']))
                ->where('is_active', true)
                ->get()
                ->each(function (User $user) use ($clearance) {
                    $user->notifications()->create([
                        'id'              => (string) Str::uuid(),
                        'type'            => 'chain.separation_initiated',
                        'notifiable_type' => $user::class,
                        'notifiable_id'   => $user->id,
                        'data'            => [
                            'clearance_id' => $clearance->hash_id,
                            'employee'     => $clearance->employee?->full_name,
                            'message'      => "Separation initiated for {$clearance->employee?->full_name}.",
                            'link'         => "/hr/employees/{$clearance->employee?->hash_id}",
                        ],
                        'read_at'         => null,
                    ]);
                });
        } catch (\Throwable $e) {
            Log::warning('NotifyOnSeparationInitiated failed', ['error' => $e->getMessage()]);
        }
    }
}
