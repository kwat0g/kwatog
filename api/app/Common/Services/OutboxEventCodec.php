<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Events\ChainStepAdvanced;
use App\Common\Events\PermissionOverrideChanged;
use App\Modules\Accounting\Events\BudgetActualsSyncRequested;
use App\Modules\Assets\Events\MonthlyDepreciationRequested;
use App\Modules\Attendance\Events\OvertimeRequestDecided;
use App\Modules\Attendance\Events\OvertimeRequestSubmitted;
use App\Modules\CRM\Events\ComplaintNcrRequested;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\Dashboard\Events\BadgesChanged;
use App\Modules\HR\Events\ClearanceFullySigned;
use App\Modules\HR\Events\EmployeeCreated;
use App\Modules\HR\Events\SeparationInitiated;
use App\Modules\Inventory\Events\GoodsReceiptNoteAccepted;
use App\Modules\Inventory\Events\GoodsReceiptNoteCreated;
use App\Modules\Inventory\Events\LowStockPrCreated;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Events\StockMovementGlPostingRequested;
use App\Modules\Leave\Events\LeaveRequestApproved;
use App\Modules\Leave\Events\LeaveRequestPendingHR;
use App\Modules\Leave\Events\LeaveRequestRejected;
use App\Modules\Leave\Events\LeaveRequestSubmitted;
use App\Modules\Leave\Events\YearEndLeaveProcessingRequested;
use App\Modules\Loans\Events\LoanDecided;
use App\Modules\Loans\Events\LoanSubmitted;
use App\Modules\Maintenance\Events\MaintenanceWorkOrderCreated;
use App\Modules\Maintenance\Events\PreventiveMaintenanceGenerationRequested;
use App\Modules\MRP\Events\MachineStatusChanged;
use App\Modules\MRP\Events\MoldShotLimitNearing;
use App\Modules\MRP\Events\MoldShotLimitReached;
use App\Modules\MRP\Events\MrpPlanGenerated;
use App\Modules\Payroll\Events\PayrollComputationRequested;
use App\Modules\Payroll\Events\PayrollGlPostingRequested;
use App\Modules\Payroll\Events\PayrollPeriodDisbursed;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Events\PayrollPeriodVoided;
use App\Modules\Production\Events\MachineBreakdownDetected;
use App\Modules\Production\Events\ProductionReceiptRequested;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Production\Events\WorkOrderOutputRecorded;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Purchasing\Events\PurchaseOrderApproved;
use App\Modules\Purchasing\Events\PurchaseOrderCancelled;
use App\Modules\Purchasing\Events\PurchaseOrderSent;
use App\Modules\Purchasing\Events\PurchaseRequestApproved;
use App\Modules\Purchasing\Events\SupplierPerformanceComputed;
use App\Modules\Quality\Events\InspectionFailed;
use App\Modules\Quality\Events\InspectionPassed;
use App\Modules\Quality\Events\NcrRecurrenceLinked;
use App\Modules\ReturnManagement\Events\ReturnInspectionRequested;
use App\Modules\SupplyChain\Events\DeliveryConfirmed;
use App\Modules\SupplyChain\Events\DeliveryInvoiceRequested;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use RuntimeException;

/**
 * Encode and hydrate the small, explicit event set that crosses module
 * boundaries. The allow-list is intentional: an event type read from the
 * database must never become an arbitrary class-instantiation primitive.
 */
class OutboxEventCodec
{
    /** @var list<class-string> */
    private const SUPPORTED_EVENTS = [
        ChainStepAdvanced::class,
        PermissionOverrideChanged::class,
        BadgesChanged::class,
        SalesOrderConfirmed::class,
        ComplaintNcrRequested::class,
        WorkOrderCompleted::class,
        WorkOrderStatusChanged::class,
        WorkOrderOutputRecorded::class,
        ProductionReceiptRequested::class,
        MachineStatusChanged::class,
        MachineBreakdownDetected::class,
        PurchaseRequestApproved::class,
        PurchaseOrderApproved::class,
        PurchaseOrderSent::class,
        PurchaseOrderCancelled::class,
        GoodsReceiptNoteCreated::class,
        GoodsReceiptNoteAccepted::class,
        LowStockPrCreated::class,
        StockMovementCompleted::class,
        StockMovementGlPostingRequested::class,
        ReturnInspectionRequested::class,
        InspectionPassed::class,
        InspectionFailed::class,
        NcrRecurrenceLinked::class,
        DeliveryConfirmed::class,
        DeliveryInvoiceRequested::class,
        EmployeeCreated::class,
        SeparationInitiated::class,
        ClearanceFullySigned::class,
        PayrollPeriodFinalized::class,
        PayrollComputationRequested::class,
        PayrollGlPostingRequested::class,
        PayrollPeriodDisbursed::class,
        PayrollPeriodVoided::class,
        MaintenanceWorkOrderCreated::class,
        PreventiveMaintenanceGenerationRequested::class,
        MonthlyDepreciationRequested::class,
        OvertimeRequestSubmitted::class,
        OvertimeRequestDecided::class,
        BudgetActualsSyncRequested::class,
        LeaveRequestSubmitted::class,
        LeaveRequestPendingHR::class,
        LeaveRequestApproved::class,
        LeaveRequestRejected::class,
        YearEndLeaveProcessingRequested::class,
        LoanSubmitted::class,
        LoanDecided::class,
        SupplierPerformanceComputed::class,
        MoldShotLimitNearing::class,
        MoldShotLimitReached::class,
        MrpPlanGenerated::class,
    ];

