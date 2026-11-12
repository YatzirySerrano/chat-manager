<?php

namespace App\Http\Controllers\Conversations;

use App\Enums\ConversationReadiness;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Conversations\ConversationTimeline;
use App\Services\Conversations\QueueRules;
use App\Services\Personalization\ChatAppearances;
use App\Support\Features;
use App\Support\ProtectedAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * The preview of a conversation from the inbox list (long press on touch
 * screens, right click or «Vista previa» on computers): its latest messages
 * without opening the chat.
 *
 * It only reads. It is a separate route from everything that changes the
 * reading state (conversations.read, notifications read) and never calls
 * them: the viewer's read marker, unread counters, notifications, the
 * conversation's state, assignment, position in the list, metrics and the
 * provider (no read receipt or typing indicator) stay exactly as they were.
 * The conversation is authorized like the chat itself, so a person who
 * cannot open it cannot preview it either (404, as if it did not exist).
 *
 * Besides the messages it tells what the request is, read from the same
 * sources the inbox uses: when it was received, its department and
 * category, its status, whether it is ready, a count of its files by type
 * and whether it is available for attention (QueueRules::acceptance(), the
 * `acceptance` contract of the list). All of it is computed with plain
 * reads; nothing is stored, locked or signalled.
 *
 * It is shown with the viewer's own effective chat colors, the same ones
 * the chat shows them (never the assigned agent's), only read: no
 * appearance row is created or changed.
 */
class ConversationPreviewController extends ConversationOperationController
{
    public function show(Request $request, Conversation $conversation, ConversationTimeline $timeline, ChatAppearances $appearances, QueueRules $queueRules): JsonResponse
    {
        $this->ensureVisible($conversation);

        /** @var User $viewer */
        $viewer = $request->user();

        return response()->json([
            ...$timeline->preview($conversation, $request),
            'conversation' => $this->describe($conversation, $viewer, $queueRules),
            // Only what the bubbles need, not the editor's choices and presets.
            'appearance' => Arr::only($appearances->forViewer($viewer, $conversation), ['source', 'colors']),
        ]);
    }

    /**
     * What the request is, for the header of the preview.
     *
     * @return array<string, mixed>
     */
    private function describe(Conversation $conversation, User $viewer, QueueRules $queueRules): array
    {
        $conversation->loadMissing(['department:id,name,color,is_active', 'category:id,name,color,is_active', 'assignee:id,name']);

        // When the customer first wrote (the provider's time), else when the conversation was created.
        $received = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Inbound)
            ->orderBy('id')
            ->first(['id', 'sent_at', 'created_at']);

        $files = MessageAttachment::query()
            ->join('messages', 'messages.id', '=', 'message_attachments.message_id')
            ->where('messages.conversation_id', $conversation->id)
            ->toBase()
            ->selectRaw('messages.type as type, COUNT(*) as total')
            ->groupBy('messages.type')
            ->pluck('total', 'type');

        // Phase one (FEATURE_PHASE_TWO off): every request is «Lista para atención».
        $readiness = Features::phaseTwo() ? ($conversation->readiness ?? ConversationReadiness::Ready) : ConversationReadiness::Ready;

        return [
            'id' => $conversation->id,
            'status' => $conversation->status->value,
            'status_label' => $conversation->status->label(),
            'stage' => $this->stage($conversation),
            'received_at' => ($received === null ? $conversation->created_at : ($received->sent_at ?? $received->created_at))?->toIso8601String(),
            'department' => $conversation->department === null ? null : [
                'id' => $conversation->department->id,
                'name' => $conversation->department->name,
                'color' => $conversation->department->color->value,
                'is_active' => $conversation->department->is_active,
            ],
            'category' => $conversation->category === null ? null : [
                'id' => $conversation->category->id,
                'name' => $conversation->category->name,
                'color' => $conversation->category->color->value,
                'is_active' => $conversation->category->is_active,
            ],
            'assignee' => app(ProtectedAccounts::class)->nameFor($conversation->assignee, $viewer),
            'readiness' => ['value' => $readiness->value, 'label' => $readiness->label()],
            'attachments' => [
                'total' => (int) $files->sum(),
                'by_type' => $files->map(fn (mixed $total): int => (int) $total)->all(),
            ],
            'acceptance' => $queueRules->acceptance($conversation, $viewer, $viewer->can('accept', $conversation)),
        ];
    }

    /**
     * Where the request stands, as people name it: «Nueva» (waiting and
     * nobody of the team answered or reopened it yet), «Pendiente» (waiting
     * again in a queue), «En proceso» (an agent attends it) or «Finalizada».
     *
     * @return array{value: 'new'|'pending'|'in_progress'|'finished', label: string}
     */
    private function stage(Conversation $conversation): array
    {
        return match (true) {
            $conversation->status === ConversationStatus::Closed => ['value' => 'finished', 'label' => 'Finalizada'],
            $conversation->status !== ConversationStatus::Pending => ['value' => 'in_progress', 'label' => 'En proceso'],
            $conversation->first_response_at === null && $conversation->reopen_count === 0 => ['value' => 'new', 'label' => 'Nueva'],
            default => ['value' => 'pending', 'label' => 'Pendiente'],
        };
    }
}
