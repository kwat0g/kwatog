<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrunePortalPasswordResetTokens extends Command
{
    protected $signature = 'portal:prune-reset-tokens';

    protected $description = 'Delete expired and consumed B2B portal password reset tokens';

    public function handle(): int
    {
        $deleted = DB::table('portal_password_reset_tokens')
            ->where(function ($query): void {
                $query->where('expires_at', '<=', now())
                    ->orWhereNotNull('used_at');
            })
            ->delete();

        $this->info("Pruned {$deleted} portal password reset token(s).");

        return self::SUCCESS;
    }
}
