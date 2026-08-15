<?php
declare(strict_types=1);
namespace App\Modules\CRM\Models;
use Illuminate\Database\Eloquent\Model;
class SalesOrderTransitionRejection extends Model
{
    protected $fillable = ['sales_order_id', 'from_status', 'to_status', 'reason_code', 'reason', 'requested_by'];
}
