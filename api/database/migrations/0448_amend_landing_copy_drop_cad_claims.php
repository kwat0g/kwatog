<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The landing part showcase claimed more than the site delivers.
 *
 * `three/parts.ts` revolves LatheGeometry from hand-authored ring()/dome()
 * half-profiles. That is honest product illustration, but the copy sold it as a
 * CAD inspection tool — "Turn it over. Take it apart.", "Inspect a section
 * before a single shot is run." A visitor cannot section anything; there is no
 * measurement readout and no imported geometry.
 *
 * The contact copy oversold in the other direction: it invited drawings and
 * promised tooling quotations, which is custom-tooling intake. Ogami does not
 * accept custom mold parts, so every such enquiry was a dead end for both sides.
 *
 * Amends the seeded values only. The hardcoded TSX fallbacks — which render when
 * the content API fails, i.e. exactly when nobody is watching — are corrected in
 * the same commit.
 */
return new class extends Migration {
    /** @var array<string, string> */
    private const NEW = [
        'part_showcase_eyebrow' => 'Parts we mold',
        'part_showcase_title'   => 'Components We Produce',
        'part_showcase_intro'   => 'Representative geometries of components we produce, drawn from our own tooling.',
        'contact_title'         => 'Talk to us.',
        'contact_intro'         => 'Questions about our capabilities, an existing order, or working with us — send a message and the right person will come back to you.',
    ];

    /** @var array<string, string> */
    private const OLD = [
        'part_showcase_eyebrow' => 'Inspect the part',
        'part_showcase_title'   => 'Turn it over. Take it apart.',
        'part_showcase_intro'   => 'Every molded part is a controlled geometry. Inspect a section before a single shot is run.',
        'contact_title'         => "Have a part in mind? Let's mold it.",
        'contact_intro'         => 'Send us your drawing or your challenge. Our engineers will come back with tooling, tolerance, and timeline — and a clear path to your first certified shipment.',
    ];

    public function up(): void
    {
        $this->applyCopy(self::NEW);
        $this->applyCta('Contact us');
    }

    public function down(): void
    {
        $this->applyCopy(self::OLD);
        $this->applyCta('Request a quote');
    }

    /** @param array<string, string> $values */
    private function applyCopy(array $values): void
    {
        $row = DB::table('settings')->where('key', 'landing.section_copy')->first();
        if (! $row) {
            return;
        }

        $copy = (array) json_decode((string) $row->value, true);

        // Only rewrite keys that are still at the value this migration knows
        // about. An admin who has since edited the copy through the UI keeps
        // their wording — we are correcting our seed, not overriding them.
        foreach ($values as $key => $value) {
            $expected = $values === self::NEW ? (self::OLD[$key] ?? null) : (self::NEW[$key] ?? null);
            if (! array_key_exists($key, $copy) || $copy[$key] === $expected) {
                $copy[$key] = $value;
            }
        }

        DB::table('settings')
            ->where('key', 'landing.section_copy')
            ->update(['value' => json_encode($copy), 'updated_at' => now()]);
    }

    /**
     * The hero/nav CTA label lives NESTED inside `landing.section_copy`, not in
     * a settings key of its own (see 0428). Reading a `landing.hero_cta` key
     * finds nothing and silently leaves the button reading "Request a quote".
     */
    private function applyCta(string $label): void
    {
        $row = DB::table('settings')->where('key', 'landing.section_copy')->first();
        if (! $row) {
            return;
        }

        $copy = (array) json_decode((string) $row->value, true);
        if (! isset($copy['hero_cta']) || ! is_array($copy['hero_cta'])) {
            return;
        }

        $copy['hero_cta']['quote_label'] = $label;
        // quote_href stays '#contact' — the anchor target survives; only the
        // modal that used to intercept the click is gone.

        DB::table('settings')
            ->where('key', 'landing.section_copy')
            ->update(['value' => json_encode($copy), 'updated_at' => now()]);
    }
};
