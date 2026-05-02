<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            $table->string('employee_no', 20)->unique();
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('suffix', 20)->nullable();

            $table->date('birth_date');
            $table->string('gender', 10);
            $table->string('civil_status', 20);
            $table->string('nationality', 50)->default('Filipino');
            $table->string('photo_path')->nullable();

            // Address
            $table->string('street_address', 200)->nullable();
            $table->string('barangay', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('zip_code', 10)->nullable();

            // Contact
            $table->string('mobile_number', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('emergency_contact_name', 100)->nullable();
            $table->string('emergency_contact_relation', 50)->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();

            // Government IDs (encrypted casts → text)
            $table->text('sss_no')->nullable();
            $table->text('philhealth_no')->nullable();
            $table->text('pagibig_no')->nullable();
            $table->text('tin')->nullable();

            // Employment
            $table->foreignId('department_id')->constrained('departments');
            $table->foreignId('position_id')->constrained('positions');
            $table->string('employment_type', 20);          // regular|probationary|contractual|project_based
            $table->string('pay_type', 10);                 // monthly|daily
            $table->date('date_hired');
            $table->date('date_regularized')->nullable();
            $table->decimal('basic_monthly_salary', 15, 2)->nullable();
            $table->decimal('daily_rate', 15, 2)->nullable();

            // Banking
            $table->string('bank_name', 100)->nullable();
            $table->text('bank_account_no')->nullable();    // encrypted

            $table->string('status', 20)->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('department_id');
            $table->index('position_id');
            $table->index('date_hired');
            $table->index('employment_type');
            $table->index('pay_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
