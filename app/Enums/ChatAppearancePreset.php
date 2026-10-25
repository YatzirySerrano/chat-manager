<?php

namespace App\Enums;

/**
 * Ready-made personal chat palettes, each with a light and a dark set whose
 * text reaches WCAG AA (4.5:1) on its bubble. "Predeterminado" is not a
 * case: it means no personal colors at all (the platform theme).
 *
 * @phpstan-type ChatColorSet array{sent_bg: string, sent_text: string, received_bg: string, received_text: string, chat_bg: string}
 * @phpstan-type ChatColors array{light: ChatColorSet, dark: ChatColorSet}
 */
enum ChatAppearancePreset: string
{
    case Ocean = 'ocean';
    case Forest = 'forest';
    case Graphite = 'graphite';
    case Sand = 'sand';
    case HighContrast = 'high_contrast';

    /** Keys of every color set, in the order the editor shows them. */
    public const COLOR_KEYS = ['sent_bg', 'sent_text', 'received_bg', 'received_text', 'chat_bg'];

    public function label(): string
    {
        return match ($this) {
            self::Ocean => 'Océano',
            self::Forest => 'Bosque',
            self::Graphite => 'Grafito',
            self::Sand => 'Arena',
            self::HighContrast => 'Alto contraste',
        };
    }

    /**
     * @return ChatColors
     */
    public function colors(): array
    {
        return match ($this) {
            self::Ocean => [
                'light' => ['sent_bg' => '#CDE7FB', 'sent_text' => '#0B2545', 'received_bg' => '#FFFFFF', 'received_text' => '#102A43', 'chat_bg' => '#EEF5FB'],
                'dark' => ['sent_bg' => '#0E4A7A', 'sent_text' => '#FFFFFF', 'received_bg' => '#16283A', 'received_text' => '#E6F0FA', 'chat_bg' => '#0B1622'],
            ],
            self::Forest => [
                'light' => ['sent_bg' => '#D3EFD9', 'sent_text' => '#0F2E1A', 'received_bg' => '#FFFFFF', 'received_text' => '#1B2B20', 'chat_bg' => '#EEF6F0'],
                'dark' => ['sent_bg' => '#1E5631', 'sent_text' => '#FFFFFF', 'received_bg' => '#1A2A1F', 'received_text' => '#E3F2E7', 'chat_bg' => '#0E1A12'],
            ],
            self::Graphite => [
                'light' => ['sent_bg' => '#E4E4E7', 'sent_text' => '#18181B', 'received_bg' => '#FFFFFF', 'received_text' => '#18181B', 'chat_bg' => '#F4F4F5'],
                'dark' => ['sent_bg' => '#3F3F46', 'sent_text' => '#FAFAFA', 'received_bg' => '#27272A', 'received_text' => '#F4F4F5', 'chat_bg' => '#18181B'],
            ],
            self::Sand => [
                'light' => ['sent_bg' => '#F6E7C8', 'sent_text' => '#3B2A12', 'received_bg' => '#FFFDF7', 'received_text' => '#2E2414', 'chat_bg' => '#FBF5EA'],
                'dark' => ['sent_bg' => '#6B4F1D', 'sent_text' => '#FFF8EB', 'received_bg' => '#2E2618', 'received_text' => '#F5EBDD', 'chat_bg' => '#1C170F'],
            ],
            self::HighContrast => [
                'light' => ['sent_bg' => '#000000', 'sent_text' => '#FFFFFF', 'received_bg' => '#FFFFFF', 'received_text' => '#000000', 'chat_bg' => '#FFFFFF'],
                'dark' => ['sent_bg' => '#FFFFFF', 'sent_text' => '#000000', 'received_bg' => '#000000', 'received_text' => '#FFFFFF', 'chat_bg' => '#000000'],
            ],
        };
    }

    /**
     * @return list<array{value: string, label: string, colors: ChatColors}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $preset): array => ['value' => $preset->value, 'label' => $preset->label(), 'colors' => $preset->colors()],
            self::cases(),
        );
    }
}
