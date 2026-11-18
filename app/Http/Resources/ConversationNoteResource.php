<?php

namespace App\Http\Resources;

use App\Models\ConversationNote;
use App\Services\LinkPreviews\LinkPreviews;
use App\Support\ProtectedAccounts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ConversationNote
 */
class ConversationNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            // Where the card of its first link is read (null: no link).
            'link_preview_url' => LinkPreviews::firstUrl($this->body) === null
                ? null
                : route('conversations.notes.link-preview', ['conversation' => $this->conversation_id, 'note' => $this->id]),
            'author' => $this->whenLoaded('author', fn (): ?array => $this->author === null ? null : [
                'id' => app(ProtectedAccounts::class)->idForRequest($this->author, $request),
                'name' => app(ProtectedAccounts::class)->nameForRequest($this->author, $request),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'can' => ['update' => $request->user()?->can('update', $this->resource) ?? false],
        ];
    }
}
