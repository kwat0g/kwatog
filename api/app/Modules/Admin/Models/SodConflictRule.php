<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Admin\Enums\SodSeverity;
use App\Modules\Auth\Models\Permission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REC-01 — one incompatible permission pair in the SoD matrix.
 */
class SodConflictRule extends Model
{
    use HasHashId, HasAuditLog;

    protected $fillable = [
        'code',
        'name',
        'permission_a_id',
        'permission_b_id',
        'severity',
        'rationale',
        'active',
    ];

    protected $casts = [
        'active'   => 'boolean',
        'severity' => SodSeverity::class,
    ];

    public function permissionA(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_a_id');
    }

    public function permissionB(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_b_id');
    }
}
