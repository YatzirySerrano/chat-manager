<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The connections a contact has interacted with. The integration keeps it
     * up to date; existing conversations seed it so no relation is lost.
     */
    public function up(): void
    {
        Schema::create('connection_contact', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_interaction_at')->nullable();
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'contact_id']);
            $table->index(['contact_id', 'connection_id']);
        });

        $now = now();

        DB::table('conversations')
            ->selectRaw('connection_id, contact_id, MIN(created_at) AS first_interaction_at, MAX(COALESCE(last_message_at, created_at)) AS last_interaction_at')
            ->groupBy('connection_id', 'contact_id')
            ->orderBy('connection_id')
            ->orderBy('contact_id')
            ->get()
            ->chunk(500)
            ->each(fn ($rows) => DB::table('connection_contact')->insert($rows->map(fn (object $row): array => [
                'connection_id' => $row->connection_id,
                'contact_id' => $row->contact_id,
                'first_interaction_at' => $row->first_interaction_at,
                'last_interaction_at' => $row->last_interaction_at,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()));
    }

    /**
     * Reverse the migrations.
     *
     * The relation is derived from conversations, which remain untouched.
     */
    public function down(): void
    {
        Schema::dropIfExists('connection_contact');
    }
};
