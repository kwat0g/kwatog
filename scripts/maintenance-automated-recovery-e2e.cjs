// Run a real-browser recovery journey against a fresh, disposable database.
// node scripts/maintenance-automated-recovery-e2e.cjs
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync, spawn } = require('node:child_process');
const { pathToFileURL } = require('node:url');

const root = path.resolve(__dirname, '..');
const spa = path.join(root, 'spa');
const run = `mr${Date.now().toString(36)}`;
const database = `ogami_test_maintenance_recovery_${run}`;
const storage = `/tmp/maintenance-recovery-${run}`;
const apiPort = 8300 + Math.floor(Math.random() * 500);
const spaPort = 5300 + Math.floor(Math.random() * 500);
const base = `http://127.0.0.1:${spaPort}`;
const work = fs.mkdtempSync(path.join(os.tmpdir(), `maintenance-recovery-${run}-`));
const fixtureFile = `/tmp/maintenance-recovery-${run}.php`;
const fixtureManifest = `/tmp/maintenance-recovery-${run}.json`;
const apiLog = `/tmp/maintenance-recovery-${run}.log`;
const manifest = path.join(work, 'fixture.json');
const configFile = path.join(spa, `.maintenance-recovery-${run}.config.cjs`);
const env = {
  APP_ENV: 'testing', DB_DATABASE: database, CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync',
  MAIL_MAILER: 'array', BROADCAST_CONNECTION: 'log', SESSION_DRIVER: 'database',
  APP_URL: base, LARAVEL_STORAGE_PATH: storage,
  SESSION_SECURE_COOKIE: 'false', SESSION_SAME_SITE: 'lax',
  SANCTUM_STATEFUL_DOMAINS: `127.0.0.1:${spaPort},localhost:${spaPort}`,
  E2E_MAINTENANCE_RECOVERY_DB: database,
  E2E_MAINTENANCE_RECOVERY_MANIFEST: fixtureManifest,
};
const compose = (...args) => execFileSync('docker', ['compose', ...args], { cwd: root, encoding: 'utf8' });
const flags = Object.entries(env).flatMap(([key, value]) => ['-e', `${key}=${value}`]);
const artisan = (...args) => compose('exec', '-T', ...flags, 'api', 'php', 'artisan', ...args);
const psql = (sql) => compose('exec', '-T', 'db', 'psql', '-U', 'ogami', '-d', 'postgres', '-At', '-c', sql).trim();
const apiIp = () => execFileSync('docker', ['inspect', '-f', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}', 'ogami-api'], { encoding: 'utf8' }).trim();
const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const fixturePhp = String.raw`<?php
declare(strict_types=1);
use App\Common\Services\SettingsService;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrew;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;

$database = getenv('E2E_MAINTENANCE_RECOVERY_DB');
if (! preg_match('/^ogami_test_maintenance_recovery_mr[a-z0-9]+$/D', $database ?: '')
    || \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== $database
    || ! app()->environment('testing')) {
    throw new RuntimeException('Refusing recovery fixture outside its disposable testing database.');
}
(new RolePermissionSeeder)->run();
(new SettingsSeeder)->run();
$settings = app(SettingsService::class);
foreach (['production', 'maintenance', 'inventory', 'mrp'] as $module) $settings->set('modules.'.$module, true, 'modules');
$settings->set('maintenance.closed_loop.enabled', true, 'maintenance');
$settings->set('system.automation.actor_roles', ['maintenance_supervisor']);
$maintenance = Department::firstOrCreate(['code' => 'MAINT'], ['name' => 'Maintenance', 'is_active' => true]);
$production = Department::firstOrCreate(['code' => 'PROD'], ['name' => 'Production', 'is_active' => true]);
$accounts = [];
foreach (['supervisor' => 'maintenance_supervisor', 'technician' => 'maintenance_tech', 'absent' => 'maintenance_tech', 'manager' => 'production_manager', 'warehouse' => 'warehouse_staff', 'worker' => 'employee'] as $name => $role) {
    $department = in_array($name, ['supervisor', 'technician', 'absent'], true) ? $maintenance : $production;
    $employee = Employee::factory()->create(['department_id' => $department->id, 'status' => 'active', 'first_name' => ucfirst($name), 'last_name' => 'Recovery']);
    $user = User::factory()->withRole($role)->create(['employee_id' => $employee->id,
        'name' => ucfirst($name).' Recovery', 'email' => $name.'-'.$database.'@ogami.test',
        'password' => 'Recovery-Test-2026!', 'must_change_password' => false, 'is_active' => true]);
    $accounts[$name] = ['email' => $user->email, 'employee_id' => $employee->hash_id, 'name' => $user->name];
}
$shift = Shift::create(['name' => 'Recovery all-day shift', 'start_time' => '00:00:00', 'end_time' => '23:59:00', 'is_active' => true]);
foreach (['technician', 'absent', 'worker'] as $name) {
    $employee = Employee::findOrFail(Employee::decodeHash($accounts[$name]['employee_id']));
    EmployeeShiftAssignment::create(['employee_id' => $employee->id, 'shift_id' => $shift->id,
        'effective_date' => now()->subDay()->toDateString(), 'working_days' => [1,2,3,4,5,6,7]]);
    Attendance::create(['employee_id' => $employee->id, 'shift_id' => $shift->id, 'date' => now()->toDateString(),
        'status' => $name === 'absent' ? 'absent' : 'present', 'time_in' => $name === 'absent' ? null : now()->subMinute()]);
}
$product = Product::factory()->create(['part_number' => 'MR-'.substr($database, -12), 'name' => 'Recovery fixture product']);
Uom::firstOrCreate(['code' => 'pcs'], ['name' => 'Pieces']);
$fgZone = WarehouseZone::factory()->create(['zone_type' => 'finished_goods']);
WarehouseLocation::factory()->create(['zone_id' => $fgZone->id]);
$machine = Machine::factory()->create(['name' => 'Recovery fixture machine', 'status' => 'running']);
$mold = Mold::create(['mold_code' => 'MR-'.substr($database, -12), 'name' => 'Recovery fixture mold', 'product_id' => $product->id,
    'status' => 'in_use', 'cavity_count' => 1, 'cycle_time_seconds' => 30, 'output_rate_per_hour' => 100,
    'setup_time_minutes' => 0, 'current_shot_count' => 0, 'max_shots_before_maintenance' => 100000, 'lifetime_max_shots' => 1000000]);
$mold->compatibleMachines()->attach($machine->id);
$manager = User::where('email', $accounts['manager']['email'])->firstOrFail();
$wo = WorkOrder::factory()->create(['product_id' => $product->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
    'status' => 'in_progress', 'quantity_target' => 100, 'quantity_produced' => 0, 'quantity_good' => 0,
    'planned_start' => now()->subHour(), 'planned_end' => now()->addHours(4), 'actual_start' => now()->subMinutes(20), 'created_by' => $manager->id]);
$machine->forceFill(['current_work_order_id' => $wo->id])->save();
$mold->forceFill(['current_work_order_id' => $wo->id])->save();
ProductionSchedule::create(['work_order_id' => $wo->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
    'scheduled_start' => now()->subHour(), 'scheduled_end' => now()->addHours(4), 'priority_order' => 0,
    'status' => 'confirmed', 'is_confirmed' => true, 'reserves_capacity' => true, 'confirmed_by' => $manager->id, 'confirmed_at' => now()]);
$worker = Employee::findOrFail(Employee::decodeHash($accounts['worker']['employee_id']));
$crew = WorkOrderCrew::create(['work_order_id' => $wo->id, 'recorder_employee_id' => $worker->id,
    'assigned_by' => $manager->id, 'reason' => 'Disposable production crew', 'assigned_at' => now()->subMinutes(20)]);
$crew->members()->create(['employee_id' => $worker->id,
    'employee_snapshot' => ['employee_no' => $worker->employee_no, 'name' => $worker->full_name]]);
$part = Item::factory()->create(['code' => 'MR-PART', 'name' => 'Recovery bearing', 'item_type' => 'spare_part',
    'unit_of_measure' => 'PCS', 'standard_cost' => '25.00']);
$location = WarehouseLocation::factory()->create();
$warehouse = User::where('email', $accounts['warehouse']['email'])->firstOrFail();
app(StockMovementService::class)->move(new StockMovementInput(type: StockMovementType::Opening,
    itemId: $part->id, quantity: '3.000', toLocationId: $location->id, unitCost: '25.0000',
    remarks: 'Disposable recovery stock', createdBy: $warehouse->id));
file_put_contents(getenv('E2E_MAINTENANCE_RECOVERY_MANIFEST'), json_encode(['database' => $database, 'accounts' => $accounts, 'production_order' => $wo->hash_id,
    'production_number' => $wo->wo_number, 'machine' => $machine->hash_id, 'part' => $part->hash_id,
    'location' => $location->hash_id], JSON_THROW_ON_ERROR));
`;

let viteServer;
let created = false;
let apiStarted = false;
async function main() {
  if (psql(`SELECT 1 FROM pg_database WHERE datname='${database}'`) !== '') throw new Error(`Database ${database} already exists.`);
  psql(`CREATE DATABASE ${database} OWNER ogami`);
  created = true;
  compose('exec', '-T', 'api', 'mkdir', '-p', `${storage}/app`, `${storage}/framework/cache/data`, `${storage}/framework/sessions`, `${storage}/framework/views`, `${storage}/logs`);
  artisan('migrate', '--force');
  const localFixture = path.join(work, 'fixture.php');
  fs.writeFileSync(localFixture, fixturePhp);
  compose('cp', localFixture, `api:${fixtureFile}`);
  const fixtureOutput = artisan('tinker', '--execute', `require '${fixtureFile}';`);
  try { compose('exec', '-T', 'api', 'test', '-f', fixtureManifest); }
  catch { throw new Error(`Fixture did not write manifest: ${fixtureOutput}`); }
  compose('cp', `api:${fixtureManifest}`, manifest);
  const data = JSON.parse(fs.readFileSync(manifest, 'utf8'));
  if (data.database !== database) throw new Error('Fixture selected wrong database.');
  fs.writeFileSync(manifest, JSON.stringify(data));
  compose('exec', '-d', ...flags, 'api', 'sh', '-c', `php artisan serve --no-reload --host=0.0.0.0 --port=${apiPort} > ${apiLog} 2>&1`);
  apiStarted = true;
  const target = `http://${apiIp()}:${apiPort}`;
  let ready = false;
  for (let attempt = 0; attempt < 40; attempt++) {
    try {
      const response = await fetch(`${target}/api/v1/auth/user`, { headers: { Accept: 'application/json' } });
      if (response.status < 500) { ready = true; break; }
    } catch { /* API starting */ }
    await pause(500);
  }
  if (!ready) throw new Error(`Disposable API did not start. Log: ${compose('exec', '-T', 'api', 'cat', apiLog)}`);
  const vite = await import(pathToFileURL(path.join(spa, 'node_modules/vite/dist/node/index.js')).href);
  viteServer = await vite.createServer({ configFile: path.join(spa, 'vite.config.ts'), root: spa, logLevel: 'error',
    server: { host: '127.0.0.1', port: spaPort, strictPort: true,
      hmr: { host: '127.0.0.1', port: spaPort, clientPort: spaPort },
      proxy: { '/api': { target, changeOrigin: false }, '/sanctum': { target, changeOrigin: false } } } });
  await viteServer.listen();
  fs.writeFileSync(configFile, `const { defineConfig, devices } = require('@playwright/test');\nmodule.exports = defineConfig({ testDir: './e2e', testMatch: 'maintenance-automated-recovery.spec.ts', outputDir: '${work}/results', workers: 1, timeout: 180000, expect: { timeout: 15000 }, reporter: [['list']], use: { baseURL: '${base}', ...devices['Desktop Chrome'], actionTimeout: 10000, navigationTimeout: 15000, trace: 'retain-on-failure' } });\n`);
  const status = await new Promise((resolve, reject) => {
    const child = spawn(path.join(spa, 'node_modules/.bin/playwright'), ['test', '-c', configFile], {
      cwd: spa, stdio: 'inherit', env: { ...process.env, E2E_BASE_URL: base,
        E2E_MAINTENANCE_RECOVERY_FIXTURE: manifest } });
    child.on('error', reject);
    child.on('close', resolve);
  });
  if (status !== 0) throw new Error(`Playwright exited ${status}`);
}

main().catch((error) => { console.error(error); process.exitCode = 1; }).finally(async () => {
  if (viteServer) await viteServer.close();
  fs.rmSync(configFile, { force: true });
  if (apiStarted) {
    try { compose('exec', '-T', 'api', 'pkill', '-f', `artisan serve --no-reload --host=0.0.0.0 --port=${apiPort}`); } catch { /* stopped */ }
  }
  if (created) {
    try {
      psql(`SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='${database}' AND pid <> pg_backend_pid()`);
      psql(`DROP DATABASE ${database}`);
    } catch (error) { console.error(`Disposable database cleanup failed: ${error.message}`); process.exitCode = 1; }
  }
  try { compose('exec', '-T', 'api', 'rm', '-rf', storage, fixtureFile, fixtureManifest, apiLog); } catch { /* container unavailable */ }
  if (process.exitCode) console.error(`Failure artifacts: ${work}`);
  else fs.rmSync(work, { recursive: true, force: true });
});
