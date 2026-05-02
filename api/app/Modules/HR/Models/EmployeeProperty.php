<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeProperty extends Model
{
    use HasFactory, HasHashId;

    protected $table = 'employee_property';
    protected $fillable = ['employee_id', 'item_name', 'description', 'quantity', 'date_issued', 'date_returned', 'status'];
    protected $casts = [
        'date_issued'   => 'date',
        'date_returned' => 'date',
        'quantity'      => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
