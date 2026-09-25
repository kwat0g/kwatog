<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A stale material-return view or a reused idempotency key with new content. */
class MaterialReturnConflictException extends BusinessRuleException {}
