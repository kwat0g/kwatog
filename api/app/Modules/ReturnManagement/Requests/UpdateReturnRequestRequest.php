<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Requests;

/** Draft edits use the exact same hash-ID and provenance validation as create. */
class UpdateReturnRequestRequest extends StoreReturnRequestRequest
{
}
