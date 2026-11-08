<?php

declare(strict_types=1);

namespace App\Modules\Landing\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\Landing\Events\ContactInquiryChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one latest inquiry-inbox refresh per database transaction. */
final class ContactInquiryRealtimeService
{
    public function stage(string $action, ?User $actor = null, ?string $entityHashId = null): void
    {
        $usesPostgresTransactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql';
        $transactionId = $usesPostgresTransactionId
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $version = $usesPostgresTransactionId
            ? 'txid:'.$transactionId
            : now()->format('Y-m-d\\TH:i:s.uP');

        app(OutboxService::class)->recordCoalesced(new ContactInquiryChanged(
            action: $action,
            version: $version,
            actorHashId: $actor?->hash_id === null ? null : (string) $actor->hash_id,
            entityHashId: $entityHashId,
        ), "contact-inquiry-inbox-refresh:{$transactionId}");
    }
}
