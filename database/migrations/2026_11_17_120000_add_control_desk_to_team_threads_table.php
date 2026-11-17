<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * team-chat.control-desk.attend: attend the «Mesa de control» of the
     * internal chat (accept requests, answer them, mark them complete).
     */
    private const PERMISSION = 'team-chat.control-desk.attend';

    /**
     * Run the migrations.
     *
     * «Mesa de control»: a team thread of type `desk`, one per requester
     * (`desk_requester_id`, unique, so opening it twice at once never
     * creates two), with the state of its current request:
     *
     * - `desk_status`: pending (waiting, unassigned), open (accepted by
     *   `desk_assigned_user_id` at `desk_accepted_at`) or closed (marked
     *   complete at `desk_completed_at` by `desk_completed_by_user_id`).
     * - `desk_requested_at`: when the current request entered the waiting
     *   list (orders «Pendientes» and «Mías»); `desk_request_message_id`:
     *   first message of the current request (where the auditor's unread
     *   messages start when she accepts it).
     *
     * Additive and nullable: direct chats and groups leave every column
     * null. Foreign keys never cascade, like the rest of the internal chat.
     *
     * The permission is granted to the root roles, to the default
     * Administrador and to every existing role that already uses the
     * internal chat (team-chat.use): without it a role becomes a
     * «solicitante» that only sees the desk, so on an existing installation
     * nobody loses their chats on deploy. Turning a role into requesters is
     * an explicit decision taken from «Roles y permisos» (removing it).
     */
    public function up(): void
    {
        Schema::table('team_threads', function (Blueprint $table) {
            $table->foreignId('desk_requester_id')->nullable()->after('direct_key')->unique()->constrained('users')->restrictOnDelete();
            $table->string('desk_status', 16)->nullable()->after('desk_requester_id');
            $table->foreignId('desk_assigned_user_id')->nullable()->after('desk_status')->constrained('users')->nullOnDelete();
            $table->timestamp('desk_requested_at')->nullable()->after('desk_assigned_user_id');
            $table->unsignedBigInteger('desk_request_message_id')->nullable()->after('desk_requested_at');
            $table->timestamp('desk_accepted_at')->nullable()->after('desk_request_message_id');
            $table->timestamp('desk_completed_at')->nullable()->after('desk_accepted_at');
            $table->foreignId('desk_completed_by_user_id')->nullable()->after('desk_completed_at')->constrained('users')->nullOnDelete();

            $table->index(['type', 'desk_status', 'desk_requested_at']);
            $table->index(['desk_assigned_user_id', 'desk_status']);
        });

        DB::transaction(function (): void {
            $now = now();

            DB::table('permissions')->insertOrIgnore(['name' => self::PERMISSION, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);

            $permissionId = DB::table('permissions')->where('guard_name', 'web')->where('name', self::PERMISSION)->value('id');
            $chatPermissionId = DB::table('permissions')->where('guard_name', 'web')->where('name', 'team-chat.use')->value('id');

            $roleIds = DB::table('roles')
                ->where('guard_name', 'web')
                ->where(fn ($roles) => $roles
                    ->where('is_root', true)
                    ->orWhere('name', 'administrator')
                    ->orWhereIn('id', DB::table('role_has_permissions')->where('permission_id', $chatPermissionId ?? 0)->select('role_id')))
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        });

        Cache::forget((string) config('permission.cache.key'));
    }

    /**
     * Reverse the migrations: the columns go, and the permission with its
     * grants (foreign keys cascade). Desk threads stay as rows of an unknown
     * type: drop them by hand if the feature is rolled back for good.
     */
    public function down(): void
    {
        Schema::table('team_threads', function (Blueprint $table) {
            $table->dropForeign(['desk_requester_id']);
            $table->dropForeign(['desk_assigned_user_id']);
            $table->dropForeign(['desk_completed_by_user_id']);
        });

        Schema::table('team_threads', function (Blueprint $table) {
            $table->dropIndex(['type', 'desk_status', 'desk_requested_at']);
            $table->dropIndex(['desk_assigned_user_id', 'desk_status']);
            $table->dropUnique(['desk_requester_id']);
            $table->dropColumn(['desk_requester_id', 'desk_status', 'desk_assigned_user_id', 'desk_requested_at', 'desk_request_message_id', 'desk_accepted_at', 'desk_completed_at', 'desk_completed_by_user_id']);
        });

        DB::transaction(function (): void {
            DB::table('permissions')->where('guard_name', 'web')->where('name', self::PERMISSION)->delete();
        });

        Cache::forget((string) config('permission.cache.key'));
    }
};
