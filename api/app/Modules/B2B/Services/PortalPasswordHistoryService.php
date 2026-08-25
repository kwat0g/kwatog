<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Services\SettingsService;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\PortalPasswordHistory;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PortalPasswordHistoryService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function assertAllowed(CustomerPortalUser|SupplierPortalUser $user, string $newPassword): void
    {
        if (Hash::check($newPassword, (string) $user->password)) {
            throw ValidationException::withMessages([
                'new_password' => 'The new password must be different from your current password.',
            ]);
        }

        $depth = $this->depth();
        if ($depth === 0) {
            return;
        }

        $history = PortalPasswordHistory::query()
            ->where('portal_type', $this->type($user))
            ->where('portal_user_id', $user->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($depth)
            ->pluck('password_hash');

        foreach ($history as $oldHash) {
            if (Hash::check($newPassword, (string) $oldHash)) {
                throw ValidationException::withMessages([
                    'new_password' => 'You have used this password recently. Choose a different one.',
                ]);
            }
        }
    }

    public function record(CustomerPortalUser|SupplierPortalUser $user, string $oldPasswordHash): void
    {
        $depth = $this->depth();
        if ($depth === 0 || trim($oldPasswordHash) === '') {
            return;
        }

        PortalPasswordHistory::create([
            'portal_type' => $this->type($user),
            'portal_user_id' => $user->getKey(),
            'password_hash' => $oldPasswordHash,
            'created_at' => now(),
        ]);

        $keepIds = PortalPasswordHistory::query()
            ->where('portal_type', $this->type($user))
            ->where('portal_user_id', $user->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($depth)
            ->pluck('id');

        PortalPasswordHistory::query()
            ->where('portal_type', $this->type($user))
            ->where('portal_user_id', $user->getKey())
            ->whereNotIn('id', $keepIds)
            ->delete();
    }

    private function depth(): int
    {
        return max(0, $this->settings->requiredInt('security.password_history_depth', 0, 10));
    }

    private function type(CustomerPortalUser|SupplierPortalUser $user): string
    {
        return $user instanceof CustomerPortalUser ? 'customer' : 'supplier';
    }
}
