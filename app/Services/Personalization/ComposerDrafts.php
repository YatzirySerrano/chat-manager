<?php

namespace App\Services\Personalization;

use App\Models\ComposerDraft;
use App\Models\Conversation;
use App\Models\TeamChat\TeamThread;
use App\Models\User;

/**
 * The unsent text of each user's composers, kept on the server so it
 * follows them between devices: a reply started on the phone is there when
 * they open the same chat on the computer. One draft per user, chat and
 * mode (a customer conversation has «reply» and «note», an internal thread
 * has «message»). Saving an empty text removes the draft, and sending the
 * message removes it too (the send endpoints call forget()). Only the
 * owner ever reads their drafts.
 */
class ComposerDrafts
{
    /** Longer than any message or note the composers accept. */
    public const MAX_LENGTH = 10000;

    public const MODE_REPLY = 'reply';

    public const MODE_NOTE = 'note';

    public const MODE_MESSAGE = 'message';

    /**
     * The modes a chat's composer has.
     *
     * @return list<string>
     */
    public function modes(Conversation|TeamThread $chat): array
    {
        return $chat instanceof Conversation
            ? [self::MODE_REPLY, self::MODE_NOTE]
            : [self::MODE_MESSAGE];
    }

    /**
     * The viewer's drafts of a chat, by mode (modes without one are left out).
     *
     * @return array<string, array{body: string, updated_at: string|null}>
     */
    public function forViewer(User $viewer, Conversation|TeamThread $chat): array
    {
        return ComposerDraft::query()
            ->where('user_id', $viewer->id)
            ->where($this->column($chat), $chat->getKey())
            ->get(['mode', 'body', 'updated_at'])
            ->mapWithKeys(fn (ComposerDraft $draft): array => [
                $draft->mode => ['body' => $draft->body, 'updated_at' => $draft->updated_at?->toIso8601String()],
            ])
            ->all();
    }

    /**
     * Keeps the text of one mode; an empty (or blank) text removes it. One
     * statement, so two devices saving at once never collide.
     */
    public function save(User $viewer, Conversation|TeamThread $chat, string $mode, string $body): void
    {
        if (trim($body) === '') {
            $this->forget($viewer, $chat, $mode);

            return;
        }

        $now = now();

        ComposerDraft::query()->upsert(
            [[
                'user_id' => $viewer->id,
                'conversation_id' => $chat instanceof Conversation ? $chat->getKey() : null,
                'team_thread_id' => $chat instanceof TeamThread ? $chat->getKey() : null,
                'mode' => $mode,
                'body' => mb_substr($body, 0, self::MAX_LENGTH),
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['user_id', $this->column($chat), 'mode'],
            ['body', 'updated_at'],
        );
    }

    /**
     * Removes the draft of one mode (the message was sent or the text erased).
     */
    public function forget(User $viewer, Conversation|TeamThread $chat, string $mode): void
    {
        ComposerDraft::query()
            ->where('user_id', $viewer->id)
            ->where($this->column($chat), $chat->getKey())
            ->where('mode', $mode)
            ->delete();
    }

    private function column(Conversation|TeamThread $chat): string
    {
        return $chat instanceof Conversation ? 'conversation_id' : 'team_thread_id';
    }
}
