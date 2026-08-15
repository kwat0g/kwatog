<?php
declare(strict_types=1);
namespace App\Modules\CRM\Support;
final class SalesOrderTransitionResult
{
    public function __construct(
        public readonly string $outcome,
        public readonly int $statusCode,
        public readonly ?string $from,
        public readonly string $to,
        public readonly ?string $reason = null,
    ) {}
    public function toArray(): array { return ['outcome' => $this->outcome, 'status_code' => $this->statusCode, 'from' => $this->from, 'to' => $this->to, 'reason' => $this->reason]; }
    public function isSuccess(): bool { return $this->outcome === 'succeeded'; }
}
