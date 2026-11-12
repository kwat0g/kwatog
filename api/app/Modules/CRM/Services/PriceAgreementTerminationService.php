<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Models\AuditLog;
use App\Common\Services\NotificationService;
use App\Common\Services\Pdf\PdfRenderService;
use App\Common\Support\Money;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Services\PortalContractSignatureService;
use App\Modules\CRM\Mail\PriceAgreementTerminationNoticeMail;
use App\Modules\CRM\Models\CustomerAgreementDocument;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Support\PriceAgreementAuditContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Mutual-signature workflow for early termination and cancellation of scheduled
 * price agreements. The workflow reuses customer_agreement_documents and
 * audit_logs so it works against the existing schema without a migration.
 */
final class PriceAgreementTerminationService
{
    private const AUDIT_ACTIONS = ['term_requested', 'term_cancelled', 'term_signed'];

    public function __construct(
        private readonly PriceAgreementService $agreements,
        private readonly PortalContractSignatureService $signatures,
        private readonly PdfRenderService $pdf,
        private readonly NotificationService $notifications,
    ) {}

    /** @return array{scheduled: bool, coverage_date: string, has_coverage: bool, coverage_source: string} */
    public function preview(PriceAgreement $agreement, ?string $lastValidDate): array
    {
        $agreement->loadMissing(['customer', 'product']);
        if ($this->hasSignedTermination($agreement)) {
            throw new \App\Common\Exceptions\BusinessRuleException('This agreement already has a customer-signed termination. Create a new agreement for later pricing.');
        }
        $today = today(config('app.timezone'))->toDateString();
        $start = $agreement->effective_from->toDateString();
        $end = $agreement->effective_to->toDateString();
        $scheduled = $start > $today;

        if ($end < $today) {
            throw new \App\Common\Exceptions\BusinessRuleException('This price agreement has already expired.');
        }

        if ($scheduled) {
            if (filled($lastValidDate)) {
                throw ValidationException::withMessages(['last_valid_date' => ['A scheduled agreement is cancelled before it starts; no last-valid date is needed.']]);
            }
            $coverageDate = $start;
        } else {
            if (! filled($lastValidDate)) {
                throw ValidationException::withMessages(['last_valid_date' => ['Choose the last date the current price remains valid.']]);
            }
            $parsedLastValidDate = CarbonImmutable::createFromFormat('!Y-m-d', $lastValidDate, config('app.timezone'));
            if ($parsedLastValidDate === false) {
                throw ValidationException::withMessages(['last_valid_date' => ['Enter a valid last-valid date.']]);
            }
            $lastValidDate = $parsedLastValidDate->toDateString();
            if ($lastValidDate < $today || $lastValidDate < $start || $lastValidDate >= $end) {
                throw ValidationException::withMessages(['last_valid_date' => ['Choose a date from today through the day before the current end date.']]);
            }
            $coverageDate = CarbonImmutable::parse($lastValidDate, config('app.timezone'))->addDay()->toDateString();
        }

        $product = $agreement->product;
        $alternateAgreement = PriceAgreement::query()
            ->where('customer_id', $agreement->customer_id)
            ->where('product_id', $agreement->product_id)
            ->where('id', '!=', $agreement->id)
            ->whereDate('effective_from', '<=', $coverageDate)
            ->whereDate('effective_to', '>=', $coverageDate)
            ->get()
            ->first(fn (PriceAgreement $candidate): bool => $this->agreementPriceIsUsable($product, $candidate));

        if ($alternateAgreement !== null) {
            return ['scheduled' => $scheduled, 'coverage_date' => $coverageDate, 'has_coverage' => true, 'coverage_source' => 'agreement'];
        }

        if ($this->priceIsUsable($product, $product->base_selling_price)) {
            return ['scheduled' => $scheduled, 'coverage_date' => $coverageDate, 'has_coverage' => true, 'coverage_source' => 'base'];
        }

        return ['scheduled' => $scheduled, 'coverage_date' => $coverageDate, 'has_coverage' => false, 'coverage_source' => 'none'];
    }

