<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnCaseAttachment extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'event_id',
        'file_name',
        'path',
        'mime_type',
        'size',
        'created_by',
        'customer_portal_user_id',
        'supplier_portal_user_id',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function returnCase(): BelongsTo
    {
        return $this->belongsTo(ReturnCase::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(ReturnCaseEvent::class, 'event_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function customerPortalUser(): BelongsTo
    {
        return $this->belongsTo(CustomerPortalUser::class);
    }

    public function supplierPortalUser(): BelongsTo
    {
        return $this->belongsTo(SupplierPortalUser::class);
    }
}
