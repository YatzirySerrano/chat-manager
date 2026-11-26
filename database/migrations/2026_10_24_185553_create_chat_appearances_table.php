<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Personal chat colors. A row without conversation is the user's default
     * for every chat; a row with a conversation overrides it for that chat.
     * The stored generated conversation_key (0 for the default) lets one
     * unique index allow a single default and a single row per conversation,
     * since NULLs never collide in a unique index.
     */
    public function up(): void
    {
        Schema::create('chat_appearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->cascadeOnDelete();
            // MySQL refuses CASCADE on the base column of a STORED generated
            // column (error 1215); a VIRTUAL one is allowed and indexable.
            // MariaDB (production) keeps it STORED.
            $key = $table->unsignedBigInteger('conversation_key');
            // The Laravel driver name ("mariadb") can't be trusted: WAMP ships the
            // `mariadb` connection but may run a real MySQL server, which refuses
            // CASCADE on the base column of a STORED generated column (error 1215)
            // while allowing it on a VIRTUAL one. Detect the real product instead.
            $serverVersion = (string) Schema::getConnection()
                ->getPdo()
                ->getAttribute(\PDO::ATTR_SERVER_VERSION);
            $isMariaDb = str_contains(strtolower($serverVersion), 'mariadb');
            $isMariaDb
                ? $key->storedAs('IFNULL(`conversation_id`, 0)')
                : $key->virtualAs('IFNULL(`conversation_id`, 0)');
            $table->string('preset', 40)->nullable();
            $table->json('colors')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'conversation_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_appearances');
    }
};
