<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\GrnItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionDefectEvidence extends Model
{
    use HasHashId;

    protected $fillable = [
        'inspection_id', 'grn_item_id', 'category', 'other_category', 'severity',
        'affected_quantity', 'notes', 'file_path', 'file_name', 'mime_type', 'file_size', 'uploaded_by',
    ];

    protected $casts = [
        'affected_quantity' => 'decimal:3',
        'file_size' => 'integer',
    ];

    protected $hidden = ['file_path'];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    public function grnItem(): BelongsTo
    {
        return $this->belongsTo(GrnItem::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
