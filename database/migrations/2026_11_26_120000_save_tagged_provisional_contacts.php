<?php

use App\Enums\AuditAction;
use App\Enums\ContactStatus;
use App\Models\Contact;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tagging a provisional contact (an unknown sender registered by an inbound
 * message) now saves it into the directory (SyncContactTags,
 * SyncConversationTags). Contacts an agent already tagged before that change
 * stayed provisional and hidden from Contactos: they are saved now, by the
 * person who tagged them first, at that moment.
 *
 * Only tags put by a person are considered (a "contact.tags_changed" audit
 * entry with a user); tags brought by the Whaticket history import are not
 * audited that way and do not save contacts. Data only; nothing to undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $morph = (new Contact)->getMorphClass();
        $audit = app(AuditLogger::class);

        Contact::query()
            ->whereNull('saved_at')
            ->where('status', '!=', ContactStatus::Archived->value)
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('contact_tag')
                ->whereColumn('contact_tag.contact_id', 'contacts.id'))
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('audit_logs')
                ->where('audit_logs.action', AuditAction::ContactTagsChanged->value)
                ->where('audit_logs.auditable_type', $morph)
                ->whereColumn('audit_logs.auditable_id', 'contacts.id')
                ->whereNotNull('audit_logs.user_id'))
            ->orderBy('id')
            ->chunkById(200, function ($contacts) use ($morph, $audit): void {
                foreach ($contacts as $contact) {
                    $first = DB::table('audit_logs')
                        ->where('action', AuditAction::ContactTagsChanged->value)
                        ->where('auditable_type', $morph)
                        ->where('auditable_id', $contact->id)
                        ->whereNotNull('user_id')
                        ->orderBy('id')
                        ->first(['user_id', 'created_at']);

                    $actor = $first === null ? null : User::query()->whereKey($first->user_id)->first();

                    $contact->forceFill([
                        'saved_at' => $first === null ? now() : $first->created_at,
                        'saved_by_user_id' => $actor?->id,
                    ])->save();

                    if ($actor !== null) {
                        $audit->record(AuditAction::ContactSaved, $contact, ['via' => 'backfill'], $actor);
                    }
                }
            });
    }

    public function down(): void
    {
        // Saved contacts stay saved.
    }
};
