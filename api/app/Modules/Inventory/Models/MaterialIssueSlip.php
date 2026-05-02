<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\MaterialIssueStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialIssueSlip extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'slip_number', 'work_order_id', 'issued_date',
        'issued_by', 'created_by', 'status',
        'total_value', 'reference_text', 'remarks',
    ];

    protected $casts = [
        'issued_date' => 'date',
        'total_value' => 'decimal:2',
        'status'      => MaterialIssueStatus::class,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(MaterialIssueSlipItem::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
