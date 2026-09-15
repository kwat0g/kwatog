<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfqAddendum extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $table = 'rfq_addenda';

    protected $fillable = [
        'request_for_quote_id', 'published_by', 'sequence', 'title', 'body',
        'material_change', 'published_at',
    ];

    protected $casts = ['sequence' => 'integer', 'material_change' => 'boolean', 'published_at' => 'datetime'];

    public function rfq(): BelongsTo { return $this->belongsTo(RequestForQuote::class, 'request_for_quote_id'); }
    public function publisher(): BelongsTo { return $this->belongsTo(User::class, 'published_by'); }
}
