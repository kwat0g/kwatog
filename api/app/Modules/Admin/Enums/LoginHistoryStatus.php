<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

enum LoginHistoryStatus: string
{
    case Success = 'success';
    case FailedCredentials = 'failed_credentials';
    case FailedLocked = 'failed_locked';
    case FailedInactive = 'failed_inactive';
    case FailedPasswordExpired = 'failed_password_expired';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Success',
            self::FailedCredentials => 'Wrong credentials',
            self::FailedLocked => 'Account locked',
            self::FailedInactive => 'Account inactive',
            self::FailedPasswordExpired => 'Password expired',
        };
    }

    public static function failureValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            [self::FailedCredentials, self::FailedLocked, self::FailedInactive, self::FailedPasswordExpired],
        );
    }
}
