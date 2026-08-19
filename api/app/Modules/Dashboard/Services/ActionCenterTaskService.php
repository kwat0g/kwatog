<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Exceptions\ForbiddenActionException;
use App\Common\Models\Alert;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Models\ActionCenterTask;
use Illuminate\Support\Facades\DB;

class ActionCenterTaskService
{
    /** @param array<int, string> $itemKeys @return array<int, ActionCenterTask> */
    public function apply(array $itemKeys, string $action, User $user, ?string $snoozedUntil = null, ?string $notes = null): array
    {
        $keys = array_values(array_unique($itemKeys));
        if (count($keys) > 100) {
            throw new BusinessRuleException('A bulk action is limited to 100 records.');
        }

        $results = DB::transaction(function () use ($keys, $action, $user, $snoozedUntil, $notes): array {
            $results = [];
            foreach ($keys as $key) {
                $this->assertAllowed($key, $user);
                $task = ActionCenterTask::query()->where('item_key', $key)->lockForUpdate()->first();
                $from = $task?->state;
                $task ??= new ActionCenterTask(['item_key' => $key, 'state' => 'open']);

                match ($action) {
                    'claim' => $task->fill(['assigned_to' => $user->id, 'state' => 'acknowledged', 'snoozed_until' => null]),
                    'unclaim' => $task->fill(['assigned_to' => null]),
                    'acknowledge' => $task->fill(['state' => 'acknowledged', 'snoozed_until' => null]),
                    'snooze' => $task->fill(['state' => 'snoozed', 'snoozed_until' => $snoozedUntil]),
                    'resolve' => $task->fill(['state' => 'resolved', 'snoozed_until' => null]),
                    'reopen' => $task->fill(['state' => 'open', 'snoozed_until' => null]),
                    default => throw new BusinessRuleException('Unsupported action-center task action.'),
                };
                $task->notes = $notes ?? $task->notes;
                $task->updated_by = $user->id;
                $task->save();
                $task->events()->create([
                    'action' => $action,
                    'from_state' => $from,
                    'to_state' => $task->state,
                    'metadata' => ['snoozed_until' => $snoozedUntil, 'notes' => $notes],
                    'acted_by' => $user->id,
                ]);

                if ($action === 'resolve' && str_starts_with($key, 'alert:') && $user->hasPermission('alerts.dismiss')) {
                    $hash = substr($key, strlen('alert:'));
                    $decoded = app('hashids')->decode($hash);
                    if ($decoded) {
                        Alert::query()->whereKey($decoded[0])->update([
                            'is_dismissed' => true, 'dismissed_by' => $user->id, 'dismissed_at' => now(),
                        ]);
                    }
                }

                $results[] = $task->load('assignee:id,name');
            }

            return $results;
        });

        BadgeService::touch();

        return $results;
    }

    /**
     * Gate one item key against the permissions its source requires.
     *
     * Both refusals used to be a bare RuntimeException, which the controller
     * turned into a 422 with the message. One of them is not a 422:
     *
     *  - an unknown key prefix is a malformed request, and 'Unsupported
     *    action-center task action.' above already answers its sibling case with
     *    BusinessRuleException, so this matches it;
     *  - "you do not have access" is an authorization refusal. It reached the
     *    user as "fix your input" for a request whose input was fine.
     *
     * Typing both also takes the controller out of the SQL-leak business: a
     * deadlock or unique violation inside apply()'s transaction is a
     * QueryException, which extends RuntimeException, so the arm that carried
     * these two also put SQLSTATE, table and column names into a 422 message.
     */
    private function assertAllowed(string $key, User $user): void
    {
        $permissions = match (true) {
            str_starts_with($key, 'approval:') => ['approvals.board.view'],
            str_starts_with($key, 'alert:') => ['alerts.view'],
            str_starts_with($key, 'quality:') => ['quality.view', 'quality.ncr.view'],
            str_starts_with($key, 'maintenance:') => ['maintenance.view'],
            str_starts_with($key, 'production:') => ['production.work_orders.view'],
            str_starts_with($key, 'supply-chain:') => ['supply_chain.view'],
            default => [],
        };
        if ($permissions === []) {
            throw new BusinessRuleException('Unknown action-center item.');
        }
        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return;
            }
        }
        throw new ForbiddenActionException('You do not have access to this action-center item.');
    }
}
