<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankFileRecord extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'payroll_period_id',
        'file_path',
        'record_count',
        'total_amount',
        'generated_by',
        'generated_at',
    ];

    protected $casts = [
        'record_count' => 'integer',
        'total_amount' => 'decimal:2',
        'generated_at' => 'datetime',
        'created_at'   => 'datetime',
    ];

    public $timestamps = false;

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
