<?php

declare(strict_types=1);

namespace App\Modules\Landing\Models;

use App\Common\Traits\HasHashId;
use App\Modules\CRM\Models\Lead;
use App\Modules\Landing\Enums\ContactInquiryStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A submission from the public contact form.
 *
 * No user FK: submissions arrive before any account exists, and most senders
 * will never have one. `ip_address` / `user_agent` are kept for abuse triage,
 * since the endpoint is unauthenticated by design.
 */
class ContactInquiry extends Model
{
    use HasFactory, HasHashId, SoftDeletes;

    /**
     * `status` and `inquiry_no` are omitted deliberately — both are assigned by
     * the service, never by request input.
     */
    protected $fillable = [
        'full_name',
        'company',
        'email',
        'phone',
        'message',
        'ip_address',
        'user_agent',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ContactInquiryStatus::class,
        ];
    }

    public function convertedToLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'converted_to_lead_id');
    }
}
