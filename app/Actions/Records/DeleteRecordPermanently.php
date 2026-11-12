<?php

namespace App\Actions\Records;

use App\Enums\AuditAction;
use App\Models\ConversationCategory;
use App\Models\QuickReply;
use App\Models\Tag;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Erases for good a record that was already deleted logically: a tag, a
 * request category or a quick reply. Only those three: they carry no
 * history of their own, so nothing else is lost with them.
 *
 * - Tag: it is taken off every contact and conversation that still had it.
 * - Request category: conversations that had it are left without category
 *   (foreign key) and so are their report metrics.
 * - Quick reply: its department assignments go with it (foreign key).
 *
 * Records with history behind them (users, connections, departments, roles,
 * contacts, templates) are not erased by this action: see
 * docs/roles-y-permisos.md.
 *
 * Authorization is the caller's job (deletePermanently of each policy,
 * permanent-deletion.*). The audit entry is restricted to the root
 * authority.
 */
class DeleteRecordPermanently
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Tag|ConversationCategory|QuickReply $record, User $actor): void
    {
        DB::transaction(function () use ($record, $actor): void {
            /** @var Tag|ConversationCategory|QuickReply $locked */
            $locked = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());

            if (! $locked->isDeleted()) {
                throw ValidationException::withMessages([
                    'confirmation' => 'Solo se puede eliminar definitivamente un registro que ya está eliminado.',
                ]);
            }

            $details = ['type' => $locked->getMorphClass(), 'name' => $this->nameOf($locked)];

            if ($locked instanceof Tag) {
                $details['contacts'] = DB::table('contact_tag')->where('tag_id', $locked->id)->delete();
                $details['conversations'] = DB::table('conversation_tag')->where('tag_id', $locked->id)->delete();
            }

            if ($locked instanceof ConversationCategory) {
                $details['conversations'] = DB::table('conversations')->where('category_id', $locked->id)->count();
                // Report dimensions without a foreign key.
                foreach (['conversation_metrics', 'conversation_agent_metrics', 'response_cycles'] as $table) {
                    DB::table($table)->where('category_id', $locked->id)->update(['category_id' => null]);
                }
            }

            $this->audit->record(AuditAction::RecordPermanentlyDeleted, $locked, $details, $actor);

            $locked->newQuery()->whereKey($locked->getKey())->delete();
        });
    }

    private function nameOf(Tag|ConversationCategory|QuickReply $record): string
    {
        return $record instanceof QuickReply ? (string) $record->shortcut : (string) $record->name;
    }
}
