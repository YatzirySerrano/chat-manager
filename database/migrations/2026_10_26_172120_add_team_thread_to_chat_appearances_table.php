<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SINGLE_CHAT_CHECK = 'chat_appearances_single_chat_check';

    /**
     * Run the migrations.
     *
     * Personal chat colors also for internal chats. A row now targets at
     * most one chat: a customer conversation, an internal thread or neither
     * (the user's default for every chat). A thread row has no
     * conversation, so its conversation_key is 0 like the default's: the
     * old unique (user_id, conversation_key) would make a thread row collide
     * with the default. The stored generated team_thread_key (0 without
     * thread) joins the unique index, so every existing row keeps its
     * meaning and stays unique ((user, conversation, 0) and (user, 0, 0)).
     * The new index is created before the old one is dropped: user_id's
     * foreign key always has an index starting with it. A CHECK keeps a
     * row from pointing at a conversation and a thread at once (MariaDB
     * only: MySQL refuses CHECK constraints on columns of foreign keys with
     * referential actions).
     */
    public function up(): void
    {
        Schema::table('chat_appearances', function (Blueprint $table) {
            $table->foreignId('team_thread_id')->nullable()->after('conversation_id')->constrained('team_threads')->cascadeOnDelete();
            $table->unsignedBigInteger('team_thread_key')->storedAs('IFNULL(`team_thread_id`, 0)')->after('conversation_key');
            $table->unique(['user_id', 'conversation_key', 'team_thread_key'], 'chat_appearances_user_chat_unique');
        });

        Schema::table('chat_appearances', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'conversation_key']);
        });

        if ($this->checksTargets()) {
            DB::statement('ALTER TABLE chat_appearances ADD CONSTRAINT '.self::SINGLE_CHAT_CHECK.' CHECK (conversation_id IS NULL OR team_thread_id IS NULL)');
        }
    }

    /**
     * Reverse the migrations.
     *
     * Internal chat colors cannot exist without the column: they are
     * removed (customer conversation and default rows are kept) so the old
     * unique index can be restored.
     */
    public function down(): void
    {
        if ($this->checksTargets()) {
            DB::statement('ALTER TABLE chat_appearances DROP CONSTRAINT '.self::SINGLE_CHAT_CHECK);
        }

        DB::table('chat_appearances')->whereNotNull('team_thread_id')->delete();

        Schema::table('chat_appearances', function (Blueprint $table) {
            $table->unique(['user_id', 'conversation_key']);
        });

        Schema::table('chat_appearances', function (Blueprint $table) {
            $table->dropUnique('chat_appearances_user_chat_unique');
            $table->dropColumn('team_thread_key');
            $table->dropConstrainedForeignId('team_thread_id');
        });
    }

    private function checksTargets(): bool
    {
        return Schema::getConnection()->getDriverName() === 'mariadb';
    }
};
