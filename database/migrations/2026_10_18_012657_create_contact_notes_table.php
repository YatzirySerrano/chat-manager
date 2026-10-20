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
     * Private notes become an append-only history with an author instead of a
     * single editable text. Each existing note is moved into the history
     * (without author, dated with the contact's last update) before the old
     * column is dropped.
     */
    public function up(): void
    {
        Schema::create('contact_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['contact_id', 'created_at']);
        });

        DB::table('contacts')
            ->whereNotNull('notes')
            ->where('notes', '!=', '')
            ->select(['id', 'notes', 'updated_at'])
            ->orderBy('id')
            ->chunk(500, fn ($contacts) => DB::table('contact_notes')->insert($contacts->map(fn (object $contact): array => [
                'contact_id' => $contact->id,
                'author_user_id' => null,
                'body' => $contact->notes,
                'created_at' => $contact->updated_at,
                'updated_at' => $contact->updated_at,
            ])->all()));

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }

    /**
     * Reverse the migrations.
     *
     * The notes of each contact are joined back, oldest first, into the
     * single text column.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('email');
        });

        DB::table('contact_notes')
            ->orderBy('contact_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['contact_id', 'body'])
            ->groupBy('contact_id')
            ->each(fn ($notes, $contactId) => DB::table('contacts')->where('id', $contactId)->update([
                'notes' => $notes->pluck('body')->implode("\n\n"),
            ]));

        Schema::dropIfExists('contact_notes');
    }
};
