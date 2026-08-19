<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\Services\ActionCenterTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActionCenterTaskController
{
    public function __construct(private readonly ActionCenterTaskService $service) {}

    /**
     * No try/catch around the service call.
     *
     * This method used to `catch (RuntimeException)` and answer 422 with
     * `$e->getMessage()` — one of the 39 arms 9fde7dfb narrowed, missed because
     * the Dashboard module was held by another session at the time. It carried
     * three unrelated things at once: a malformed item key, an authorization
     * refusal, and — since QueryException extends PDOException extends
     * RuntimeException — every SQL fault raised inside apply()'s transaction,
     * SQLSTATE and column names included.
     *
     * The service now names all three. The render hook in bootstrap/app.php
     * answers a BusinessRuleException with 422 and a ForbiddenActionException
     * with 403, both carrying the message and an `errors` bag the old arm
     * dropped; a QueryException reaches nothing that claims to understand it and
     * is a 500.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'item_ids.*' => ['required', 'string', 'max:190'],
            'action' => ['required', 'in:claim,unclaim,acknowledge,snooze,resolve,reopen'],
            'snoozed_until' => ['nullable', 'required_if:action,snooze', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $tasks = $this->service->apply(
            $data['item_ids'], $data['action'], $request->user(),
            $data['snoozed_until'] ?? null, $data['notes'] ?? null,
        );

        return response()->json(['data' => array_map(fn ($task) => [
            'item_id' => $task->item_key,
            'state' => $task->state,
            'assigned_to' => $task->assignee ? ['id' => $task->assignee->hash_id, 'name' => $task->assignee->name] : null,
            'snoozed_until' => $task->snoozed_until?->toIso8601String(),
        ], $tasks)]);
    }
}
