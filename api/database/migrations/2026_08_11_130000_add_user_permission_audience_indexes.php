<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audience lookups are used by return, alert, and chain notifications.
     * The existing primary key on role_permissions is role-first, while these
     * queries discover roles from a permission slug and then users from their
     * role. Keep both directions indexed so the cross-module fan-out does not
     * degrade as the user and permission tables grow.
     */
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->index(
                ['role_id', 'is_active', 'deleted_at'],
                'users_role_active_deleted_lookup',
            );
        });

        Schema::table('role_permissions', static function (Blueprint $table): void {
            $table->index(
                ['permission_id', 'role_id'],
                'role_permissions_permission_role_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::table('role_permissions', static function (Blueprint $table): void {
            $table->dropIndex('role_permissions_permission_role_lookup');
        });

        Schema::table('users', static function (Blueprint $table): void {
            $table->dropIndex('users_role_active_deleted_lookup');
        });
    }
};
