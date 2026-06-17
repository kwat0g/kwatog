<?php

declare(strict_types=1);

namespace App\Modules\Landing\Enums;

enum NewsletterStatus: string
{
    case Subscribed   = 'subscribed';
    case Unsubscribed = 'unsubscribed';
}
