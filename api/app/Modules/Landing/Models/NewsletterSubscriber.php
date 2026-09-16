<?php

declare(strict_types=1);

namespace App\Modules\Landing\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Landing\Enums\NewsletterStatus;
use Illuminate\Database\Eloquent\Model;

class NewsletterSubscriber extends Model
{
    use HasHashId;

    protected $fillable = [
        'email',
        'ip_address',
        'unsubscribed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => NewsletterStatus::class,
            'consent_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }
}
