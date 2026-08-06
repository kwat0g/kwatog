<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum WarehouseScanContext: string
{
    case Lookup = 'lookup';
    case Grn = 'grn_id';
    case MaterialIssue = 'material_issue_id';
    case StockCount = 'stock_count_session_id';
    case WorkOrder = 'wo_id';

    public function label(): string
    {
        return match ($this) {
            self::Lookup => 'General lookup',
            self::Grn => 'Receiving / GRN',
            self::MaterialIssue => 'Picking / issuance',
            self::StockCount => 'Stock count',
            self::WorkOrder => 'Work order issue',
        };
    }
}
