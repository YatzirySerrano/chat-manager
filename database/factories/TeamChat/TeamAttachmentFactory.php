<?php

namespace Database\Factories\TeamChat;

use App\Enums\TeamChat\TeamAttachmentKind;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TeamAttachment>
 */
class TeamAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_message_id' => TeamMessage::factory(),
            'disk' => (string) config('team-chat.attachments.disk'),
            'path' => 'team-chat/'.now()->format('Y/m').'/'.Str::uuid(),
            'original_name' => 'reporte.pdf',
            'mime_type' => 'application/pdf',
            'kind' => TeamAttachmentKind::Document,
            'size_bytes' => 1024,
            'sha256' => hash('sha256', Str::random()),
        ];
    }
}
