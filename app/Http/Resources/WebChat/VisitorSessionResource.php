<?php

namespace App\Http\Resources\WebChat;

use App\Models\Conversation;
use App\Models\WebChatSession;
use App\Models\WebChatWidget;
use App\Services\WebChat\VisitorRealtimeConfig;
use App\Services\WebChat\WebChatAvailability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The visitor's session as the widget needs it: whether it is identified,
 * the visitor's own name, when it expires, the state of their latest
 * conversation (with the attending agent's name only), the team's
 * availability, the real-time channel and the polling cadence. Never the
 * token, the contact id, the IP or anything about other visitors.
 * Expects 'connection.webChatWidget' loaded.
 *
 * @mixin WebChatSession
 */
class VisitorSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WebChatWidget $widget */
        $widget = $this->connection->webChatWidget;
        $widget->setRelation('connection', $this->connection);

        $conversation = $this->contact_id === null ? null : Conversation::query()
            ->with('assignee:id,name')
            ->where('connection_id', $this->connection_id)
            ->where('contact_id', $this->contact_id)
            ->orderByDesc('id')
            ->first(['id', 'status', 'assigned_user_id']);

        $profile = is_array($this->profile) ? $this->profile : [];

        return [
            'id' => $this->public_id,
            'identified' => $this->identified,
            'profile' => ['name' => is_string($profile['name'] ?? null) ? $profile['name'] : null],
            'expires_at' => $this->expires_at->toIso8601String(),
            'conversation' => [
                'status' => $conversation?->status->value ?? 'none',
                'agent_name' => $conversation?->assignee?->name,
            ],
            'availability' => app(WebChatAvailability::class)->for($widget),
            'realtime' => app(VisitorRealtimeConfig::class)->for($this->resource),
            'poll' => [
                'visible_seconds' => (int) config('webchat.poll.visible_seconds', 4),
                'hidden_seconds' => (int) config('webchat.poll.hidden_seconds', 20),
            ],
        ];
    }
}
