<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Auth\Models\User;

/**
 * M-40 — Tuple returned from UserAdminService::createStandalone so callers
 * outside of an HTTP request (Artisan commands, queued jobs) can read the
 * one-time temp password without going through request()->attributes.
 */
final readonly class CreatedUser
{
    public function __construct(
        public User $user,
        public string $tempPassword,
    ) {}
}
