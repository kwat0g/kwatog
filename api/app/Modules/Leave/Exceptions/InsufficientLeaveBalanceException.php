<?php

declare(strict_types=1);

namespace App\Modules\Leave\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

class InsufficientLeaveBalanceException extends BusinessRuleException {}
