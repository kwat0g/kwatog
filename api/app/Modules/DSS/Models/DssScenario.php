<?php

declare(strict_types=1);

namespace App\Modules\DSS\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Saved what-if scenario (Wave 3). Pure data: computation never writes live records. */
class DssScenario extends Model
{
    use HasFactory, HasHashId;

    protected $table = 'dss_scenarios';

    protected $fillable = [
        'name',
        'created_by',
        'inputs',
        'outputs',
    ];

    protected $casts = [
        'inputs' => 'array',
        'outputs' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
