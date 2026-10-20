<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Custom field that keeps a raw phone number that cannot be rebuilt from
     * its normalized form or the WhatsApp id (for example, one that could not
     * be normalized), so no data stored before this migration is lost.
     */
    private const ORIGINAL_PHONE_FIELD = 'Teléfono original';

    /**
     * Run the migrations.
     *
     * A contact becomes a person managed by the company: a chosen display
     * name, a normalized E.164 phone (not unique: people share numbers), a
     * lifecycle status, its source and free custom fields. The free-form
     * `display_name` and raw `phone_number` are copied before being dropped,
     * and `last_inbound_at` becomes the broader `last_interaction_at`.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('name', 160)->nullable()->after('id');
            $table->string('phone_e164', 20)->nullable()->after('name');
            $table->string('status', 20)->default('active')->after('email');
            $table->timestamp('archived_at')->nullable()->after('status');
            $table->string('source', 20)->default('manual')->after('archived_at');
            $table->foreignId('created_by_user_id')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->json('custom_fields')->nullable()->after('created_by_user_id');
            $table->timestamp('first_interaction_at')->nullable()->after('custom_fields');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->renameColumn('last_inbound_at', 'last_interaction_at');
        });

        $firstConversations = DB::table('conversations')
            ->selectRaw('contact_id, MIN(created_at) AS first_at')
            ->groupBy('contact_id');

        DB::table('contacts')
            ->leftJoinSub($firstConversations, 'first_conversations', 'first_conversations.contact_id', '=', 'contacts.id')
            ->select(['contacts.id', 'contacts.wa_id', 'contacts.phone_number', 'contacts.profile_name', 'contacts.display_name', 'contacts.last_interaction_at', 'first_conversations.first_at'])
            ->orderBy('contacts.id')
            ->chunk(500, function ($contacts): void {
                foreach ($contacts as $contact) {
                    $rawPhone = trim((string) $contact->phone_number);
                    $phone = $this->normalizePhone($rawPhone)
                        ?? ($contact->wa_id !== null ? $this->normalizePhone('+'.$contact->wa_id) : null);
                    $rebuildable = $rawPhone === '' || $rawPhone === $phone || $rawPhone === '+'.$contact->wa_id;

                    DB::table('contacts')->where('id', $contact->id)->update([
                        'name' => mb_substr($this->firstFilled($contact->display_name, $contact->profile_name, $rawPhone, $contact->wa_id) ?? "Contacto {$contact->id}", 0, 160),
                        'phone_e164' => $phone,
                        'custom_fields' => ! $rebuildable
                            ? json_encode([self::ORIGINAL_PHONE_FIELD => $rawPhone], JSON_UNESCAPED_UNICODE)
                            : null,
                        'source' => 'whatsapp',
                        'first_interaction_at' => $contact->first_at ?? $contact->last_interaction_at,
                    ]);
                }
            });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('name', 160)->nullable(false)->change();
            $table->dropIndex(['phone_number']);
            $table->dropColumn(['phone_number', 'display_name']);
            $table->index('phone_e164');
            $table->index('email');
            $table->index(['status', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * The raw phone is rebuilt from the original value kept in the custom
     * fields, the normalized phone or the WhatsApp id. The display name is
     * restored when it differs from the values it was backfilled from.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('phone_number', 32)->nullable()->after('wa_id');
            $table->string('display_name')->nullable()->after('profile_name');
        });

        $hasWaId = Schema::hasColumn('contacts', 'wa_id');

        DB::table('contacts')->orderBy('id')->chunk(500, function ($contacts) use ($hasWaId): void {
            foreach ($contacts as $contact) {
                $customFields = json_decode((string) $contact->custom_fields, true);
                $waId = $hasWaId ? $contact->wa_id : null;
                $fromWaId = $waId !== null && $this->normalizePhone('+'.$waId) === $contact->phone_e164 ? '+'.$waId : null;
                $phone = (is_array($customFields) ? ($customFields[self::ORIGINAL_PHONE_FIELD] ?? null) : null)
                    ?? $fromWaId
                    ?? $contact->phone_e164
                    ?? '';

                $wasBackfilled = in_array($contact->name, array_filter([$contact->profile_name, $phone, $waId, "Contacto {$contact->id}"]), true);

                DB::table('contacts')->where('id', $contact->id)->update([
                    'phone_number' => mb_substr((string) $phone, 0, 32),
                    'display_name' => $wasBackfilled ? null : $contact->name,
                ]);
            }
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('phone_number', 32)->nullable(false)->change();
            $table->index('phone_number');
            $table->dropIndex(['phone_e164']);
            $table->dropIndex(['email']);
            $table->dropIndex(['status', 'name']);
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn(['name', 'phone_e164', 'status', 'archived_at', 'source', 'custom_fields', 'first_interaction_at']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->renameColumn('last_interaction_at', 'last_inbound_at');
        });
    }

    private function firstFilled(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Snapshot of App\Support\PhoneNumber::normalize() for Mexico at the time
     * of this migration, kept inline so the migration never changes behavior.
     */
    private function normalizePhone(string $number): ?string
    {
        $compact = preg_replace('/[\s().\-\x{00A0}]+/u', '', $number) ?? '';

        if (preg_match('/^(\+|00)?(\d+)$/', $compact, $matches) !== 1) {
            return null;
        }

        $digits = $matches[1] === '' ? (strlen($matches[2]) === 10 ? '52'.$matches[2] : '') : $matches[2];

        if (strlen($digits) === 13 && str_starts_with($digits, '521')) {
            $digits = '52'.substr($digits, 3);
        }

        if (strlen($digits) < 8 || strlen($digits) > 15 || str_starts_with($digits, '0')) {
            return null;
        }

        return '+'.$digits;
    }
};
