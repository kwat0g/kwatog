<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Enums\RfqInvitationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequestForQuoteInvitation extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'request_for_quote_id', 'vendor_id', 'invited_by', 'invited_at', 'viewed_at',
        'exception_reason', 'portal_notified_at', 'email_notified_at', 'last_notification_error',
    ];

    protected $casts = [
        'status' => RfqInvitationStatus::class,
        'invited_at' => 'datetime',
        'viewed_at' => 'datetime',
        'portal_notified_at' => 'datetime',
        'email_notified_at' => 'datetime',
    ];

    public function rfq(): BelongsTo { return $this->belongsTo(RequestForQuote::class, 'request_for_quote_id'); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function inviter(): BelongsTo { return $this->belongsTo(User::class, 'invited_by'); }
    public function quotes(): HasMany { return $this->hasMany(SupplierQuote::class, 'invitation_id'); }
}