    /**
     * @param array{last_valid_date?: string|null, reason: string, coverage_gap_acknowledged?: bool} $data
     */
    public function request(PriceAgreement $agreement, array $data, User $actor): PriceAgreement
    {
        $documentPath = null;

        try {
            $updated = DB::transaction(function () use ($agreement, $data, $actor, &$documentPath): PriceAgreement {
                $candidate = PriceAgreement::query()->findOrFail($agreement->id);
                $this->agreements->lockPricingReferences([(int) $candidate->product_id], [(int) $candidate->customer_id]);
                $locked = PriceAgreement::query()->with(['customer', 'product'])->lockForUpdate()->findOrFail($agreement->id);
                $impact = $this->preview($locked, $data['last_valid_date'] ?? null);
                if (! $impact['has_coverage'] && ! (bool) ($data['coverage_gap_acknowledged'] ?? false)) {
                    throw ValidationException::withMessages(['coverage_gap_acknowledged' => ['Confirm that orders may be blocked after this agreement ends.']]);
                }

                $signers = $this->authorizedSigners((int) $locked->customer_id);
                if ($signers->isEmpty()) {
                    throw new \App\Common\Exceptions\BusinessRuleException('Set up an active customer portal user with authorized-signatory access before sending a termination request.');
                }

                $prefix = $this->documentPrefix($locked);
                if (CustomerAgreementDocument::query()->where('customer_id', $locked->customer_id)
                    ->where('file_path', 'like', $prefix.'%')->where('signature_status', 'pending')->exists()) {
                    throw new \App\Common\Exceptions\BusinessRuleException('A customer signature request is already waiting for this price agreement.');
                }

                $reason = trim((string) $data['reason']);
                $kind = $impact['scheduled'] ? 'scheduled_cancellation' : 'early_termination';
                $lastValidDate = $impact['scheduled'] ? null : (string) $data['last_valid_date'];
                $effectiveChangeDate = $impact['scheduled'] ? $locked->effective_from->toDateString() : $lastValidDate;
                $content = $this->pdf->render('crm::pdf.price-agreement-termination', [
                    'termination' => [
                        'kind' => $kind,
                        'reference' => 'PA-'.$locked->hash_id,
                        'customer' => $locked->customer->name,
                        'product_name' => $locked->product->name,
                        'part_number' => $locked->product->part_number,
                        'price' => (string) $locked->price,
                        'effective_from' => $locked->effective_from->toDateString(),
                        'effective_to' => $locked->effective_to->toDateString(),
                        'last_valid_date' => $lastValidDate,
                        'cancellation_date' => $impact['scheduled'] ? $locked->effective_from->toDateString() : null,
                        'reason' => $reason,
                        'coverage_date' => $impact['coverage_date'],
                        'coverage_status' => $impact['has_coverage'] ? $impact['coverage_source'] : 'none',
                    ],
                ], [
                    'title' => $impact['scheduled'] ? 'Scheduled Price Agreement Cancellation' : 'Price Agreement Early Termination',
                    'watermark_text' => 'CUSTOMER SIGNATURE REQUIRED',
                ]);

                $documentPath = $prefix.Str::uuid().'.pdf';
                $disk = Storage::disk('local');
                if (! $disk->put($documentPath, $content, ['visibility' => 'private'])) {
                    throw new \RuntimeException('The customer signature document could not be stored.');
                }
                $fileName = Str::limit(($impact['scheduled'] ? 'Price agreement cancellation - ' : 'Price agreement termination - ')
                    .$locked->product->part_number.' - '.$effectiveChangeDate.'.pdf', 255, '');
                $document = CustomerAgreementDocument::query()->create([
                    'customer_id' => $locked->customer_id,
                    'file_path' => $documentPath,
                    'filename' => $fileName,
                    'size' => strlen($content),
                    'mime' => 'application/pdf',
                    'uploaded_by' => $actor->id,
                    'document_sha256' => hash('sha256', $content),
                    'signature_status' => 'draft',
                ]);
                $document = $this->signatures->issueCustomerDocument($document, $actor);

                $event = [
                    'status' => 'awaiting_customer_signature',
                    'document_id' => (int) $document->id,
                    'kind' => $kind,
                    'last_valid_date' => $lastValidDate,
                    'coverage' => $impact,
                ];
                $this->recordEvent($locked, 'term_requested', $actor->id, $reason, $event);
                $this->notifyCustomer($locked, $document, $event, 'signature_request', $signers);

                return $locked->fresh()->load(['customer', 'product']);
            });
        } catch (Throwable $exception) {
            if ($documentPath !== null && ! CustomerAgreementDocument::query()->where('file_path', $documentPath)->exists()) {
                Storage::disk('local')->delete($documentPath);
            }
            throw $exception;
        }

        return $this->withSummary(collect([$updated]))->first();
    }

