<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Reactions of participants to internal messages: one row per person,
     * message and emoji (from the allow list in config/team-chat.php). The
     * unique key makes reacting twice idempotent. The emoji is compared
     * byte by byte: case and accent insensitive collations treat many
     * different emoji as equal.
     */
    public function up(): void
    {
        Schema::create('team_message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_message_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $emoji = $table->string('emoji', 16);

            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $emoji->collation('utf8mb4_bin');
            }

            $table->timestamps();

            $table->unique(['team_message_id', 'user_id', 'emoji']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_message_reactions');
    }
};
