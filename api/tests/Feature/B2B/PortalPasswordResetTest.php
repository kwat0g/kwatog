<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Models\AuditLog;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Mail\PortalPasswordResetMail;
use App\Modules\B2B\Mail\PortalAccessInvitationMail;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Services\PortalInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PortalPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function makeSupplier(string $email): SupplierPortalUser
    {
        return SupplierPortalUser::create([
            'vendor_id' => Vendor::factory()->create()->id,
            'name' => 'Supplier Portal User',
            'email' => $email,
            'password' => Hash::make('OldPassword-1!'),
            'is_active' => true,
        ]);
    }

    public function test_customer_reset_email_is_queued_and_token_updates_password(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create();
        $user = CustomerPortalUser::create([
            'customer_id' => $customer->id,
            'name' => 'Customer Portal User',
            'email' => 'customer-reset@example.test',
            'password' => Hash::make('OldPassword-1!'),
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/b2b/customer/forgot-password', ['email' => $user->email])
            ->assertOk();

        $mail = null;
        Mail::assertQueued(PortalPasswordResetMail::class, function (PortalPasswordResetMail $queued) use (&$mail): bool {
            $mail = $queued;
            return $queued->portalType === 'customer';
        });
        self::assertNotNull($mail);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'portal.password.reset_requested',
            'model_type' => CustomerPortalUser::class,
            'model_id' => $user->id,
        ]);

        $this->postJson('/api/v1/b2b/customer/reset-password', [
            'token' => $mail->token,
            'password' => 'NewPassword-2!',
            'password_confirmation' => 'NewPassword-2!',
        ])->assertOk();

        self::assertTrue(Hash::check('NewPassword-2!', $user->fresh()->password));
        $this->assertDatabaseHas('portal_password_reset_tokens', [
            'portal_type' => 'customer',
            'email' => $user->email,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'portal.password.reset',
            'model_type' => CustomerPortalUser::class,
            'model_id' => $user->id,
        ]);
    }

    public function test_supplier_reset_request_does_not_reveal_unknown_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/b2b/supplier/forgot-password', [
            'email' => 'unknown-supplier@example.test',
        ]);

        $response->assertOk()->assertJsonPath('message', 'If an active portal account exists for that email, a reset link will be sent shortly.');
        Mail::assertNothingQueued();
    }

    public function test_supplier_reset_email_uses_supplier_portal_type(): void
    {
        Mail::fake();
        $vendor = Vendor::factory()->create();
        $user = SupplierPortalUser::create([
            'vendor_id' => $vendor->id,
            'name' => 'Supplier Portal User',
            'email' => 'supplier-reset@example.test',
            'password' => Hash::make('OldPassword-1!'),
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/b2b/supplier/forgot-password', ['email' => $user->email])
            ->assertOk();

        Mail::assertQueued(PortalPasswordResetMail::class, fn (PortalPasswordResetMail $mail): bool => $mail->portalType === 'supplier');
    }

    public function test_supplier_reset_request_invalidates_the_previous_link(): void
    {
        Mail::fake();
        $user = $this->makeSupplier('supplier-two-links@example.test');

        $tokens = [];
        $this->postJson('/api/v1/b2b/supplier/forgot-password', ['email' => $user->email])->assertOk();
        $this->postJson('/api/v1/b2b/supplier/forgot-password', ['email' => $user->email])->assertOk();
        Mail::assertQueued(PortalPasswordResetMail::class, function (PortalPasswordResetMail $queued) use (&$tokens): bool {
            $tokens[] = $queued->token;
            return true;
        });
        self::assertCount(2, $tokens);

        // Newest-only semantics: the first link must be dead the moment a
        // second is issued, or a stale email keeps working.
        $this->postJson('/api/v1/b2b/supplier/reset-password', [
            'token' => $tokens[0],
            'password' => 'NewPassword-2!',
            'password_confirmation' => 'NewPassword-2!',
        ])->assertStatus(422);
        self::assertTrue(Hash::check('OldPassword-1!', $user->fresh()->password));

        $this->postJson('/api/v1/b2b/supplier/reset-password', [
            'token' => $tokens[1],
            'password' => 'NewPassword-2!',
            'password_confirmation' => 'NewPassword-2!',
        ])->assertOk();
        self::assertTrue(Hash::check('NewPassword-2!', $user->fresh()->password));
    }

    public function test_supplier_reset_revokes_live_portal_tokens_and_clears_lock_state(): void
    {
        Mail::fake();
        $user = $this->makeSupplier('supplier-reset-revokes@example.test');
        $user->forceFill(['failed_login_attempts' => 3, 'locked_until' => now()->addMinutes(10)])->save();
        $user->createToken('supplier_portal');
        self::assertSame(1, $user->tokens()->count());

        $this->postJson('/api/v1/b2b/supplier/forgot-password', ['email' => $user->email])->assertOk();
        $token = null;
        Mail::assertQueued(PortalPasswordResetMail::class, function (PortalPasswordResetMail $queued) use (&$token): bool {
            $token = $queued->token;
            return true;
        });

        $this->postJson('/api/v1/b2b/supplier/reset-password', [
            'token' => $token,
            'password' => 'NewPassword-2!',
            'password_confirmation' => 'NewPassword-2!',
        ])->assertOk();

        // A reset is the recovery path for a compromised or locked-out account:
        // every existing bearer token has to die with the old password.
        $fresh = $user->fresh();
        self::assertSame(0, $fresh->tokens()->count());
        self::assertSame(0, (int) $fresh->failed_login_attempts);
        self::assertNull($fresh->locked_until);
    }

    public function test_customer_invitation_creates_account_and_queues_branded_access_mail(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create();

        $result = app(PortalInvitationService::class)->inviteCustomer(
            $customer,
            'Invited Contact',
            'invited-contact@example.test',
        );

        self::assertSame($customer->id, $result['user']->customer_id);
        self::assertTrue(Hash::check($result['temporary_password'], $result['user']->fresh()->password));
        self::assertTrue($result['user']->fresh()->must_change_password);
        Mail::assertQueued(PortalAccessInvitationMail::class, fn (PortalAccessInvitationMail $mail): bool =>
            $mail->portalType === 'customer' && $mail->recipientName === 'Invited Contact'
        );
    }

    public function test_portal_reset_clears_first_login_requirement(): void
    {
        Mail::fake();
        $vendor = Vendor::factory()->create();
        $user = SupplierPortalUser::create([
            'vendor_id' => $vendor->id,
            'name' => 'Supplier Portal User',
            'email' => 'supplier-reset-required@example.test',
            'password' => Hash::make('OldPassword-1!'),
            'is_active' => true,
            'must_change_password' => true,
        ]);

        $this->postJson('/api/v1/b2b/supplier/forgot-password', ['email' => $user->email])->assertOk();
        $mail = null;
        Mail::assertQueued(PortalPasswordResetMail::class, function (PortalPasswordResetMail $queued) use (&$mail): bool {
            $mail = $queued;
            return true;
        });

        $this->postJson('/api/v1/b2b/supplier/reset-password', [
            'token' => $mail->token,
            'password' => 'NewPassword-2!',
            'password_confirmation' => 'NewPassword-2!',
        ])->assertOk();

        self::assertFalse($user->fresh()->must_change_password);
    }

    public function test_portal_reset_rejects_weak_password(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create();
        $user = CustomerPortalUser::create([
            'customer_id' => $customer->id,
            'name' => 'Customer Portal User',
            'email' => 'customer-weak-reset@example.test',
            'password' => Hash::make('OldPassword-1!'),
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/b2b/customer/forgot-password', ['email' => $user->email])->assertOk();
        $mail = null;
        Mail::assertQueued(PortalPasswordResetMail::class, function (PortalPasswordResetMail $queued) use (&$mail): bool {
            $mail = $queued;
            return true;
        });

        $this->postJson('/api/v1/b2b/customer/reset-password', [
            'token' => $mail->token,
            'password' => 'weakpassword',
            'password_confirmation' => 'weakpassword',
        ])->assertStatus(422)->assertJsonValidationErrorFor('password');
    }
}
