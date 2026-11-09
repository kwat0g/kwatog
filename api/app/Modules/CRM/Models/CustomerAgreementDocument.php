<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\PortalContractTemplate;
use App\Modules\B2B\Models\CustomerPortalUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAgreementDocument extends Model
{
    use HasHashId;

    protected $fillable = [
        'customer_id', 'file_path', 'filename', 'size', 'mime', 'uploaded_by', 'signature_status',
        'document_sha256', 'template_id', 'template_version', 'signed_by_portal_user_id', 'signed_name',
        'signed_at', 'signed_document_sha256', 'issued_by', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer', 'template_version' => 'integer',
            'signed_at' => 'immutable_datetime', 'issued_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            $allowed = [
                'signature_status', 'document_sha256', 'signed_by_portal_user_id', 'signed_name', 'signed_at',
                'signed_document_sha256', 'issued_by', 'issued_at',
            ];
            $changed = array_keys($document->getDirty());
            if (array_diff($changed, $allowed) !== [] || $document->getOriginal('signed_at') !== null) {
                throw new \LogicException('Customer agreement document versions are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Customer agreement document versions cannot be deleted.'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PortalContractTemplate::class, 'template_id');
    }

    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(CustomerPortalUser::class, 'signed_by_portal_user_id')->withTrashed();
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
