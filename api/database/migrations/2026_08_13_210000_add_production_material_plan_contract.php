<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->string('work_order_class', 30)->default('standard')->after('status');
            $table->text('exception_reason')->nullable()->after('work_order_class');
            $table->foreignId('exception_authorized_by')->nullable()->after('exception_reason')->constrained('users')->nullOnDelete();
            $table->string('material_plan_source', 30)->nullable()->after('exception_authorized_by');
        });
        Schema::table('work_order_outputs', fn (Blueprint $table) => $table->json('material_lineage')->nullable()->after('remarks'));
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_class_check CHECK (work_order_class IN ('standard','service','non_stock','prototype'))");
            DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_exception_authorization_check CHECK (work_order_class = 'standard' OR (exception_reason IS NOT NULL AND btrim(exception_reason) <> '' AND exception_authorized_by IS NOT NULL))");
        } elseif ($driver === 'sqlite') {
            DB::statement("CREATE TRIGGER work_orders_material_plan_insert_guard BEFORE INSERT ON work_orders WHEN NEW.work_order_class NOT IN ('standard','service','non_stock','prototype') OR (NEW.work_order_class <> 'standard' AND (NEW.exception_reason IS NULL OR trim(NEW.exception_reason) = '' OR NEW.exception_authorized_by IS NULL)) BEGIN SELECT RAISE(ABORT, 'invalid work order material-plan contract'); END");
            DB::statement("CREATE TRIGGER work_orders_material_plan_update_guard BEFORE UPDATE OF work_order_class, exception_reason, exception_authorized_by ON work_orders WHEN NEW.work_order_class NOT IN ('standard','service','non_stock','prototype') OR (NEW.work_order_class <> 'standard' AND (NEW.exception_reason IS NULL OR trim(NEW.exception_reason) = '' OR NEW.exception_authorized_by IS NULL)) BEGIN SELECT RAISE(ABORT, 'invalid work order material-plan contract'); END");
        }
    }
    public function down(): void {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_exception_authorization_check');
            DB::statement('ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_class_check');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS work_orders_material_plan_insert_guard');
            DB::statement('DROP TRIGGER IF EXISTS work_orders_material_plan_update_guard');
        }
        Schema::table('work_order_outputs', fn (Blueprint $table) => $table->dropColumn('material_lineage'));
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropForeign(['exception_authorized_by']);
            $table->dropColumn(['work_order_class', 'exception_reason', 'exception_authorized_by', 'material_plan_source']);
        });
    }
};
