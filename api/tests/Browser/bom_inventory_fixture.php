<?php

declare(strict_types=1);

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\InspectionSpecService;
use Illuminate\Support\Facades\DB;

if (app()->environment() !== 'local' || DB::connection()->getDatabaseName() !== 'ogami_test_audit_20261002') {
    throw new RuntimeException('Refusing browser fixtures outside the audit database.');
}
$manifest = json_decode(file_get_contents('/tmp/bom-e2e-identities.json'), true, flags: JSON_THROW_ON_ERROR);
$product = Product::whereHash($manifest['product'])->firstOrFail();
$wo = WorkOrder::whereHash($manifest['wo'])->firstOrFail();
$production = User::where('email', 'production@ogami.test')->firstOrFail();
app(InspectionSpecService::class)->upsertForProduct($product->id, [
    ['parameter_name' => 'Surface', 'parameter_type' => 'visual', 'is_critical' => false],
], $production->id);
// Explicit downstream starting state: upstream material issue is covered by separate integration tests.
$wo->forceFill(['status' => 'in_progress', 'actual_start' => now(), 'material_plan_source' => 'manual'])->save();
$wo->materials()->delete();
$process = app(InspectionService::class)->create([
    'stage' => 'in_process', 'product_id' => $product->id,
    'batch_quantity' => 100, 'entity_type' => 'work_order', 'entity_id' => $wo->id,
], $production);
$process->forceFill(['status' => 'passed', 'reviewed_by' => User::where('email', 'qc@ogami.test')->value('id'),
    'reviewed_at' => now(), 'completed_at' => now(), 'accepted_quantity' => 100])->save();
echo "Fixture ready.\n";
