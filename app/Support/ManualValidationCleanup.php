<?php

namespace App\Support;

use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\QuickReply;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Selective removal of the manual validation scenario. Only records carrying
 * the markers of App\Support\ManualValidation are removed. When a test by hand
 * tied real data to the scenario in a way that removing it would change that
 * data (a real conversation assigned to a demo agent, a real user holding the
 * demo role...), nothing is removed and the blockers are reported instead.
 * The audit trail is kept: it records what was done during the validation.
 */
final class ManualValidationCleanup
{
    /**
     * What would be removed, links of real records that would be detached,
     * and blockers that prevent the removal.
     *
     * @return array{remove: array<string, list<int>>, detach: list<string>, blockers: list<string>}
     */
    public function plan(): array
    {
        $connectionIds = $this->ids(Connection::query()->whereIn('phone_number_id', ManualValidation::LINES)->pluck('id')->all());
        $contactIds = $this->ids(Contact::query()
            ->where(fn ($query) => $query
                ->where('phone_e164', 'like', ManualValidation::demoPhonePattern())
                ->orWhereIn('id', DB::table('contact_identities')->where('channel', 'whatsapp')->where('external_id', 'like', '52155000001__')->select('contact_id')))
            ->pluck('id')->all());
        $userIds = $this->ids(User::query()->where('email', 'like', '%@'.ManualValidation::EMAIL_DOMAIN)->pluck('id')->all());
        $departmentIds = ManualValidation::departmentIds();
        $roleIds = $this->ids(Role::query()->where('label', ManualValidation::ROLE_LABEL)->pluck('id')->all());
        $tagIds = $this->ids(Tag::query()->whereIn('slug', ManualValidation::TAGS)->pluck('id')->all());
        $quickReplyIds = $this->ids(QuickReply::query()->whereIn('shortcut', ManualValidation::QUICK_REPLIES)->where('title', 'like', ManualValidation::PREFIX.'%')->pluck('id')->all());
        $conversationIds = $this->ids(Conversation::query()->whereIn('connection_id', $connectionIds)->pluck('id')->all());

        $foreignConversations = $this->ids(Conversation::query()
            ->whereNotIn('id', $conversationIds)
            ->where(fn ($query) => $query
                ->whereIn('contact_id', $contactIds)
                ->orWhereIn('assigned_user_id', $userIds)
                ->orWhereIn('department_id', $departmentIds))
            ->pluck('id')->all());
        $realUsersWithDemoRole = $this->ids(DB::table('model_has_roles')
            ->whereIn('role_id', $roleIds)
            ->where('model_type', 'user')
            ->whereNotIn('model_id', $userIds)
            ->pluck('model_id')->all());

        $blockers = [];

        if ($foreignConversations !== []) {
            $blockers[] = 'Conversaciones reales ligadas a contactos, agentes o departamentos de la demo: #'.implode(', #', $foreignConversations).'. Reasígnalas o transfiérelas primero.';
        }

        if ($realUsersWithDemoRole !== []) {
            $blockers[] = 'Usuarios reales con el rol «'.ManualValidation::ROLE_LABEL.'» (ids '.implode(', ', $realUsersWithDemoRole).'). Cámbiales el rol primero.';
        }

        $detach = array_filter([
            $this->describe('etiquetas demo asignadas a contactos reales', DB::table('contact_tag')->whereIn('tag_id', $tagIds)->whereNotIn('contact_id', $contactIds)->count()),
            $this->describe('respuestas rápidas reales ligadas a departamentos demo', DB::table('department_quick_reply')->whereIn('department_id', $departmentIds)->whereNotIn('quick_reply_id', $quickReplyIds)->count()),
            $this->describe('membresías de usuarios reales en departamentos demo', DB::table('department_user')->whereIn('department_id', $departmentIds)->whereNotIn('user_id', $userIds)->count()),
            $this->describe('departamentos reales autorizados en la conexión demo', DB::table('connection_department')->whereIn('connection_id', $connectionIds)->whereNotIn('department_id', $departmentIds)->count()),
        ]);

        return [
            'remove' => [
                'conversations' => $conversationIds,
                'contacts' => $contactIds,
                'connections' => $connectionIds,
                'quick_replies' => $quickReplyIds,
                'tags' => $tagIds,
                'users' => $userIds,
                'departments' => $departmentIds,
                'roles' => $roleIds,
            ],
            'detach' => array_values($detach),
            'blockers' => $blockers,
        ];
    }

    /**
     * Remove the scenario in one transaction; nothing is removed while there are blockers.
     *
     * @return array{remove: array<string, list<int>>, detach: list<string>, blockers: list<string>}
     */
    public function execute(): array
    {
        $plan = DB::transaction(function (): array {
            $plan = $this->plan();

            if ($plan['blockers'] !== []) {
                return $plan;
            }

            ['conversations' => $conversations, 'contacts' => $contacts, 'users' => $users] = $plan['remove'];
            $messages = $this->ids(DB::table('messages')->whereIn('conversation_id', $conversations)->pluck('id')->all());

            DB::table('message_attachments')->whereIn('message_id', $messages)->delete();
            DB::table('messages')->whereIn('id', $messages)->delete();
            DB::table('conversation_events')->whereIn('conversation_id', $conversations)->delete();
            DB::table('conversation_notes')->whereIn('conversation_id', $conversations)->delete();
            DB::table('conversation_tag')->whereIn('conversation_id', $conversations)->delete();
            DB::table('conversations')->whereIn('id', $conversations)->delete();

            DB::table('contact_attributions')->whereIn('contact_id', $contacts)->delete();
            DB::table('contact_identities')->whereIn('contact_id', $contacts)->delete();
            DB::table('contact_notes')->whereIn('contact_id', $contacts)->delete();
            DB::table('contact_review_cases')->whereIn('contact_id', $contacts)->delete();
            DB::table('contacts')->whereIn('id', $contacts)->delete();

            DB::table('connections')->whereIn('id', $plan['remove']['connections'])->delete();
            DB::table('quick_replies')->whereIn('id', $plan['remove']['quick_replies'])->delete();
            DB::table('contact_tag')->whereIn('tag_id', $plan['remove']['tags'])->delete();
            DB::table('conversation_tag')->whereIn('tag_id', $plan['remove']['tags'])->delete();
            DB::table('tags')->whereIn('id', $plan['remove']['tags'])->delete();

            DB::table('model_has_roles')->where('model_type', 'user')->whereIn('model_id', $users)->delete();
            DB::table('model_has_permissions')->where('model_type', 'user')->whereIn('model_id', $users)->delete();
            DB::table('sessions')->whereIn('user_id', $users)->delete();
            DB::table('users')->whereIn('id', $users)->delete();

            DB::table('departments')->whereIn('id', $plan['remove']['departments'])->delete();
            DB::table('roles')->whereIn('id', $plan['remove']['roles'])->delete();

            return $plan;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $plan;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<int>
     */
    private function ids(array $values): array
    {
        return array_values(array_map(fn (mixed $id): int => (int) $id, $values));
    }

    private function describe(string $what, int $count): ?string
    {
        return $count > 0 ? "{$count} {$what}" : null;
    }
}
