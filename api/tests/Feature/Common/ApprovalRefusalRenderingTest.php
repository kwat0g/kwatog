<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Exceptions\ForbiddenActionException;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * ApprovalService's two refusals — segregation of duties, and the wrong role for
 * the current step — were the only ones in the approval path stated as
 * `abort(403, '…')` rather than a throw, and that cost a defect twice running.
 * They are now ForbiddenActionException.
 *
 * `abort()` produced a Symfony HttpException, which is invisible to
 * `grep 'throw new'` and matches no named catch, so:
 *   - narrowing a bulk-approve arm to BusinessRuleException swallowed both,
 *     replacing the SoD sentence with "An unexpected error stopped this request"
 *     and logging an error per row for a deliberate refusal (2b82cba8 →
 *     f54822f7);
 *   - the repair had to infer "is this sentence safe to show" from a status code.
 *
 * Retyping only helps if the observable behaviour is unchanged, so this pins the
 * four things that were true of `abort(403, …)` and must stay true. Every one of
 * them is a behaviour, not a type: `BusinessRuleException extends
 * RuntimeException`, so an `expectException(RuntimeException::class)` assertion
 * would have passed against any of these classes.
 */
class ApprovalRefusalRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);

        // APP_DEBUG is true in .env.testing, which changes what a 500 body
        // carries. Pin the production configuration, the one where "the refusal
        // reaches the client" is a claim about the response.
        config(['app.debug' => false]);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
        ]);
    }

    /** A PR submitted into the workflow, so step 1 (department_head) is pending. */
    private function pendingPurchaseRequest(User $requester): PurchaseRequest
    {
        $svc = app(PurchaseRequestService::class);
        $pr  = $svc->create([
            'priority' => 'normal',
            'items'    => [
                ['description' => 'A4 Bond Paper', 'quantity' => '5', 'unit' => 'ream'],
            ],
        ], $requester);

        return $svc->submit($pr);
    }

    /**
     * The wrong-role refusal must stay a 403 carrying its own sentence.
     *
     * PurchaseRequestController::approve catches BusinessRuleException only, so
     * nothing between the guard and the client rewrites this — the render arm in
     * bootstrap/app.php answers. Putting the guard on BusinessRuleException
     * instead would have made it a 422: "fix your input", for a request whose
     * input was fine and whose author simply is not the approver.
     */
    public function test_a_wrong_role_refusal_reaches_the_client_as_403_with_its_message(): void
    {
        $requester = $this->userWithRole('purchasing_officer');
        $pr        = $this->pendingPurchaseRequest($requester);

        // system_admin holds every permission, so the middleware lets this
        // through; its role slug is not the step's `department_head`.
        $approver = $this->userWithRole('system_admin');

        $response = $this->actingAs($approver)
            ->patchJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/approve");

        $response->assertStatus(403);
        $this->assertSame(
            "Only users with role 'department_head' can approve this step.",
            $response->json('message'),
        );
        // Same envelope shape as the 422 arm, so a page that reads `errors`
        // finds the sentence in the same place.
        $response->assertJsonPath('errors.error.0', "Only users with role 'department_head' can approve this step.");
        // `code` is omitted when the refusal declares none: the SPA's 403 branch
        // switches on it ('password_expired' redirects, 'feature_disabled'
        // suppresses the toast) and must not meet a value it has no case for.
        $this->assertArrayNotHasKey('code', (array) $response->json());
        $this->assertSame('pending', $pr->fresh()->approvalRecords()->first()?->action ?? 'pending');
    }

    /**
     * The segregation-of-duties refusal, over HTTP, at the status it was written
     * as. `chain-leave.spec.ts` asserts this exact sentence reaches the user.
     */
    public function test_the_segregation_of_duties_refusal_reaches_the_client_as_403_with_its_message(): void
    {
        // Right role for step 1 AND the submitter: the SoD guard runs first.
        $deptHead = $this->userWithRole('department_head');
        $pr       = $this->pendingPurchaseRequest($deptHead);

        $response = $this->actingAs($deptHead)
            ->patchJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/approve");

        $response->assertStatus(403);
        $this->assertSame('You cannot act on a record you submitted.', $response->json('message'));
    }

    /**
     * One refused row must not abort the batch around it.
     *
     * This is why ForbiddenActionException extends RuntimeException rather than
     * something tidier: Symfony's HttpException does too, and
     * PurchaseRequestService::bulkApprove catches RuntimeException to skip a bad
     * row and carry on. Rooting the new class at Illuminate's
     * AuthorizationException (which extends Exception) would have let a single
     * self-submitted PR in a 20-row batch throw the whole request away — and no
     * existing test would have failed, because nothing exercised the mixed batch.
     */
    public function test_a_refused_row_is_skipped_with_its_message_and_the_batch_continues(): void
    {
        $deptHead = $this->userWithRole('department_head');
        $other    = $this->userWithRole('purchasing_officer');

        $ownPr   = $this->pendingPurchaseRequest($deptHead); // refused: SoD
        $otherPr = $this->pendingPurchaseRequest($other);    // approvable

        $results = app(PurchaseRequestService::class)->bulkApprove([$ownPr->id, $otherPr->id], $deptHead);

        $byId = collect($results)->keyBy('id');

        $this->assertSame('skipped', $byId[$ownPr->hash_id]['status']);
        $this->assertSame('You cannot act on a record you submitted.', $byId[$ownPr->hash_id]['message']);

        $this->assertSame('approved', $byId[$otherPr->hash_id]['status'], 'The refused row must not take the batch down with it.');
        $this->assertSame('approved', $otherPr->fresh()->approvalRecords()->first()?->action);
    }

    /**
     * A refusal the system decided on purpose is not a fault to report.
     *
     * `abort(403, …)` was silent — HttpException is in Laravel's
     * internalDontReport list — and f54822f7's complaint was precisely that a
     * deliberate refusal produced a Log::error per row. Retyping would have
     * quietly reintroduced that at the framework level, since a plain
     * RuntimeException subclass IS reported, so bootstrap/app.php registers the
     * class as dontReport. The control below is what makes this assertion mean
     * something.
     */
    public function test_a_refusal_is_not_reported_but_a_genuine_fault_still_is(): void
    {
        // Collision decorates the handler under `artisan test` and forwards
        // shouldReport() to the application handler, so this reads the real
        // dontReport list either way.
        $handler = app(ExceptionHandler::class);

        $this->assertFalse(
            $handler->shouldReport(new ForbiddenActionException('You cannot act on a record you submitted.')),
            'An authorization refusal must not write an error log line.',
        );
        $this->assertTrue(
            $handler->shouldReport(new RuntimeException('Something actually broke.')),
            'dontReport must not have been widened to cover real faults.',
        );
    }

    /**
     * The refusal is not a business rule, and the difference is observable: a
     * BusinessRuleException from the same endpoint is a 422. If someone later
     * reparents ForbiddenActionException onto BusinessRuleException "for
     * consistency", the 403 test above fails — this is the other half, showing
     * the two statuses are genuinely distinct rather than an accident of ordering
     * between two render arms.
     */
    public function test_a_business_rule_from_the_same_endpoint_is_still_a_422(): void
    {
        $requester = $this->userWithRole('purchasing_officer');
        $pr        = $this->pendingPurchaseRequest($requester);
        $approver  = $this->userWithRole('system_admin');

        $this->mock(PurchaseRequestService::class, function ($mock): void {
            $mock->shouldReceive('approve')->once()->andThrow(
                new BusinessRuleException('Only pending PRs can be approved.'),
            );
        });

        $this->actingAs($approver)
            ->patchJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only pending PRs can be approved.');
    }
}
