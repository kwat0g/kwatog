<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Common\Traits\HasHashId;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutingOperation extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'routing_id',
        'sequence',
        'operation_name',
        'work_center',
        'machine_id',
        'mold_id',
        'setup_time_minutes',
        'cycle_time_minutes',
        'description',
        'qc_required',
    ];

    protected $casts = [
        'setup_time_minutes' => 'decimal:2',
        'cycle_time_minutes' => 'decimal:2',
        'qc_required'        => 'boolean',
        'sequence'            => 'integer',
    ];

    public function routing(): BelongsTo
    {
        return $this->belongsTo(ProductRouting::class, 'routing_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function mold(): BelongsTo
    {
        return $this->belongsTo(Mold::class);
    }
}
