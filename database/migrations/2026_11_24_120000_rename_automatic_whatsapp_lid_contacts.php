<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contacts registered from a WhatsApp privacy id (LID) without a profile
 * name were named "WhatsApp lid:40674078527636". They take their WhatsApp
 * profile name when one is known, otherwise "Contacto de WhatsApp 7636"
 * (ConnectionChannel::fallbackContactName). Only that automatic name is
 * touched: a name somebody chose is kept. Data only; nothing to undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('contacts')
            ->where('name', 'like', 'WhatsApp lid:%')
            ->orderBy('id')
            ->select(['id', 'name', 'profile_name'])
            ->chunkById(500, function ($contacts): void {
                foreach ($contacts as $contact) {
                    $profile = is_string($contact->profile_name) ? trim($contact->profile_name) : '';
                    $digits = (string) preg_replace('/\D+/', '', (string) $contact->name);

                    DB::table('contacts')->where('id', $contact->id)->update([
                        'name' => $profile !== '' ? mb_substr($profile, 0, 160) : 'Contacto de WhatsApp '.mb_substr($digits, -4),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // The previous automatic names carried no information worth restoring.
    }
};