    public function cancelRequest(PriceAgreement $agreement, string $documentHash, User $actor): PriceAgreement
    {
        return DB::transaction(function () use ($agreement, $documentHash, $actor): PriceAgreement {
            $candidate = PriceAgreement::query()->findOrFail($agreement->id);
            $candidate->loadMissing('customer');
            $documentId = CustomerAgreementDocument::tryDecodeHash($documentHash);
            abort_if($documentId === null, 404);
            // Match the portal-signature lock order: document, pricing references,
            // then agreement. This avoids a document/agreement deadlock if the
            // customer signs while CRM is cancelling the request.
            $document = CustomerAgreementDocument::query()->whereKey($documentId)
                ->where('customer_id', $candidate->customer_id)
                ->where('file_path', 'like', $this->documentPrefix($candidate).'%')
                ->lockForUpdate()->firstOrFail();
            $this->agreements->lockPricingReferences([(int) $candidate->product_id], [(int) $candidate->customer_id]);
            $locked = PriceAgreement::query()->with(['customer', 'product'])->lockForUpdate()->findOrFail($agreement->id);
            abort_unless((int) $locked->customer_id === (int) $candidate->customer_id
                && str_starts_with((string) $document->file_path, $this->documentPrefix($locked)), 404);
            $requestEvent = $this->eventForDocument($locked, (int) $document->id);
            if ($document->signature_status !== 'pending' || ($requestEvent['status'] ?? null) !== 'awaiting_customer_signature') {
                throw new \App\Common\Exceptions\BusinessRuleException('This customer signature request is no longer waiting for a response.');
            }

            $document->forceFill(['signature_status' => 'cancelled'])->save();
            $event = [
                ...$requestEvent,
                'status' => 'request_cancelled',
                'document_id' => (int) $document->id,
            ];
            $reason = 'Pending customer signature request cancelled by CRM.';
            $this->recordEvent($locked, 'term_cancelled', $actor->id, $reason, $event);
            $this->notifyCustomer($locked, $document, $event, 'request_cancelled');

            return $this->withSummary(collect([$locked->fresh()->load(['customer', 'product'])]))->first();
        });
    }