    /** @return array{event_type: class-string, payload: array<string, mixed>} */
    public function encode(object $event): array
    {
        $eventType = $event::class;
        $this->assertSupported($eventType);

        $constructor = (new ReflectionClass($event))->getConstructor();
        if ($constructor === null) {
            return ['event_type' => $eventType, 'payload' => []];
        }

        $payload = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (! property_exists($event, $name)) {
                throw new RuntimeException("Outbox event {$eventType} has no public property for {$name}.");
            }

            $payload[$name] = $this->encodeValue($event->{$name});
        }

        return ['event_type' => $eventType, 'payload' => $payload];
    }

    public function decode(string $eventType, array $payload): object
    {
        $this->assertSupported($eventType);
        $reflection = new ReflectionClass($eventType);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (! array_key_exists($name, $payload)) {
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();

                    continue;
                }
                throw new RuntimeException("Outbox payload for {$eventType} is missing {$name}.");
            }

            $arguments[] = $this->decodeValue($payload[$name], $parameter->getType(), $parameter);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /** @return array<string, mixed> */
    public function fingerprintPayload(object $event): array
    {
        return $this->encode($event)['payload'];
    }

    /** @return list<class-string> */
    public static function supportedEventTypes(): array
    {
        return self::SUPPORTED_EVENTS;
    }

    private function assertSupported(string $eventType): void
    {
        if (! in_array($eventType, self::SUPPORTED_EVENTS, true)) {
            throw new RuntimeException("Event {$eventType} is not registered for durable publication.");
        }
    }

    private function encodeValue(mixed $value): mixed
    {
        if ($value instanceof Model) {
            if (! $value->exists || $value->getKey() === null) {
                throw new RuntimeException('Cannot put an unsaved model into the event outbox.');
            }

            return [
                '__type' => 'model',
                'class' => $value::class,
                'id' => (string) $value->getKey(),
                'version' => $value->getRawOriginal('updated_at'),
            ];
        }

        if ($value instanceof BackedEnum) {
            return [
                '__type' => 'enum',
                'class' => $value::class,
                'value' => $value->value,
            ];
        }

        if ($value instanceof DateTimeInterface) {
            return [
                '__type' => 'datetime',
                'class' => $value::class,
                'value' => $value->format(DateTimeInterface::ATOM),
            ];
        }

        if (is_array($value)) {
            $encoded = [];
            foreach ($value as $key => $item) {
                $encoded[$key] = $this->encodeValue($item);
            }

            return $encoded;
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        throw new RuntimeException('Unsupported value in event outbox payload: '.get_debug_type($value));
    }

    private function decodeValue(mixed $value, ?ReflectionType $expectedType, ReflectionParameter $parameter): mixed
    {
        if (! is_array($value) || ! isset($value['__type'])) {
            if (is_array($value)) {
                return array_map(fn (mixed $item): mixed => $this->decodeValue($item, null, $parameter), $value);
            }

            return $value;
        }

        return match ($value['__type']) {
            'model' => $this->decodeModel($value, $expectedType, $parameter),
            'enum' => $this->decodeEnum($value, $expectedType),
            'datetime' => new \DateTimeImmutable((string) $value['value']),
            default => throw new RuntimeException('Unknown outbox payload marker.'),
        };
    }

    /** @param array{__type: string, class: mixed, id: mixed} $value */
    private function decodeModel(array $value, ?ReflectionType $expectedType, ReflectionParameter $parameter): Model
    {
        $class = $value['class'] ?? null;
        if (! is_string($class) || ! is_a($class, Model::class, true)) {
            throw new RuntimeException('Outbox payload contains an invalid model type.');
        }

        $expectedClass = $this->namedClass($expectedType);
        if ($expectedClass !== null && ! is_a($class, $expectedClass, true)) {
            throw new RuntimeException("Outbox model {$class} does not match constructor parameter {$parameter->getName()}.");
        }

        $model = $class::query()->find($value['id'] ?? null);
        if (! $model) {
            throw new RuntimeException("Outbox model {$class}#{$value['id']} no longer exists.");
        }

        return $model;
    }

    /** @param array{__type: string, class: mixed, value: mixed} $value */
    private function decodeEnum(array $value, ?ReflectionType $expectedType): BackedEnum
    {
        $class = $value['class'] ?? null;
        if (! is_string($class) || ! enum_exists($class) || ! is_a($class, BackedEnum::class, true)) {
            throw new RuntimeException('Outbox payload contains an invalid enum type.');
        }

        $expectedClass = $this->namedClass($expectedType);
        if ($expectedClass !== null && $expectedClass !== $class) {
            throw new RuntimeException('Outbox enum does not match its constructor parameter.');
        }

        return $class::from($value['value']);
    }

    private function namedClass(?ReflectionType $type): ?string
    {
        return $type instanceof ReflectionNamedType && ! $type->isBuiltin()
            ? $type->getName()
            : null;
    }
}
