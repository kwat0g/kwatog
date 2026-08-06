<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $settings = [
            ['landing.capabilities', [
                ['id' => 'automotive', 'title' => 'Automotive resin parts', 'icon' => 'car', 'tag' => 'IATF 16949', 'blurb' => 'Safety-critical injection-molded components, produced to automotive-grade discipline and supplied tier-direct to the world’s leading OEMs.', 'points' => ['Wiper bushings', 'Pivot caps', 'Relay covers']],
                ['id' => 'precision', 'title' => 'Medical & precision parts', 'icon' => 'stethoscope', 'tag' => 'Tight tolerance', 'blurb' => 'Cleanroom-ready molding for devices that cannot fail — tight tolerances, full lot traceability, and material certainty on every shot.', 'points' => ['Light-electric resin parts', 'Micro-tolerance molding', 'Lot traceability']],
                ['id' => 'assembly', 'title' => 'Assembly & sub-assembly', 'icon' => 'layers', 'tag' => 'Value-added', 'blurb' => 'Molding, fitting, and inspection combined into one controlled flow, so finished assemblies arrive ready for your line.', 'points' => ['Integrated sub-assembly', 'In-line inspection', 'Kitted delivery']],
                ['id' => 'tooling', 'title' => 'In-house mold design & tooling', 'icon' => 'hammer', 'tag' => 'Built in-house', 'blurb' => 'We design, cut, and maintain our own molds — protecting your tolerances, your lead time, and your intellectual property.', 'points' => ['Mold design', 'Precision fabrication', 'Preventive maintenance']],
            ], 'Landing Capabilities', 'Capabilities displayed on the public landing page.'],
            ['landing.process_steps', [
                ['index' => '01', 'title' => 'Material & incoming QC', 'icon' => 'boxes', 'body' => 'Every batch of resin is checked against its certificate of analysis and moisture spec before it is ever accepted into inventory.'],
                ['index' => '02', 'title' => 'Mold design & tooling', 'icon' => 'hammer', 'body' => 'Precision molds are designed and cut in-house, so the geometry your part depends on is owned and controlled end-to-end.'],
                ['index' => '03', 'title' => 'Injection molding', 'icon' => 'layers', 'body' => 'Controlled-process molding holds pressure, temperature, and cycle time to tight windows — repeatable shot after shot, at scale.'],
                ['index' => '04', 'title' => 'Cooling & forming', 'icon' => 'thermometer', 'body' => 'Managed cooling locks in dimensional stability, eliminating warp and internal stress before the part leaves the cell.'],
                ['index' => '05', 'title' => 'Inspection — AQL 0.65', 'icon' => 'ruler', 'body' => 'Critical dimensions are measured against spec tolerances under AQL 0.65 Level II sampling, with in-process checks between operations.'],
                ['index' => '06', 'title' => 'Certificate & delivery', 'icon' => 'file-check', 'body' => 'A Certificate of Conformance is generated from real inspection data, and parts ship with full traceability from resin to dock.'],
            ], 'Landing Process Steps', 'Manufacturing process steps displayed on the public landing page.'],
            ['landing.quality_pillars', [
                ['id' => 'incoming', 'title' => 'Incoming verification', 'icon' => 'package-check', 'body' => 'Resin certificates and moisture are verified before any material is accepted — quality starts before the first shot.'],
                ['id' => 'in-process', 'title' => 'In-process sampling', 'icon' => 'scan-line', 'body' => 'Periodic sampling between operations catches drift early, so a problem never reaches a full production run.'],
                ['id' => 'outgoing', 'title' => 'Outgoing AQL inspection', 'icon' => 'ruler', 'body' => 'AQL 0.65 Level II sampling with measured critical dimensions gates every shipment against your tolerances.'],
                ['id' => 'coc', 'title' => 'Certificate of Conformance', 'icon' => 'file-check', 'body' => 'Each delivery carries a CoC built from real measurement data, with traceability from raw resin to your receiving dock.'],
            ], 'Landing Quality Pillars', 'Quality pillars displayed on the public landing page.'],
        ];

        foreach ($settings as [$key, $value, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => 'landing',
                'label' => $label, 'description' => $description,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['landing.capabilities', 'landing.process_steps', 'landing.quality_pillars'])->delete();
    }
};
