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
     * A contact is a person; how a provider identifies that person (the
     * WhatsApp `wa_id`) is an identity of its own. The unique index keeps
     * webhook retries or parallel processing from duplicating an identity.
     * Every existing `wa_id` becomes a WhatsApp identity before the column
     * is dropped.
     */
    public function up(): void
    {
        Schema::create('contact_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->string('channel', 20);
            $table->string('provider', 40);
            $table->string('external_id', 64);
            $table->string('display_name')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_id']);
        });

        DB::table('contacts')
            ->select(['id', 'wa_id', 'profile_name', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunk(500, fn ($contacts) => DB::table('contact_identities')->insert($contacts->map(fn (object $contact): array => [
                'contact_id' => $contact->id,
                'channel' => 'whatsapp',
                'provider' => 'meta_cloud_api',
                'external_id' => $contact->wa_id,
                'display_name' => $contact->profile_name,
                'created_at' => $contact->created_at,
                'updated_at' => $contact->updated_at,
            ])->all()));

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['wa_id']);
            $table->dropColumn('wa_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Each contact gets back the id of its oldest WhatsApp identity. Contacts
     * created without one (added by hand) receive a unique placeholder because
     * the previous schema required the column.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('wa_id', 32)->nullable()->after('id');
        });

        $oldestIdentities = DB::table('contact_identities')
            ->selectRaw('contact_id, MIN(id) AS identity_id')
            ->where('channel', 'whatsapp')
            ->groupBy('contact_id');

        DB::table('contacts')
            ->leftJoinSub($oldestIdentities, 'oldest', 'oldest.contact_id', '=', 'contacts.id')
            ->leftJoin('contact_identities', 'contact_identities.id', '=', 'oldest.identity_id')
            ->select(['contacts.id', 'contact_identities.external_id'])
            ->orderBy('contacts.id')
            ->chunk(500, function ($contacts): void {
                foreach ($contacts as $contact) {
                    DB::table('contacts')->where('id', $contact->id)->update([
                        'wa_id' => $contact->external_id !== null ? mb_substr($contact->external_id, 0, 32) : "manual-{$contact->id}",
                    ]);
                }
            });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('wa_id', 32)->nullable(false)->change();
            $table->unique('wa_id');
        });

        Schema::dropIfExists('contact_identities');
    }
};
