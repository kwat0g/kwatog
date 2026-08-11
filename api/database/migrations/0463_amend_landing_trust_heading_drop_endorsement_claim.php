<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The trust band claimed an endorsement the plant does not have.
 *
 * 0429 seeded `trust_heading` as "Trusted by the world's leading automakers",
 * set directly above a row of OEM wordmarks. MarqueeSection.tsx already limits
 * itself to nominative use — plain type, no logo artwork — precisely because
 * naming an automaker is only defensible as a statement of fact. "Trusted by"
 * is not a statement of fact; it asserts a relationship. Ogami is a tier-N
 * molder supplying tier-1 assemblers, so no OEM has vouched for it, and a
 * wordmark row under those words reads as borrowed endorsement.
 *
 * Same correction class as 0448, which dropped the CAD-inspection claims: the
 * seed oversold, so the seed is amended rather than the component patched.
 *
 * >>> AWAITING WORDING CHOICE <<<
 * NEW below carries option A from the accompanying report. Swap the string for
 * option B or C before running this migration if the user prefers those. The
 * component fallback stays '—' (neutral), so no TSX change accompanies this.
 *
 *   A. 'Parts in vehicles from'          — end-state fact, no relationship claimed
 *   B. 'Molding to OEM specification for' — foregrounds the IATF discipline
 *   C. 'In the supply chain for'          — most conservative, states tier position
 */
return new class extends Migration {
    private const NEW = 'Parts in vehicles from';

    private const OLD = 'Trusted by the world\'s leading automakers';

    public function up(): void
    {
        $this->apply(self::NEW, self::OLD);
    }

    public function down(): void
    {
        $this->apply(self::OLD, self::NEW);
    }

    /**
     * Rewrite `trust_heading` only when it still holds the value this migration
     * expects. An admin who has since edited the copy through the UI keeps their
     * wording — we are correcting our own seed, not overriding them (0448).
     */
    private function apply(string $value, string $expected): void
    {
        $row = DB::table('settings')->where('key', 'landing.section_copy')->first();
        if (! $row) {
            return;
        }

        $copy = (array) json_decode((string) $row->value, true);

        if (array_key_exists('trust_heading', $copy) && $copy['trust_heading'] !== $expected) {
            return;
        }

        $copy['trust_heading'] = $value;

        DB::table('settings')
            ->where('key', 'landing.section_copy')
            ->update(['value' => json_encode($copy), 'updated_at' => now()]);
    }
};