    /** Called inside the portal signing transaction so stale requests cannot leave a recorded signature. */
    public function applySignedDocument(CustomerAgreementDocument $document, CustomerPortalUser $signer): void
    {
        $association = $this->agreementFromPath((string) $document->file_path);
        if ($association === null) {
            return;
        }

        [$customerHash, $agreementHash] = $association;
        $agreementId = PriceAgreement::tryDecodeHash($agreementHash);
        abort_if($agreementId === null, 404);
        $candidate = PriceAgreement::query()->findOrFail($agreementId);
        abort_unless((int) $candidate->customer_id === (int) $signer->customer_id
            && hash_equals((string) $candidate->customer?->hash_id, $customerHash), 404);

        DB::transaction(function () use ($candidate, $document, $signer): void {
            $this->agreements->lockPricingReferences([(int) $candidate->product_id], [(int) $candidate->customer_id]);
            $locked = PriceAgreement::query()->with(['customer', 'product'])->lockForUpdate()->findOrFail($candidate->id);
            $requestEvent = $this->eventForDocument($locked, (int) $document->id);
            if (($requestEvent['status'] ?? null) !== 'awaiting_customer_signature') {
                throw new \App\Common\Exceptions\BusinessRuleException('This termination request was cancelled or replaced. Ask your account manager for a current document.');
            }

            $today = today(config('app.timezone'))->toDateString();
            $kind = (string) ($requestEvent['kind'] ?? '');
            $lastValidDate = $requestEvent['last_valid_date'] ?? null;
            if ($kind === 'scheduled_cancellation') {
                if ($locked->effective_from->toDateString() <= $today || $locked->effective_to->toDateString() < $today) {
                    throw new \App\Common\Exceptions\BusinessRuleException('This agreement has started or expired. Ask your account manager to prepare a current termination document.');
                }
                $newEnd = $locked->effective_from->copy()->subDay()->toDateString();
                $status = 'cancelled_before_start';
            } elseif ($kind === 'early_termination' && is_string($lastValidDate)) {
                if ($locked->effective_from->toDateString() > $today || $locked->effective_to->toDateString() < $today
                    || $lastValidDate < $today || $lastValidDate < $locked->effective_from->toDateString()
                    || $lastValidDate >= $locked->effective_to->toDateString()) {
                    throw new \App\Common\Exceptions\BusinessRuleException('This termination request has expired. Ask your account manager to issue an updated document.');
                }
                $newEnd = $lastValidDate;
                $status = 'ended_early';
            } else {
                abort(409, 'The termination document contains an unsupported agreement state.');
            }

            // Availability can change while a signature request is waiting. Do
            // not apply a document whose price-coverage statement is stale.
            $coverageAtRequest = $requestEvent['coverage'] ?? null;
            if (! is_array($coverageAtRequest)) {
                throw new \App\Common\Exceptions\BusinessRuleException('The termination document is missing its price-coverage review. Ask your account manager to prepare a new one.');
            }
            $currentCoverage = $this->preview($locked, $kind === 'scheduled_cancellation' ? null : $lastValidDate);
            if (($coverageAtRequest['has_coverage'] ?? null) !== $currentCoverage['has_coverage']
                || ($coverageAtRequest['coverage_source'] ?? null) !== $currentCoverage['coverage_source']) {
                throw new \App\Common\Exceptions\BusinessRuleException('Price availability changed while the document was waiting. Ask your account manager to send an updated request.');
            }

            $reason = (string) ($requestEvent['reason'] ?? 'Customer-signed agreement change.');
            PriceAgreementAuditContext::runAsCustomerPortal($reason, function () use ($locked, $newEnd): void {
                $locked->update(['effective_to' => $newEnd]);
            });

            $event = [
                ...$requestEvent,
                'status' => $status,
                'document_id' => (int) $document->id,
                'last_valid_date' => $kind === 'early_termination' ? $newEnd : null,
                'applied_effective_to' => $newEnd,
                'signed_at' => $document->signed_at?->toIso8601String(),
                'signed_by' => $signer->hash_id,
            ];
            $this->recordEvent($locked, 'term_signed', null, $reason, $event, actorType: 'customer_portal');
            $this->notifyCustomer($locked, $document, $event, 'termination_recorded');
        });
    }

