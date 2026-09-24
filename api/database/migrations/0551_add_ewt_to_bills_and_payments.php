<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add EWT columns to bills table
        if (! Schema::hasColumn('bills', 'withholding_tax_type')) {
            Schema::table('bills', function (Blueprint $table): void {
                $table->string('withholding_tax_type', 20)
                    ->default('none')
                    ->after('is_vatable');
                $table->decimal('ewt_rate', 6, 4)
                    ->default(0)
                    ->after('withholding_tax_type');
                $table->decimal('ewt_amount', 15, 2)
                    ->default(0)
                    ->after('ewt_rate');
            });
        }

        // Add EWT columns to bill_payments table
        if (! Schema::hasColumn('bill_payments', 'ewt_amount')) {
            Schema::table('bill_payments', function (Blueprint $table): void {
                $table->decimal('ewt_amount', 15, 2)
                    ->default(0)
                    ->after('amount');
                $table->decimal('cash_amount', 15, 2)
                    ->nullable()
                    ->after('ewt_amount');
            });
        }

        // Add COA account for EWT Payable (2051) if it doesn't exist
        if (Schema::hasTable('accounts')) {
            $parentId = DB::table('accounts')->where('code', '2000')->value('id');
            if ($parentId && ! DB::table('accounts')->where('code', '2051')->exists()) {
                DB::table('accounts')->insert([
                    'code' => '2051',
                    'name' => 'Expanded Withholding Tax Payable',
                    'type' => 'liability',
                    'normal_balance' => 'credit',
                    'parent_id' => $parentId,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Add setting for EWT payable account code
        if (Schema::hasTable('settings')) {
            DB::table('settings')->updateOrInsert(
                ['key' => 'accounting.accounts.ewt_payable_code'],
                [
                    'value' => json_encode('2051'),
                    'group' => 'accounting',
                    'label' => 'Expanded Withholding Tax Payable Account Code',
                    'description' => 'Liability account credited when EWT is withheld on supplier payments.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            if (Schema::hasColumn('bills', 'withholding_tax_type')) {
                $table->dropColumn(['withholding_tax_type', 'ewt_rate', 'ewt_amount']);
            }
        });

        Schema::table('bill_payments', function (Blueprint $table): void {
            if (Schema::hasColumn('bill_payments', 'ewt_amount')) {
                $table->dropColumn(['ewt_amount', 'cash_amount']);
            }
        });

        if (Schema::hasTable('accounts')) {
            DB::table('accounts')->where('code', '2051')->delete();
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('key', 'accounting.accounts.ewt_payable_code')->delete();
        }
    }
};
