<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'accounting.bills.three_way_override' => 'Approve 3-Way-Match Overrides',
        'accounting.bills.void_payment' => 'Void Bill Payments',
    ];

    public function up(): void
    {
        if (Schema::hasTable('bills')) {
            Schema::table('bills', function (Blueprint $table): void {
                if (! Schema::hasColumn('bills', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable()->after('status');
                }
                if (! Schema::hasColumn('bills', 'cancelled_by')) {
                    $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('bill_payments')) {
            Schema::table('bill_payments', function (Blueprint $table): void {
                if (! Schema::hasColumn('bill_payments', 'status')) {
                    $table->string('status', 20)->default('posted')->after('reference_number');
                }
                if (! Schema::hasColumn('bill_payments', 'voided_at')) {
                    $table->timestamp('voided_at')->nullable();
                }
                if (! Schema::hasColumn('bill_payments', 'voided_by')) {
                    $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('bill_payments', 'void_reason')) {
                    $table->text('void_reason')->nullable();
                }
                if (! Schema::hasColumn('bill_payments', 'void_reversal_journal_entry_id')) {
                    $table->foreignId('void_reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                }
                if (! Schema::hasColumn('bill_payments', 'replacement_payment_id')) {
                    $table->foreignId('replacement_payment_id')->nullable()->constrained('bill_payments')->nullOnDelete();
                }
            });
        }

        // The AP workflow deliberately defines one bill per accepted GRN. Do
        // not silently discard or merge legacy duplicates during deployment:
        // force an explicit finance reconciliation before adding the durable
        // invariant.
        if (Schema::hasTable('bills') && Schema::hasColumn('bills', 'goods_receipt_note_id')) {
            $hasDuplicates = DB::table('bills')
                ->select('goods_receipt_note_id')
                ->whereNotNull('goods_receipt_note_id')
                ->groupBy('goods_receipt_note_id')
                ->havingRaw('COUNT(*) > 1')
                ->exists();
            if ($hasDuplicates) {
                throw new \RuntimeException('Cannot enforce one AP bill per GRN until duplicate goods receipt bills are reconciled.');
            }
            if (! Schema::hasIndex('bills', 'bills_goods_receipt_note_unique')) {
                Schema::table('bills', function (Blueprint $table): void {
                    $table->unique('goods_receipt_note_id', 'bills_goods_receipt_note_unique');
                });
            }
        }

        foreach (self::PERMISSIONS as $slug => $name) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $slug],
                ['name' => $name, 'module' => 'accounting', 'updated_at' => now(), 'created_at' => now()],
            );
            $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');
            $roleId = DB::table('roles')->where('slug', 'finance_officer')->value('id');
            if ($permissionId && $roleId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->delete();

        if (Schema::hasTable('bills') && Schema::hasIndex('bills', 'bills_goods_receipt_note_unique')) {
            Schema::table('bills', fn (Blueprint $table) => $table->dropUnique('bills_goods_receipt_note_unique'));
        }
        if (Schema::hasTable('bill_payments')) {
            Schema::table('bill_payments', function (Blueprint $table): void {
                if (Schema::hasColumn('bill_payments', 'replacement_payment_id')) {
                    $table->dropForeign(['replacement_payment_id']);
                }
                if (Schema::hasColumn('bill_payments', 'void_reversal_journal_entry_id')) {
                    $table->dropForeign(['void_reversal_journal_entry_id']);
                }
                if (Schema::hasColumn('bill_payments', 'voided_by')) {
                    $table->dropForeign(['voided_by']);
                }
                $columns = array_values(array_filter([
                    Schema::hasColumn('bill_payments', 'status') ? 'status' : null,
                    Schema::hasColumn('bill_payments', 'voided_at') ? 'voided_at' : null,
                    Schema::hasColumn('bill_payments', 'void_reason') ? 'void_reason' : null,
                    Schema::hasColumn('bill_payments', 'void_reversal_journal_entry_id') ? 'void_reversal_journal_entry_id' : null,
                    Schema::hasColumn('bill_payments', 'replacement_payment_id') ? 'replacement_payment_id' : null,
                    Schema::hasColumn('bill_payments', 'voided_by') ? 'voided_by' : null,
                ]));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
        if (Schema::hasTable('bills')) {
            Schema::table('bills', function (Blueprint $table): void {
                if (Schema::hasColumn('bills', 'cancelled_by')) {
                    $table->dropForeign(['cancelled_by']);
                }
                if (Schema::hasColumn('bills', 'cancelled_at')) {
                    $table->dropColumn('cancelled_at');
                }
                if (Schema::hasColumn('bills', 'cancelled_by')) {
                    $table->dropColumn('cancelled_by');
                }
            });
        }
    }
};
