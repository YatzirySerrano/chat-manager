<?php

namespace App\Enums;

/**
 * The company whose API answers for the assistant of «Inteligencia
 * artificial». Both need an API key of their developer platform: a
 * claude.ai or ChatGPT Plus subscription cannot be used by other programs.
 */
enum AiVendor: string
{
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';

    /** Model used when the administrator chose none. */
    public function defaultModel(): string
    {
        return match ($this) {
            self::Anthropic => 'claude-opus-5-5',
            self::OpenAi => 'gpt-5-mini',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Claude (Anthropic)',
            self::OpenAi => 'ChatGPT (OpenAI)',
        };
    }

    /** Where the administrator creates the API key. */
    public function consoleUrl(): string
    {
        return match ($this) {
            self::Anthropic => 'https://console.anthropic.com/settings/keys',
            self::OpenAi => 'https://platform.openai.com/api-keys',
        };
    }

    /**
     * The models offered in a closed list (Claude); OpenAI takes free text.
     *
     * @return list<array{value: string, label: string}>
     */
    public function models(): array
    {
        return match ($this) {
            self::Anthropic => [
                ['value' => 'claude-opus-5-5', 'label' => 'Claude Opus 5.5 (recomendado)'],
                ['value' => 'claude-sonnet-5-5', 'label' => 'Claude Sonnet 5.5'],
                ['value' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5 (más económico)'],
            ],
            self::OpenAi => [],
        };
    }

    /**
     * @return list<array{value: string, label: string, default_model: string, models: list<array{value: string, label: string}>, console_url: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $vendor): array => [
            'value' => $vendor->value,
            'label' => $vendor->label(),
            'default_model' => $vendor->defaultModel(),
            'models' => $vendor->models(),
            'console_url' => $vendor->consoleUrl(),
        ], self::cases());
    }
}
