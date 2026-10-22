<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Morph names a user may be stored with in model_has_roles / audit_logs.
     *
     * @var list<string>
     */
    private const USER_TYPES = ['user', 'App\\Models\\User'];

    /**
     * @var list<string>
     */
    private const ROLE_TYPES = ['role', 'App\\Models\\Role'];

    /**
     * Run the migrations.
     *
     * Entries about the root authority (performed by a Superadministrador, or
     * about a root user or the root role) are only visible to the root
     * authority. The flag is fixed when the entry is written because roles
     * change later; existing entries are classified with the current roles,
     * the best evidence available for the past. Idempotent: running it again
     * only repeats the classification.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('audit_logs', 'restricted')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->boolean('restricted')->default(false)->after('user_agent');
                $table->index(['restricted', 'created_at']);
            });
        }

        $rootRoleIds = DB::table('roles')->where('is_root', true)->pluck('id')->all();

        if ($rootRoleIds === []) {
            return;
        }

        $rootUserIds = DB::table('model_has_roles')
            ->whereIn('role_id', $rootRoleIds)
            ->whereIn('model_type', self::USER_TYPES)
            ->pluck('model_id')
            ->all();

        DB::table('audit_logs')
            ->where(fn (Builder $query) => $query
                ->whereIn('user_id', $rootUserIds)
                ->orWhere(fn (Builder $subject) => $subject->whereIn('auditable_type', self::USER_TYPES)->whereIn('auditable_id', $rootUserIds))
                ->orWhere(fn (Builder $subject) => $subject->whereIn('auditable_type', self::ROLE_TYPES)->whereIn('auditable_id', $rootRoleIds)))
            ->update(['restricted' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['restricted', 'created_at']);
            $table->dropColumn('restricted');
        });
    }
};
