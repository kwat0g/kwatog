<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

enum RfqInvitationStatus: string
{
    case Invited = 'invited';
    case Viewed = 'viewed';
    case Submitted = 'submitted';
    case Awarded = 'awarded';
    case NotAwarded = 'not_awarded';
}
