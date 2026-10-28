<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAgreementDocument extends Model
{
    use HasHashId;

    protected $fillable = ['customer_id', 'file_path', 'filename', 'size', 'mime', 'uploaded_by'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Customer agreement document versions are immutable.'));
        static::deleting(fn () => throw new \LogicException('Customer agreement document versions cannot be deleted.'));
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