    /** Attach the latest no-schema workflow event to each row for API serialization. */
    public function withSummary(Collection $agreements): Collection
    {
        $items = $agreements->filter(fn ($item): bool => $item instanceof PriceAgreement)->values();
        if ($items->isEmpty()) {
            return $agreements;
        }
        $ids = $items->map(fn (PriceAgreement $agreement): int => (int) $agreement->id)->all();
        $eventQuery = AuditLog::query()->where('model_type', $items->first()->getMorphClass())
            ->whereIn('model_id', $ids)->whereIn('action', self::AUDIT_ACTIONS);
        $latestEventIds = (clone $eventQuery)->selectRaw('MAX(id) as id')->groupBy('model_id')->pluck('id');
        $historyCounts = (clone $eventQuery)->selectRaw('model_id, COUNT(*) as event_count')->groupBy('model_id')
            ->pluck('event_count', 'model_id');
        $events = $latestEventIds->isEmpty()
            ? collect()
            : AuditLog::query()->whereIn('id', $latestEventIds)->orderByDesc('id')->get();
        $latest = [];
        foreach ($events as $event) {
            $agreementId = (int) $event->model_id;
            if (isset($latest[$agreementId])) {
                continue;
            }
            $values = is_array($event->new_values) ? $event->new_values : [];
            $latest[$agreementId] = [...$values, 'reason' => $event->reason];
        }
        $documentIds = collect($latest)->pluck('document_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        $documents = $documentIds->isEmpty()
            ? collect()
            : CustomerAgreementDocument::query()->whereIn('id', $documentIds)->get()->keyBy('id');

        foreach ($items as $agreement) {
            $event = $latest[$agreement->id] ?? null;
            $document = $event === null ? null : $documents->get((int) ($event['document_id'] ?? 0));
            $agreement->setAttribute('termination_status', $event['status'] ?? null);
            $agreement->setAttribute('termination_reason', $event['reason'] ?? null);
            $agreement->setAttribute('termination_last_valid_date', $event['last_valid_date'] ?? null);
            $agreement->setAttribute('termination_document_id', $document?->hash_id);
            $agreement->setAttribute('termination_kind', $event['kind'] ?? null);
            $agreement->setAttribute('termination_history_count', (int) ($historyCounts[$agreement->id] ?? 0));
        }

        return $agreements;
    }

    /**
     * Return a bounded, newest-first termination timeline for the CRM UI.
     * Raw audit IDs and actor IDs are deliberately omitted.
     *
     * @return array{entries: list<array{status: string, reason: ?string, occurred_at: ?string, actor_type: ?string, signed_name: ?string, kind: ?string, last_valid_date: ?string, document_id: ?string}>, has_older_entries: bool}
     */
    public function history(PriceAgreement $agreement): array
    {
        $events = AuditLog::query()->where('model_type', $agreement->getMorphClass())
            ->where('model_id', $agreement->id)->whereIn('action', self::AUDIT_ACTIONS)
            ->orderByDesc('id')->limit(51)->get();
        $hasOlderEntries = $events->count() > 50;
        $events = $events->take(50);
        $documentIds = $events->pluck('new_values')->filter(fn ($values): bool => is_array($values))
            ->map(fn (array $values) => $values['document_id'] ?? null)->filter()->map(fn ($id): int => (int) $id)->unique();
        $documents = $documentIds->isEmpty()
            ? collect()
            : CustomerAgreementDocument::query()->whereIn('id', $documentIds)->get()->keyBy('id');

        $entries = $events->map(function (AuditLog $event) use ($documents): array {
            $values = is_array($event->new_values) ? $event->new_values : [];
            $document = $documents->get((int) ($values['document_id'] ?? 0));

            return [
                'status' => (string) ($values['status'] ?? $event->action),
                'reason' => $event->reason,
                'occurred_at' => $event->created_at?->toIso8601String(),
                'actor_type' => $event->actor_type,
                'signed_name' => $document?->signed_name,
                'kind' => isset($values['kind']) ? (string) $values['kind'] : null,
                'last_valid_date' => isset($values['last_valid_date']) ? (string) $values['last_valid_date'] : null,
                'document_id' => $document?->hash_id,
            ];
        })->values()->all();

        return ['entries' => $entries, 'has_older_entries' => $hasOlderEntries];
    }

    public function hasPendingTermination(PriceAgreement $agreement): bool
    {
        return ($this->latestAgreementEvent($agreement)['status'] ?? null) === 'awaiting_customer_signature';
    }

    public function hasSignedTermination(PriceAgreement $agreement): bool
    {
        return in_array($this->latestAgreementEvent($agreement)['status'] ?? null, ['ended_early', 'cancelled_before_start'], true);
    }

    private function agreementPriceIsUsable(Product $product, PriceAgreement $agreement): bool
    {
        if (! $this->priceIsUsable($product, $agreement->price)) {
            return false;
        }
        foreach ($agreement->tiers ?? [] as $tier) {
            if (! $this->priceIsUsable($product, $tier['unit_price'] ?? null)) {
                return false;
            }
        }
        return true;
    }

    private function priceIsUsable(Product $product, mixed $price): bool
    {
        if (! is_numeric($price) || Money::lte((string) $price, '0')) {
            return false;
        }
        return $product->minimum_selling_price === null
            || Money::gte((string) $price, (string) $product->minimum_selling_price);
    }

    /** @return Collection<int, CustomerPortalUser> */
    private function authorizedSigners(int $customerId): Collection
    {
        return CustomerPortalUser::query()->where('customer_id', $customerId)->where('is_active', true)
            ->where('is_authorized_signatory', true)->where('must_change_password', false)
            ->whereNotNull('email')->where('email', '!=', '')->get();
    }

    /** @param array<string, mixed> $event */
    private function notifyCustomer(
        PriceAgreement $agreement,
        CustomerAgreementDocument $document,
        array $event,
        string $mailEvent,
        ?Collection $emailRecipients = null,
    ): void {
        $users = CustomerPortalUser::query()->where('customer_id', $agreement->customer_id)
            ->where('is_active', true)->get();
        $isScheduled = ($event['kind'] ?? null) === 'scheduled_cancellation';
        $message = match ($mailEvent) {
            'signature_request' => $isScheduled
                ? 'A scheduled price agreement cancellation is ready for your authorized signatory to review.'
                : 'A price agreement termination is ready for your authorized signatory to review. The current terms stay in effect until it is signed.',
            'request_cancelled' => 'A pending price agreement signature request was cancelled by your account manager.',
            default => $isScheduled
                ? 'Your authorized signatory cancelled the scheduled price agreement before its start date.'
                : 'Your authorized signatory ended the price agreement. The agreed price remains valid through '.($event['last_valid_date'] ?? 'the recorded date').'.',
        };
        $this->notifications->sendPortalInApp($users, 'pricing.agreement_termination', [
            'title' => $mailEvent === 'signature_request' ? 'Price agreement signature requested' : 'Price agreement updated',
            'message' => $agreement->product->part_number.' · '.$message,
            'link_to' => '/portal/customer/agreements',
        ], 'pricing:agreement-termination:'.$agreement->id.':'.$document->id.':'.$mailEvent);

        $recipients = $emailRecipients ?? $users->filter(fn (CustomerPortalUser $user): bool => filled($user->email));
        foreach ($recipients as $recipient) {
            if (! filled($recipient->email)) {
                continue;
            }
            Mail::to($recipient->email)->queue(new PriceAgreementTerminationNoticeMail(
                customerName: (string) $agreement->customer->name,
                productName: (string) $agreement->product->name,
                partNumber: (string) $agreement->product->part_number,
                agreementId: (string) $agreement->hash_id,
                documentName: (string) $document->filename,
                event: $mailEvent,
                kind: (string) ($event['kind'] ?? 'early_termination'),
                lastValidDate: is_string($event['last_valid_date'] ?? null) ? $event['last_valid_date'] : null,
                fallbackUserIds: app(\App\Common\Services\EmailDeliveryFailureNotifier::class)
                    ->userIdsWithPermission('crm.price_agreements.manage'),
                agreement: $agreement,
            ));
        }
    }

    /** @param array<string, mixed> $values */
    private function recordEvent(
        PriceAgreement $agreement,
        string $action,
        ?int $actorId,
        string $reason,
        array $values,
        string $actorType = 'user',
    ): void {
        AuditLog::query()->create([
            'user_id' => $actorId,
            'actor_type' => $actorType,
            'action' => $action,
            'model_type' => $agreement->getMorphClass(),
            'model_id' => $agreement->id,
            'old_values' => null,
            'new_values' => $values,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'source_command' => request()?->route()?->getName() ?? 'price_agreement_termination',
            'correlation_id' => request()?->header('X-Request-ID'),
            'reason' => mb_substr(trim($reason), 0, 2000),
            'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function eventForDocument(PriceAgreement $agreement, int $documentId): ?array
    {
        $events = AuditLog::query()->where('model_type', $agreement->getMorphClass())
            ->where('model_id', $agreement->id)->whereIn('action', self::AUDIT_ACTIONS)->orderByDesc('id')->get();
        foreach ($events as $event) {
            $values = is_array($event->new_values) ? $event->new_values : [];
            if ((int) ($values['document_id'] ?? 0) === $documentId) {
                $values['reason'] = $event->reason;
                return $values;
            }
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    private function latestAgreementEvent(PriceAgreement $agreement): ?array
    {
        $event = AuditLog::query()->where('model_type', $agreement->getMorphClass())
            ->where('model_id', $agreement->id)->whereIn('action', self::AUDIT_ACTIONS)->orderByDesc('id')->first();
        if ($event === null) {
            return null;
        }
        $values = is_array($event->new_values) ? $event->new_values : [];
        $values['reason'] = $event->reason;
        return $values;
    }

    private function documentPrefix(PriceAgreement $agreement): string
    {
        $agreement->loadMissing('customer');
        return 'customer-agreement-documents/'.$agreement->customer->hash_id.'/price-terminations/'.$agreement->hash_id.'/';
    }

    /** @return array{0: string, 1: string}|null */
    private function agreementFromPath(string $path): ?array
    {
        if (! preg_match('#^customer-agreement-documents/([A-Za-z0-9]+)/price-terminations/([A-Za-z0-9]+)/[a-f0-9-]+\.pdf$#', $path, $matches)) {
            return null;
        }
        return [$matches[1], $matches[2]];
    }
}
