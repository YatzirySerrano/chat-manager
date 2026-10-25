<?php

namespace App\Services\Personalization;

use App\Enums\ChatAppearancePreset;
use App\Models\ChatAppearance;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Color\Contrast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Personal chat colors of one user: the row of a conversation overrides the
 * user's default row, and without either the platform theme applies. They
 * only ever reach their owner's own view (and, read-only, an authorized
 * supervisor); they never change messages, other users or the web chat.
 *
 * @phpstan-import-type ChatColors from ChatAppearancePreset
 * @phpstan-import-type ChatColorSet from ChatAppearancePreset
 *
 * @phpstan-type AppearanceChoice array{id: int, preset: string|null, preset_label: string|null, colors: ChatColors|null, updated_at: string|null}
 * @phpstan-type ViewerAppearance array{source: 'conversation'|'default'|'system', preset: string|null, colors: ChatColors|null, default: AppearanceChoice|null, conversation: AppearanceChoice|null, presets: list<array{value: string, label: string, colors: ChatColors}>}
 */
class ChatAppearances
{
    /** Text over bubble pairs checked against WCAG AA. */
    private const TEXT_PAIRS = ['sent_text' => 'sent_bg', 'received_text' => 'received_bg'];

    public const ADJUSTED_NOTICE = 'Ajustamos el color del texto para que sea legible sobre la burbuja (contraste mínimo 4.5:1).';

    /**
     * What the viewer's chat of the conversation looks like, with both of the
     * viewer's choices so the editor can show and change either. One query.
     *
     * @return ViewerAppearance
     */
    public function forViewer(User $viewer, Conversation $conversation): array
    {
        $rows = ChatAppearance::query()
            ->where('user_id', $viewer->id)
            ->where(fn (Builder $query) => $query->whereNull('conversation_id')->orWhere('conversation_id', $conversation->id))
            ->get();

        $default = $rows->first(fn (ChatAppearance $row): bool => $row->conversation_id === null);
        $override = $rows->first(fn (ChatAppearance $row): bool => $row->conversation_id === $conversation->id);
        $effective = $override ?? $default;

        return [
            'source' => match (true) {
                $override !== null => 'conversation',
                $default !== null => 'default',
                default => 'system',
            },
            'preset' => $effective?->preset,
            'colors' => $effective === null ? null : $this->validColors($effective->colors),
            'default' => $this->present($default),
            'conversation' => $this->present($override),
            'presets' => ChatAppearancePreset::options(),
        ];
    }

    /**
     * Keeps every chosen color, except a text color unreadable on its bubble:
     * it becomes the most readable near-black or white.
     *
     * @param  ChatColors  $colors
     * @return array{colors: ChatColors, adjusted: bool}
     */
    public function normalize(array $colors): array
    {
        $adjusted = false;

        foreach (['light', 'dark'] as $mode) {
            $set = array_map(fn (string $hex): string => strtoupper($hex), $colors[$mode]);

            foreach (self::TEXT_PAIRS as $text => $background) {
                if (! Contrast::isReadable($set[$text], $set[$background])) {
                    $set[$text] = Contrast::readableTextOn($set[$background]);
                    $adjusted = true;
                }
            }

            $colors[$mode] = $set;
        }

        return ['colors' => $colors, 'adjusted' => $adjusted];
    }

    /**
     * Stores the user's colors for the conversation, or as their default for
     * every chat when no conversation is given. The preset is kept only while
     * the colors are still exactly the preset's.
     *
     * @param  ChatColors  $colors
     * @return array{appearance: ChatAppearance, adjusted: bool}
     */
    public function save(User $owner, ?Conversation $conversation, ?ChatAppearancePreset $preset, array $colors): array
    {
        ['colors' => $colors, 'adjusted' => $adjusted] = $this->normalize($colors);

        $appearance = $this->ownedBy($owner, $conversation)->first() ?? (new ChatAppearance)->forceFill(['user_id' => $owner->id, 'conversation_id' => $conversation?->id]);
        $attributes = [
            'preset' => $preset !== null && $preset->colors() == $colors ? $preset->value : null,
            'colors' => $colors,
        ];

        try {
            $appearance->fill($attributes)->save();
        } catch (UniqueConstraintViolationException) {
            // A concurrent request (double submit) created the row between
            // the lookup and the insert: the last write wins as an update.
            $appearance = $this->ownedBy($owner, $conversation)->firstOrFail();
            $appearance->fill($attributes)->save();
        }

        return ['appearance' => $appearance, 'adjusted' => $adjusted];
    }

    /**
     * Back to the next level: the default row for a conversation, the
     * platform theme for the default.
     */
    public function clear(User $owner, ?Conversation $conversation): void
    {
        $this->ownedBy($owner, $conversation)->delete();
    }

    /**
     * The owner's row for the conversation, or their default row.
     *
     * @return Builder<ChatAppearance>
     */
    private function ownedBy(User $owner, ?Conversation $conversation): Builder
    {
        return ChatAppearance::query()
            ->where('user_id', $owner->id)
            ->when(
                $conversation === null,
                fn (Builder $query) => $query->whereNull('conversation_id'),
                fn (Builder $query) => $query->where('conversation_id', $conversation?->id),
            );
    }

    /**
     * @return AppearanceChoice|null
     */
    public function present(?ChatAppearance $appearance): ?array
    {
        if ($appearance === null) {
            return null;
        }

        $preset = $appearance->preset === null ? null : ChatAppearancePreset::tryFrom($appearance->preset);

        return [
            'id' => $appearance->id,
            'preset' => $preset?->value,
            'preset_label' => $preset?->label(),
            'colors' => $this->validColors($appearance->colors),
            'updated_at' => $appearance->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Stored colors when complete and well formed; anything else is ignored
     * so a damaged row falls back to the theme instead of breaking the chat.
     *
     * @param  array<mixed>|null  $colors
     * @return ChatColors|null
     */
    private function validColors(?array $colors): ?array
    {
        $light = $this->validSet($colors['light'] ?? null);
        $dark = $this->validSet($colors['dark'] ?? null);

        return $light === null || $dark === null ? null : ['light' => $light, 'dark' => $dark];
    }

    /**
     * @return ChatColorSet|null
     */
    private function validSet(mixed $set): ?array
    {
        if (! is_array($set)) {
            return null;
        }

        foreach (ChatAppearancePreset::COLOR_KEYS as $key) {
            if (! Contrast::isHex($set[$key] ?? null)) {
                return null;
            }
        }

        return [
            'sent_bg' => strtoupper((string) $set['sent_bg']),
            'sent_text' => strtoupper((string) $set['sent_text']),
            'received_bg' => strtoupper((string) $set['received_bg']),
            'received_text' => strtoupper((string) $set['received_text']),
            'chat_bg' => strtoupper((string) $set['chat_bg']),
        ];
    }
}
