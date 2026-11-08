<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Services\OutboxEventCodec;
use App\Common\Models\Document;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\EmployeeDocumentsChanged;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Services\EmployeeDocumentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeDocumentsRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_employee_document_creation_stages_a_private_content_free_refresh(): void
    {
        $employee = Employee::factory()->create();
        $actor = $this->userWithPermissions('hr.employees.documents.upload');
        $this->actingAs($actor);

        $document = DB::transaction(fn () => EmployeeDocument::query()->create([
            'employee_id' => $employee->id,
            'document_type' => 'certificate',
            'file_name' => 'confidential-certificate.pdf',
            'file_path' => 'employee-documents/private/confidential-certificate.pdf',
            'uploaded_at' => now(),
        ]));

        $message = DB::table('event_outbox')
            ->where('event_type', EmployeeDocumentsChanged::class)
            ->sole();
        $event = app(OutboxEventCodec::class)->decode(
            $message->event_type,
            json_decode((string) $message->payload, true, flags: JSON_THROW_ON_ERROR),
        );

        self::assertInstanceOf(EmployeeDocumentsChanged::class, $event);
        self::assertSame('created', $event->action);
        self::assertSame($employee->hash_id, $event->employeeHashId);
        self::assertSame(['private-employee.documents.'.$employee->hash_id], array_map(
            static fn ($channel) => $channel->name,
            $event->broadcastOn(),
        ));
        self::assertSame($actor->hash_id, $event->actorHashId);
        self::assertSame($document->updated_at?->format('Y-m-d\\TH:i:s.uP'), $event->version);
        self::assertStringNotContainsString('confidential-certificate.pdf', json_encode($event->broadcastWith(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('employee-documents/private/', json_encode($event->broadcastWith(), JSON_THROW_ON_ERROR));
    }

    public function test_employee_document_delete_and_restore_refresh_the_owner_scoped_list(): void
    {
        $employee = Employee::factory()->create();
        $actor = $this->userWithPermissions('hr.employees.documents.delete', 'hr.employees.view_sensitive');
        $this->actingAs($actor);
        $document = EmployeeDocument::withoutEvents(fn () => EmployeeDocument::query()->create([
            'employee_id' => $employee->id,
            'document_type' => 'certificate',
            'file_name' => 'certificate.pdf',
            'file_path' => 'employee-documents/private/certificate.pdf',
            'uploaded_at' => now(),
        ]));

        app(EmployeeDocumentService::class)->delete($employee, $document, $actor);

        $deleted = DB::table('event_outbox')
            ->where('event_type', EmployeeDocumentsChanged::class)
            ->sole();
        self::assertSame('deleted', json_decode((string) $deleted->payload, true, flags: JSON_THROW_ON_ERROR)['action']);

        DB::table('event_outbox')->where('event_type', EmployeeDocumentsChanged::class)->delete();
        $document = EmployeeDocument::withTrashed()->findOrFail($document->getKey());
        app(EmployeeDocumentService::class)->restore($employee, $document, $actor);

        $restored = DB::table('event_outbox')
            ->where('event_type', EmployeeDocumentsChanged::class)
            ->sole();
        self::assertSame('restored', json_decode((string) $restored->payload, true, flags: JSON_THROW_ON_ERROR)['action']);
    }

    public function test_employee_vault_rows_refresh_employee_lists_but_other_vault_rows_do_not(): void
    {
        $employee = Employee::factory()->create();
        $actor = $this->userWithPermissions('hr.employees.documents.view');
        $this->actingAs($actor);

        DB::transaction(fn () => Document::query()->create([
            'document_type' => 'invoice',
            'entity_type' => Employee::class,
            'entity_id' => $employee->id,
            'file_path' => 'documents/private/employee.pdf',
            'file_name' => 'employee.pdf',
            'file_size' => 128,
            'mime_type' => 'application/pdf',
            'generated_at' => now(),
            'is_confidential' => true,
        ]));

        $employeeRefresh = DB::table('event_outbox')
            ->where('event_type', EmployeeDocumentsChanged::class)
            ->sole();
        $payload = json_decode((string) $employeeRefresh->payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($employee->hash_id, $payload['employeeHashId']);

        DB::table('event_outbox')->where('event_type', EmployeeDocumentsChanged::class)->delete();
        Document::query()->create([
            'document_type' => 'invoice',
            'entity_type' => 'App\\Modules\\Accounting\\Models\\Invoice',
            'entity_id' => 900001,
            'file_path' => 'documents/private/invoice.pdf',
            'file_name' => 'invoice.pdf',
            'file_size' => 128,
            'mime_type' => 'application/pdf',
            'generated_at' => now(),
            'is_confidential' => true,
        ]);

        self::assertSame(0, DB::table('event_outbox')->where('event_type', EmployeeDocumentsChanged::class)->count());
    }

    public function test_document_list_channel_matches_the_employee_row_scope(): void
    {
        $employee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $owner = $this->userWithPermissions('hr.employees.documents.view');
        $owner->update(['employee_id' => $employee->id]);
        $other = $this->userWithPermissions('hr.employees.documents.view');
        $other->update(['employee_id' => $otherEmployee->id]);
        $sensitiveViewerWithoutDocumentPermission = $this->userWithPermissions('hr.employees.view_sensitive');

        $this->authorizeChannel($owner, $employee)->assertOk();
        $this->authorizeChannel($other, $employee)->assertForbidden();
        $this->authorizeChannel($sensitiveViewerWithoutDocumentPermission, $employee)->assertForbidden();
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $role = Role::query()->create([
            'name' => 'Employee document refresh '.substr(uniqid(), -6),
            'slug' => 'employee_document_refresh_'.substr(uniqid(), -6),
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id')->all());

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function authorizeChannel(User $user, Employee $employee)
    {
        return $this->actingAs($user)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-employee.documents.'.$employee->hash_id,
        ]);
    }
}
