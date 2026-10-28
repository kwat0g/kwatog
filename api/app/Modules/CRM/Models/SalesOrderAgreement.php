<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderAgreement extends Model
{
    use HasAuditLog, HasHashId;

    protected $fillable = ['sales_order_id', 'revision', 'terms', 'snapshot', 'file_path', 'file_name', 'file_mime_type', 'published_by', 'published_at', 'acknowledgment_method', 'representative', 'acknowledged_at', 'recorded_at', 'reference', 'portal_user_id', 'recorded_by'];

    protected $casts = ['snapshot' => 'array', 'published_at' => 'datetime', 'acknowledged_at' => 'datetime', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $agreement): void {
            foreach (['sales_order_id', 'revision', 'terms', 'snapshot', 'file_path', 'file_name', 'file_mime_type', 'published_by', 'published_at'] as $field) {
                if ($agreement->isDirty($field)) {
                    throw new \LogicException('Published sales order agreements are immutable.');
                }
            }
            if ($agreement->getOriginal('acknowledgment_method') !== null) {
                foreach (['acknowledgment_method', 'representative', 'acknowledged_at', 'recorded_at', 'reference', 'portal_user_id', 'recorded_by'] as $field) {
                    if ($agreement->isDirty($field)) {
                        throw new \LogicException('Sales order acknowledgment is immutable.');
                    }
                }
            }
        });
        static::deleting(fn () => throw new \LogicException('Published sales order agreements cannot be deleted.'));
    }

    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class); }
    public function publisher(): BelongsTo { return $this->belongsTo(User::class, 'published_by'); }
    public function portalUser(): BelongsTo { return $this->belongsTo(CustomerPortalUser::class, 'portal_user_id'); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
