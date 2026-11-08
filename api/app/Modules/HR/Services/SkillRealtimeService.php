<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\SkillChanged;
use App\Modules\HR\Models\Skill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces catalog refresh signals within each Skill transaction. */
final class SkillRealtimeService
{
    public function stage(Skill $skill, string $action): void
    {
        $actor = auth()->user();
        $actorId = $actor instanceof User && is_string($actor->hash_id) ? $actor->hash_id : null;
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new SkillChanged(
            hashId: (string) $skill->hash_id,
            action: $action,
            version: $skill->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorId: $actorId,
        ), "skill-catalog-refresh:{$skill->getKey()}:{$transactionId}");
    }
}
