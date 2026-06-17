<?php

declare(strict_types=1);

namespace App\Modules\Landing\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Landing\Enums\QuoteRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuoteRequest extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'request_no',
        'full_name',
        'company',
        'email',
        'part_description',
        'annual_volume',
        'drawing_path',
        'drawing_original_name',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'status'        => QuoteRequestStatus::class,
            'annual_volume' => 'integer',
        ];
    }
}
