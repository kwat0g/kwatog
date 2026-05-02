<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    use HasFactory, HasHashId;

    public $timestamps = false;
    protected $fillable = ['employee_id', 'document_type', 'file_name', 'file_path', 'uploaded_at', 'created_at'];
    protected $casts = ['uploaded_at' => 'datetime', 'created_at' => 'datetime'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
