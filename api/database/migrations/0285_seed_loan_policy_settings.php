<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROWS = [
        ['company_loan', 'Company Loan', 0.0, 1.0],
        ['cash_advance', 'Cash Advance', 0.0, 1.0],
        ['sss_loan', 'SSS Salary Loan', 0.10, 1.0],
        ['pagibig_loan', 'Pag-IBIG Multi-Purpose Loan', 0.105, 1.0],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$type, $label, $rate, $multiplier]) {
            $this->insert("loans.{$type}.annual_interest_rate", $rate, "{$label} Annual Interest Rate", 'Decimal annual rate, where 0.10 means 10%.');
            $this->insert("loans.{$type}.max_salary_multiplier", $multiplier, "{$label} Salary Limit", 'Maximum principal as a multiple of the employee monthly salary.');
        }
    }

    public function down(): void
    {
        $keys = [];
        foreach (self::ROWS as [$type]) {
            $keys[] = "loans.{$type}.annual_interest_rate";
            $keys[] = "loans.{$type}.max_salary_multiplier";
        }
        DB::table('settings')->whereIn('key', $keys)->delete();
    }

    private function insert(string $key, float $value, string $label, string $description): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => $key,
            'value' => json_encode($value),
            'group' => 'loans',
            'label' => $label,
            'description' => $description,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
