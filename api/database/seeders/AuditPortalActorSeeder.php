<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AuditPortalActorSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production') || ! filter_var(env('AUDIT_E2E_TESTING', false), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('Audit-only portal actors require AUDIT_E2E_TESTING=true outside production.');
        }

        $vendor = Vendor::query()->active()->orderBy('id')->firstOrFail();
        $customer = Customer::query()->active()->orderBy('id')->firstOrFail();

        $supplier = SupplierPortalUser::withTrashed()->updateOrCreate(
            ['email' => 'portal@supp.test'],
            [
                'vendor_id' => $vendor->id,
                'name' => $vendor->name.' Portal',
                'password' => Hash::make('password'),
                'is_active' => true,
                'must_change_password' => false,
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'password_changed_at' => now(),
            ],
        );
        $supplier->restore();

        $customerUser = CustomerPortalUser::withTrashed()->updateOrCreate(
            ['email' => 'portal@cust.test'],
            [
                'customer_id' => $customer->id,
                'name' => $customer->name.' Portal',
                'password' => Hash::make('password'),
                'is_active' => true,
                'must_change_password' => false,
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'password_changed_at' => now(),
            ],
        );
        $customerUser->restore();
    }
}
