<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\Services\ActionCenterTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ActionCenterTaskController
{
    public function __construct(private readonly ActionCenterTaskService $service) {}

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'item_ids.*' => ['required', 'string', 'max:190'],
            'action' => ['required', 'in:claim,unclaim,acknowledge,snooze,resolve,reopen'],
            'snoozed_until' => ['nullable', 'required_if:action,snooze', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $tasks = $this->service->apply(
                $data['item_ids'], $data['action'], $request->user(),
                $data['snoozed_until'] ?? null, $data['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => array_map(fn ($task) => [
            'item_id' => $task->item_key,
            'state' => $task->state,
            'assigned_to' => $task->assignee ? ['id' => $task->assignee->hash_id, 'name' => $task->assignee->name] : null,
            'snoozed_until' => $task->snoozed_until?->toIso8601String(),
        ], $tasks)]);
    }
}
