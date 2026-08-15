<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Modules\Auth\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Converts an email delivery failure into a durable internal inbox alert.
 *
 * External recipients such as customers, suppliers, and job candidates do
 * not necessarily have an ERP user account. The fallback therefore targets
 * the internal operators responsible for the business process.
 */
class EmailDeliveryFailureNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @param  User|Collection<int, User>|array<int, User>|null  $recipients
     * @param  array{link_to?: string, entity_type?: string, entity_id?: string, reason?: string}  $context
     */
    public function notify(
        User|Collection|array|null $recipients,
        string $feature,
        string $message,
        array $context = [],
    ): void {
        if ($recipients === null) {
            return;
        }

        try {
            $this->notifications->sendInApp($recipients, 'email.delivery_failed', [
                'title' => 'Email delivery failed',
                'message' => $feature.': '.$message,
                'link_to' => $context['link_to'] ?? null,
                'entity_type' => $context['entity_type'] ?? null,
                'entity_id' => $context['entity_id'] ?? null,
                'failure_reason' => $context['reason'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // A fallback must never replace the original business failure or
            // cause a queue worker to retry forever just because the inbox is
            // temporarily unavailable.
            Log::error('Email delivery fallback notification failed', [
                'feature' => $feature,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, int|string>  $userIds
     * @param  array{link_to?: string, entity_type?: string, entity_id?: string, reason?: string}  $context
     */
    public function notifyUserIds(
        array $userIds,
        string $feature,
        string $message,
        array $context = [],
    ): void {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return;
        }

        $this->notify(
            User::query()->whereIn('id', $ids)->where('is_active', true)->get(),
            $feature,
            $message,
            $context,
        );
    }

    /**
     * @param  array{link_to?: string, entity_type?: string, entity_id?: string, reason?: string}  $context
     */
    public function notifyUserId(?int $userId, string $feature, string $message, array $context = []): void
    {
        if ($userId === null) {
            return;
        }

        $this->notifyUserIds([$userId], $feature, $message, $context);
    }

    /**
     * Notify active internal users who hold a permission for the affected
     * business area. This keeps external-recipient failures actionable even
     * when the customer or supplier has no portal account.
     *
     * @param  array{link_to?: string, entity_type?: string, entity_id?: string, reason?: string}  $context
     */
    public function notifyPermission(
        string $permission,
        string $feature,
        string $message,
        array $context = [],
    ): void {
        $this->notify(
            User::query()
                ->whereHas('role.permissions', fn ($query) => $query->where('slug', $permission))
                ->where('is_active', true)
                ->get(),
            $feature,
            $message,
            $context,
        );
    }

    /** @return list<int> */
    public function userIdsWithPermission(string $permission): array
    {
        return User::query()
            ->whereHas('role.permissions', fn ($query) => $query->where('slug', $permission))
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
